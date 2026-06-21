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
            // Warm reuse: flush per-run reflection state before analysing the next
            // file. Rector's DynamicSourceLocatorProvider caches its
            // AggregateSourceLocator for every non-PHPUnit run, so a second, different
            // file gets analysed with a locator that only knows the first file and
            // Rector emits 'System error: ClassReflection must be resolved for class X'
            // (claude-supertool#273). A fresh CLI process never hits this; the warm
            // daemon must reset the same services AbstractRectorTestCase resets between
            // fixtures to behave identically to cold.
            $this->resetReflectionState();
        }

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
     * AbstractRectorTestCase::setUp(), which resets every service tagged
     * ResettableInterface so each fixture analyses with a fresh source locator.
     * The warm daemon reuses one container across files and needs the same flush;
     * without it the cached AggregateSourceLocator from the previous file poisons
     * the next one (claude-supertool#273).
     */
    private function resetReflectionState(): void
    {
        $container = $this->container;
        if ($container === null || !method_exists($container, 'tagged')) {
            return;
        }

        /** @var iterable<object> $resettables */
        $resettables = $container->tagged(\Rector\Contract\DependencyInjection\ResettableInterface::class);
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
