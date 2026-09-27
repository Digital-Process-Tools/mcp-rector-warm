<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm;

use Rector\Bootstrap\RectorConfigsResolver;
use Rector\DependencyInjection\RectorContainerFactory;

/**
 * Holds a warm Rector container + Application across multiple analyse calls.
 * Boot happens lazily on first call; subsequent calls reuse the live container.
 */
final class RectorRunner implements RunnerInterface
{
    private ?object $application = null;
    private ?object $container = null;
    private ?string $appClass = null;
    private ?string $inputClass = null;
    private ?string $outputClass = null;
    private ?string $prefix = null;

    public function isWarm(): bool
    {
        return $this->application !== null;
    }

    /**
     * Drop the warm container + application so the next run() boots fresh. Used to
     * recover from warm-state corruption that resetReflectionState() cannot flush:
     * PHPStan's NodeScopeResolver/reflection caches are not ResettableInterface
     * services, so a class whose shape changed on disk between warm calls can yield
     * a null scope deep in PHPStanNodeScopeResolver ("Call to a member function
     * toMutatingScope() on null"). A fresh container is the only guaranteed reset.
     */
    public function reboot(): void
    {
        $this->application = null;
        $this->container = null;
    }

    /**
     * Run a rector command. Returns ['exit_code' => int, 'output' => string, 'warm_boot' => bool].
     *
     * @param list<string> $argv Rector CLI args including the binary name as $argv[0].
     *   E.g. ['rector', 'process', '/path/file.php', '--dry-run', '--output-format=json']
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    public function run(array $argv): array
    {
        $warmBoot = $this->isWarm();
        if (!$warmBoot) {
            $this->boot();
        } else {
            // Warm reuse. PHPStan's own class reflection (and its per-class
            // method/property caches) is not a ResettableInterface service and
            // lives for the whole process, so a class edited on disk between
            // calls is otherwise seen with its old shape forever -- no error,
            // just a silently wrong diff (claude-supertool#8).
            // resetReflectionState() alone (claude-supertool#273) only flushes
            // the three services Rector itself resets between fixtures; it
            // never touches PHPStan's caches.
            $this->resetReflectionState();
        }

        if ($this->canFork()) {
            // Isolate EVERY call (including the first, post-boot one) in a
            // forked child, never analysing in the parent process itself: the
            // child's copy-on-write memory absorbs every cache the analysis
            // fills in and dies with the child, so the parent's container
            // stays exactly as pristine as right after boot() for every call,
            // not only the ones after the first.
            return $this->runForked($argv, $warmBoot);
        }

        // No pcntl (e.g. Windows): forking is unavailable, and
        // resetReflectionState() alone is not a complete reset. The only
        // guaranteed-correct fallback is a fresh container before every warm
        // call -- slower than the warm path, but never wrong. warm_boot is
        // reported false: this call did not benefit from reuse.
        if ($warmBoot) {
            $this->reboot();
            $this->boot();
            $warmBoot = false;
        }

        return $this->execute($argv, $warmBoot);
    }

    private function canFork(): bool
    {
        return \function_exists('pcntl_fork')
            && \function_exists('pcntl_waitpid')
            && \function_exists('stream_socket_pair');
    }

    /**
     * Run $argv in a forked child so the parent's booted container is never mutated
     * by the analysis. The child serialises its result over a unix socket pair and
     * exits without running the parent's own shutdown sequence any further than
     * that; the parent waits for it and decodes the result.
     *
     * @param list<string> $argv
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    private function runForked(array $argv, bool $warmBoot): array
    {
        $sockets = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new \RuntimeException('Could not create a socket pair for the forked rector call.');
        }
        [$parentSocket, $childSocket] = $sockets;

        $pid = \pcntl_fork();
        if ($pid === -1) {
            \fclose($parentSocket);
            \fclose($childSocket);
            throw new \RuntimeException('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            // Child: analyse in isolation, report the result over the socket, then
            // exit. This is a real forked OS process -- it owns a private
            // copy-on-write copy of the parent's memory, including the parent's
            // stdio file descriptors, but this call is the only thing it ever does
            // before exiting, so nothing else reaches them.
            \fclose($parentSocket);
            $exitCode = 0;
            try {
                $result = $this->execute($argv, $warmBoot);
                \fwrite($childSocket, (string) \json_encode($result, \JSON_THROW_ON_ERROR));
            } catch (\Throwable $e) {
                \fwrite($childSocket, (string) \json_encode([
                    'error' => $e->getMessage(),
                    'error_class' => $e::class,
                ], \JSON_THROW_ON_ERROR));
                $exitCode = 1;
            } finally {
                \fclose($childSocket);
            }
            exit($exitCode);
        }

        // Parent: wait for the child's result, then reap it.
        \fclose($childSocket);
        $raw = '';
        while (!\feof($parentSocket)) {
            $chunk = \fread($parentSocket, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        \fclose($parentSocket);
        \pcntl_waitpid($pid, $status);

        $decoded = $raw === '' ? null : \json_decode($raw, true);
        if (!\is_array($decoded) || isset($decoded['error'])) {
            $message = \is_array($decoded) && isset($decoded['error'])
                ? (string) $decoded['error']
                : "forked rector call produced no output (child exit status {$status})";
            throw new \RuntimeException($message);
        }

        /** @var array{exit_code: int, output: string, warm_boot: bool} $decoded */
        return $decoded;
    }

    /**
     * @param list<string> $argv
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    private function execute(array $argv, bool $warmBoot): array
    {
        $inputClass = $this->inputClass;
        $outputClass = $this->outputClass;
        \assert($inputClass !== null && $outputClass !== null);
        \assert($this->application !== null);

        // ArgvInput expects $_SERVER['argv'] semantics: [scriptName, ...args]
        $input = new $inputClass($argv);
        $output = new $outputClass();

        // Rector's parallel mode forks workers via proc_open(PHP_BINARY . ' ' . $_SERVER['argv'][0] . ' worker --port=X ...').
        // From within an MCP server, argv[0] is our bin (not rector) so workers can't respawn.
        // Spoof argv[0] to the real rector binary path so workers spawn correctly. Restore after.
        $origArgv0 = $_SERVER['argv'][0] ?? null;
        $rectorBin = $this->findRectorBin();
        if ($rectorBin !== null) {
            $_SERVER['argv'][0] = $rectorBin;
        }

        // Rector's JsonOutputFormatter uses raw `echo` (rector/src/ChangesReporting/Output/JsonOutputFormatter.php:40)
        // bypassing the Symfony OutputInterface. Wrap in ob_*() to capture and prevent it from
        // leaking into our MCP stdio transport.
        ob_start();
        try {
            $exit = $this->application->run($input, $output);
        } finally {
            $echoed = ob_get_clean();
            if ($origArgv0 !== null) {
                $_SERVER['argv'][0] = $origArgv0;
            }
        }

        $combined = $output->fetch();
        if (is_string($echoed) && $echoed !== '') {
            $combined = $combined === '' ? $echoed : $combined . "\n" . $echoed;
        }

        return [
            'exit_code' => (int) $exit,
            'output' => $combined,
            'warm_boot' => $warmBoot,
        ];
    }

    private function boot(): void
    {
        // Load rector's scoped autoload lazily. Find it by reflecting on a Rector class:
        // works whether mcp-rector-warm is installed as a local clone (nested vendor) or as
        // a project/composer-global dep (rector lives parallel under the same vendor dir).
        if (!class_exists(RectorConfigsResolver::class, false)) {
            // file = .../rector/rector/src/Bootstrap/RectorConfigsResolver.php → 3 levels up = package root
            $rectorPkgDir = dirname((new \ReflectionClass(RectorConfigsResolver::class))->getFileName(), 3);
            $scoperAutoload = $rectorPkgDir . '/vendor/scoper-autoload.php';
            if (!is_file($scoperAutoload)) {
                throw new \RuntimeException("scoper-autoload.php not found at: {$scoperAutoload}");
            }
            require_once $scoperAutoload;
        }
        // Resolve Rector configs and build container.
        $resolver = new RectorConfigsResolver();
        $bootstrapConfigs = $resolver->provide();
        $factory = new RectorContainerFactory();
        $container = $factory->createFromBootstrapConfigs($bootstrapConfigs);

        $this->prefix = $this->detectRectorPrefix();
        if ($this->prefix === null) {
            throw new \RuntimeException('Could not detect Rector prefix namespace.');
        }

        $this->appClass = $this->resolvePrefixed('Symfony\\Component\\Console\\Application');
        $this->inputClass = $this->resolvePrefixed('Symfony\\Component\\Console\\Input\\ArgvInput');
        $this->outputClass = $this->resolvePrefixed('Symfony\\Component\\Console\\Output\\BufferedOutput');

        $app = $container->get($this->appClass);
        \assert(is_object($app));
        \assert(method_exists($app, 'setAutoExit'));
        \assert(method_exists($app, 'setCatchExceptions'));
        $app->setAutoExit(false);
        $app->setCatchExceptions(true);

        $this->application = $app;
        $this->container = $container;
    }

    /**
     * Reset Rector's per-run reflection state between warm calls. Mirrors
     * AbstractRectorTestCase::setUp(), which resets every service implementing
     * ResettableInterface so each fixture analyses with a fresh source locator.
     * The warm daemon reuses one container across files and needs the same flush;
     * without it the cached AggregateSourceLocator from the previous file poisons
     * the next one (claude-supertool#273).
     *
     * Rector's own container is entropy/entropy's Container, which finds services
     * by contract via findByContract() -- there is no tagged() method on it at
     * all, so a method_exists($container, 'tagged') guard here always fails and
     * this used to return before resetting anything (claude-supertool#8:
     * AbstractRectorTestCase itself calls findByContract(ResettableInterface::class),
     * confirmed against vendor/rector/rector/src/Testing/PHPUnit/AbstractRectorTestCase.php).
     * Now that every warm call runs isolated in a forked child (see run()), this
     * reset is no longer load-bearing for correctness, but it stays as the same
     * defence-in-depth Rector's own test harness relies on -- so it needs to
     * actually run rather than silently no-op.
     */
    private function resetReflectionState(): void
    {
        $container = $this->container;
        if ($container === null || !method_exists($container, 'findByContract')) {
            return;
        }

        /** @var iterable<object> $resettables */
        $resettables = $container->findByContract(\Rector\Contract\DependencyInjection\ResettableInterface::class);
        foreach ($resettables as $resettable) {
            if (method_exists($resettable, 'reset')) {
                $resettable->reset();
            }
        }
    }

    /**
     * Locate the real rector binary (the one parallel workers will respawn).
     * Looks at Composer's installed package dir first, then common vendor/bin paths.
     */
    private function findRectorBin(): ?string
    {
        if (class_exists(\Composer\InstalledVersions::class, false)) {
            try {
                $pkgDir = \Composer\InstalledVersions::getInstallPath('rector/rector');
                if (is_string($pkgDir)) {
                    foreach (['bin/rector', 'bin/rector.php'] as $rel) {
                        $candidate = realpath($pkgDir . '/' . $rel);
                        if ($candidate !== false && is_file($candidate)) {
                            return $candidate;
                        }
                    }
                }
            } catch (\Throwable) {
                // fall through
            }
        }
        foreach ([
            __DIR__ . '/../vendor/bin/rector',          // local clone
            __DIR__ . '/../../../../bin/rector',         // project-dep vendor/bin
        ] as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_file($resolved)) {
                return $resolved;
            }
        }
        return null;
    }

    private function detectRectorPrefix(): ?string
    {
        foreach (get_declared_classes() as $c) {
            if (preg_match('/^(RectorPrefix\d+)\\\\/', $c, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * Combine detected prefix with an un-prefixed FQN, then force-autoload.
     */
    private function resolvePrefixed(string $unprefixedFqn): string
    {
        \assert($this->prefix !== null);
        $fqn = $this->prefix . '\\' . $unprefixedFqn;
        if (!class_exists($fqn, true)) {
            throw new \RuntimeException("Prefixed class not found: {$fqn}");
        }
        return $fqn;
    }
}
