<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm;

use Rector\Bootstrap\RectorConfigsResolver;
use Rector\DependencyInjection\RectorContainerFactory;

/**
 * Holds a warm Rector container + Application across multiple analyse calls.
 *
 * The container is never built in THIS object's own OS process (#31): PHP fatals
 * ("Cannot declare class/function already declared") the second time a process
 * `require`s a rector.php (or withBootstrapFiles file) that declares a class or
 * function, and a reboot used to re-require it in the very process that had
 * already required it once. So booting always happens in a forked "warm worker"
 * child instead -- see boot()/serveWorker(). This instance (running inside the
 * long-lived MCP daemon) only ever talks to that worker over a persistent
 * socket; it never requires rector.php itself, so it can fork a brand-new
 * worker -- itself never having required anything either -- as many times as
 * the config changes, for the life of the daemon.
 *
 * Without pcntl (no fork at all -- Windows, or #18's disable_functions case),
 * there is no OS-process boundary available to isolate a reboot in, so warming
 * is not attempted at all: every call boots and runs in its own fresh `php`
 * subprocess (runCold()), correct but without the warm speedup.
 */
class RectorRunner implements RunnerInterface
{
    private ?object $application = null;
    private ?object $container = null;
    private ?string $appClass = null;
    private ?string $inputClass = null;
    private ?string $outputClass = null;
    private ?string $prefix = null;

    /** pid of the persistent warm-worker child that holds the booted container, or
     *  null when nothing is booted. Set by boot(), cleared by reboot()/forgetDeadWorker(). */
    private ?int $workerPid = null;

    /** @var resource|null Persistent duplex socket to the warm-worker child. */
    private $workerSocket = null;

    /** Absolute path of the main config file (--config, or rector.php/rector.dist.php) as
     *  resolved the last time a worker booted; null if none was found. Refreshed in THIS
     *  (never-booting) process by refreshConfigFileState() -- resolving the path and
     *  hashing its bytes never requires the file, so it stays safe to do here. */
    private ?string $configFile = null;

    /** sha256 of $configFile's contents as of the last successful boot, or null when
     *  $configFile is null. Compared against the file's CURRENT bytes before every call so
     *  an edit to rector.php between calls is picked up (#20) instead of staying pinned
     *  until restart. */
    private ?string $configFileHash = null;

    /** Map of each `withBootstrapFiles()` file's absolute path (as resolved by the last
     *  successful boot) to a sha256 of its contents at that boot, or [] when the config
     *  registered none. Unlike $configFile/$configFileHash, these paths are only knowable
     *  once the container is actually built -- Rector's own SimpleParameterProvider only
     *  holds them as a side effect of requiring rector.php -- so THIS (never-booting)
     *  instance cannot resolve them itself; they arrive from the worker's own boot
     *  handshake (serveWorker()) and are stored here verbatim by boot(), mirroring how
     *  refreshConfigFileState() mirrors $configFile/$configFileHash. Compared by content
     *  hash, same rationale as $configFileHash, so an edit to a bootstrap file (a class or
     *  function added after the file was first required) is picked up before the next call
     *  (#33) instead of staying invisible for the rest of the session.
     *  @var array<string, string|null> */
    private array $bootstrapFileHashes = [];

    public function isWarm(): bool
    {
        return $this->workerPid !== null;
    }

    /**
     * Tear down the warm worker (if any) so the next run() boots a fresh one in a brand
     * new, never-booted child. Used by RectorTool to recover from warm-state corruption:
     * PHPStan's NodeScopeResolver/reflection caches are not ResettableInterface services,
     * so a class whose shape changed on disk between warm calls can yield a null scope deep
     * in PHPStanNodeScopeResolver ("Call to a member function toMutatingScope() on null").
     * A fresh container is the only guaranteed reset.
     */
    public function reboot(): void
    {
        if ($this->workerPid === null) {
            return;
        }
        if (\is_resource($this->workerSocket)) {
            // EOF on the worker's read loop (serveWorker()) makes it exit cleanly.
            \fclose($this->workerSocket);
        }
        $status = 0;
        \pcntl_waitpid($this->workerPid, $status);
        $this->workerPid = null;
        $this->workerSocket = null;
    }

    /**
     * True when the worker process this instance still believes is booted no longer
     * exists -- crashed, was killed, or exited on its own -- since the last time this
     * was checked. Reaps it non-blockingly (pcntl_waitpid(..., WNOHANG)) so a dead
     * worker never lingers as a zombie: WNOHANG returns 0 immediately when the process
     * is still alive, so this never blocks run() waiting on a worker that is fine.
     * Distinct from isWarm(), which only reflects THIS instance's own belief.
     */
    private function workerIsDead(): bool
    {
        if ($this->workerPid === null) {
            return false;
        }
        $status = 0;
        $result = \pcntl_waitpid($this->workerPid, $status, \WNOHANG);

        return $result !== 0;
    }

    /**
     * Clear worker bookkeeping after workerIsDead() (or a failed frame write/read in
     * runForked()) found the worker gone, so the next run() boots a fresh one instead
     * of writing to a socket whose peer no longer exists. Unlike reboot(), never waits
     * on the pid: the worker is already known gone (or workerIsDead() already reaped
     * it), so a second wait here would either hang on the wrong process state or reap
     * nothing new.
     */
    private function forgetDeadWorker(): void
    {
        if (\is_resource($this->workerSocket)) {
            @\fclose($this->workerSocket);
        }
        $this->workerPid = null;
        $this->workerSocket = null;
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
        if (!$this->canFork()) {
            // No pcntl at all: there is no OS-process boundary available to isolate a
            // boot/reboot in (#31), so warming is never attempted -- every call is a
            // fresh, correct-by-construction cold run. warm_boot is always false: this
            // call never benefits from reuse.
            return $this->runCold($argv);
        }

        $warmBoot = $this->isWarm();
        if ($warmBoot && $this->workerIsDead()) {
            // The worker process crashed, was OOM-killed, or otherwise exited between
            // calls: isWarm() only reflects whether THIS instance still believes it
            // booted one, not whether that process still exists, so left unchecked
            // every call below would keep writing to a socket whose peer is gone
            // (#31 follow-up). Forget it so the block below boots a fresh worker
            // instead, exactly as if nothing had ever booted.
            $this->forgetDeadWorker();
            $warmBoot = false;
        }
        if ($warmBoot && $this->configFileChanged()) {
            // The worker's booted container still holds the rules it read at boot() time;
            // reusing it would run them forever, even though rector.php now says
            // something else (#20). Tear the worker down so the next boot() starts a
            // fresh one that re-resolves and re-requires whatever is on disk right now.
            $this->reboot();
            $warmBoot = false;
        }
        if (!$warmBoot) {
            $this->boot();
        }
        // Warm reuse must never analyse in the booted container itself. PHPStan's
        // own class reflection (and its per-class method/property caches) is not a
        // ResettableInterface service and lives for the whole process, so a class
        // edited on disk between calls would otherwise be seen with its old shape
        // forever -- no error, just a silently wrong diff (#8). runForked() below
        // asks the worker to isolate every call (including the first, post-boot
        // one) in ITS OWN forked grandchild, never analysing in the worker's own
        // process: the grandchild's copy-on-write memory absorbs every cache the
        // analysis fills in and dies with the grandchild, so the worker's container
        // stays exactly as pristine as right after boot() for every call.
        return $this->runForked($argv, $warmBoot);
    }

    protected function canFork(): bool
    {
        return \function_exists('pcntl_fork')
            && \function_exists('pcntl_waitpid')
            && \function_exists('stream_socket_pair');
    }

    /**
     * Boot: fork a brand-new "warm worker" child and wait for it to either confirm it
     * booted (a real container built inside that fresh process) or report why it could
     * not, before returning. The worker then serves every call for THIS instance until
     * reboot() (or run()'s own configFileChanged() check) tears it down -- see
     * serveWorker(). $this (the caller-facing RectorRunner living in the MCP daemon
     * process) never itself runs createFromBootstrapConfigs() -- see bootInPlace() --
     * so it can fork another brand-new, never-booted worker as many times as needed.
     */
    protected function boot(): void
    {
        $sockets = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new \RuntimeException('Could not create a socket pair for the warm worker.');
        }
        [$parentSocket, $childSocket] = $sockets;

        $pid = \pcntl_fork();
        if ($pid === -1) {
            \fclose($parentSocket);
            \fclose($childSocket);
            throw new \RuntimeException('pcntl_fork() failed while starting the warm worker.');
        }

        if ($pid === 0) {
            \fclose($parentSocket);
            $this->serveWorker($childSocket);
            // serveWorker() always exit()s; this line is unreachable.
        }

        \fclose($childSocket);
        $handshake = $this->readFrame($parentSocket);
        $decoded = $handshake === null ? null : \json_decode($handshake, true);
        if (!\is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            \fclose($parentSocket);
            $status = 0;
            \pcntl_waitpid($pid, $status);
            $message = \is_array($decoded) && isset($decoded['error'])
                ? (string) $decoded['error']
                : "the warm worker failed to boot (exit status {$status})";
            throw new \RuntimeException($message);
        }

        $this->workerPid = $pid;
        $this->workerSocket = $parentSocket;
        $this->refreshConfigFileState();
        // Bootstrap file paths (#33) are only known inside the process that actually
        // built the container -- the worker, never THIS instance -- so, unlike
        // $configFile/$configFileHash, they cannot be independently re-resolved here;
        // take the worker's own bootInPlace()-computed hashes verbatim off the handshake.
        $this->bootstrapFileHashes = \is_array($decoded['bootstrap_files'] ?? null)
            ? $decoded['bootstrap_files']
            : [];
    }

    /**
     * Worker child's own main loop, run right after boot()'s pcntl_fork(). Boots exactly
     * once (bootInPlace(), the same container-build boot() used to do in place before
     * #31), reports the outcome over $socket, then serves requests until the parent
     * closes the connection (reboot(), or the daemon exiting). Never returns.
     *
     * @param resource $socket
     */
    private function serveWorker($socket): void
    {
        try {
            $this->bootInPlace();
        } catch (\Throwable $e) {
            $this->writeFrame($socket, (string) \json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
                'error_class' => $e::class,
            ]));
            \fclose($socket);
            exit(1);
        }
        $this->writeFrame($socket, (string) \json_encode([
            'ok' => true,
            'bootstrap_files' => $this->bootstrapFileHashes,
        ]));

        while (true) {
            $frame = $this->readFrame($socket);
            if ($frame === null) {
                break;
            }
            $request = \json_decode($frame, true);
            $argv = \is_array($request) && \is_array($request['argv'] ?? null) ? $request['argv'] : [];
            $warmBoot = \is_array($request) && ($request['warm_boot'] ?? false) === true;
            try {
                $result = $this->forkAndExecute($argv, $warmBoot);
            } catch (\Throwable $e) {
                $result = ['error' => $e->getMessage(), 'error_class' => $e::class];
            }
            $encoded = \json_encode($result, \JSON_INVALID_UTF8_SUBSTITUTE);
            $this->writeFrame($socket, $encoded !== false ? $encoded : '{"error":"failed to encode the warm-worker result"}');
        }

        \fclose($socket);
        exit(0);
    }

    /**
     * Ask the (already-booted) warm worker to run $argv, isolated in its own forked
     * grandchild (forkAndExecute(), inside the worker), and wait for the result over the
     * persistent socket boot() set up. Called from run() whenever pcntl is available,
     * warm or not: the very first call after a fresh boot() takes this same path, so
     * every call -- not only reused ones -- is isolated from the worker's own container.
     *
     * @param list<string> $argv
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    protected function runForked(array $argv, bool $warmBoot): array
    {
        \assert($this->workerSocket !== null);
        $payload = (string) \json_encode(['argv' => $argv, 'warm_boot' => $warmBoot]);
        try {
            $this->writeFrame($this->workerSocket, $payload);
            $raw = $this->readFrame($this->workerSocket);
        } catch (\RuntimeException $e) {
            // The worker died mid-call (crash, OOM-kill) rather than between calls, so
            // workerIsDead()'s WNOHANG check in run() never got a chance to catch it
            // before this write/read was attempted. Forget it now so the NEXT call
            // boots a fresh worker instead of repeating this same failure forever
            // (#31 follow-up) -- this call still fails; there is no result to recover.
            $this->forgetDeadWorker();
            throw $e;
        }
        $decoded = $raw === null ? null : \json_decode($raw, true);
        if (!\is_array($decoded) || isset($decoded['error'])) {
            $message = \is_array($decoded) && isset($decoded['error'])
                ? (string) $decoded['error']
                : 'the warm worker closed its connection unexpectedly';
            if (!\is_array($decoded)) {
                // A clean EOF with no frame at all also means the worker is gone
                // (its own end of the socket closed) -- same self-heal as above.
                $this->forgetDeadWorker();
            }
            throw new \RuntimeException($message);
        }

        /** @var array{exit_code: int, output: string, warm_boot: bool} $decoded */
        return $decoded;
    }

    /**
     * Run $argv in a forked grandchild so the worker's booted container is never mutated
     * by the analysis (#8). The grandchild serialises its result over a unix socket pair
     * and exits without running the worker's own shutdown sequence any further than that;
     * the worker waits for it and decodes the result. Runs INSIDE the worker process
     * (called from serveWorker()) -- before #31 this ran directly in the daemon process
     * under the name runForked(); the mechanics are unchanged, only where it runs moved.
     *
     * @param list<string> $argv
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    private function forkAndExecute(array $argv, bool $warmBoot): array
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
            // Grandchild: analyse in isolation, report the result over the socket, then
            // exit. This is a real forked OS process -- it owns a private
            // copy-on-write copy of the worker's memory, including the worker's
            // stdio file descriptors -- which are NOT redirected (the worker itself
            // still inherits the daemon's real stdin/stdout/stderr unredirected;
            // attempted as a #31 follow-up and reverted after it interacted badly
            // with PHP's own resource-refcount GC, see the commit that added this
            // comment for the mechanism -- a real, open gap, not fixed here). A
            // stray PHP warning/deprecation notice during analysis -- routine for
            // PHPStan on real-world code -- must never land on a pipe something
            // else is reading a protocol from. Close and reopen fd 0/1/2 onto
            // /dev/null before running anything, in THIS grandchild at least: this
            // process exits right after this call and never legitimately needs
            // them, and $childSocket lives on its own fd above 2 so it is
            // unaffected.
            \fclose($parentSocket);
            if (\defined('STDIN')) {
                @\fclose(\STDIN);
            }
            if (\defined('STDOUT')) {
                @\fclose(\STDOUT);
            }
            if (\defined('STDERR')) {
                @\fclose(\STDERR);
            }
            @\fopen('/dev/null', 'rb');
            @\fopen('/dev/null', 'wb');
            @\fopen('/dev/null', 'wb');

            $exitCode = 0;
            try {
                $result = $this->execute($argv, $warmBoot);
                $encoded = $this->encodeForkResult($result);
                \fwrite($childSocket, $encoded);
            } catch (\Throwable $e) {
                \fwrite($childSocket, $this->encodeForkResult([
                    'error' => $e->getMessage(),
                    'error_class' => $e::class,
                ]));
                $exitCode = 1;
            } finally {
                \fclose($childSocket);
            }
            exit($exitCode);
        }

        // Worker: wait for the grandchild's result, then reap it. A read timeout
        // (default_socket_timeout, 60s by default) is NOT the same thing as the
        // grandchild closing the socket: fread() returns '' in both cases, but only a
        // real EOF means there is nothing left to read (#32). stream_get_meta_data()'s
        // 'timed_out' flag tells them apart -- an analysis that simply outran the
        // timeout keeps this loop waiting (the grandchild is still working and will
        // still write+close normally), instead of this being mistaken for "the child
        // produced no output" while it is, in fact, still running (and, with
        // dryRun: false, may already have written files to disk).
        \fclose($childSocket);
        $raw = '';
        while (!\feof($parentSocket)) {
            $chunk = \fread($parentSocket, 65536);
            if ($chunk === false || $chunk === '') {
                $meta = \stream_get_meta_data($parentSocket);
                if ($meta['timed_out'] ?? false) {
                    continue;
                }
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
     * Boot and run $argv once, in the current process, with no worker/fork indirection
     * at all. Used only by bin/rector-cold-call.php's one-shot subprocess (#31's
     * no-pcntl fallback) -- that process is thrown away right after this call, so
     * nothing here needs to protect a long-lived container from a second call's caches
     * (#8): there is no second call in this process.
     *
     * @param list<string> $argv
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    public function runOnceInThisProcess(array $argv): array
    {
        $this->bootInPlace();

        return $this->execute($argv, false);
    }

    /**
     * Run one call cold, in a brand-new `php` subprocess (bin/rector-cold-call.php):
     * booted and executed there, never in this (long-lived, no-pcntl) process, so a
     * rector.php/bootstrap file that declares a class or function can never be
     * re-required in a process that already required it -- this process never requires
     * it at all. Slower than the warm path (no reuse across calls), but the only
     * available way to isolate a boot from another when pcntl is unavailable.
     *
     * @param list<string> $argv
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    protected function runCold(array $argv): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $command = [\PHP_BINARY, \dirname(__DIR__) . '/bin/rector-cold-call.php'];
        $process = \proc_open($command, $descriptors, $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not spawn a cold rector subprocess (no-pcntl fallback, #31).');
        }

        $request = (string) \json_encode([
            'daemon_argv' => $_SERVER['argv'] ?? [],
            'call_argv' => $argv,
            'cwd' => \getcwd(),
        ]);
        \fwrite($pipes[0], $request);
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exitCode = \proc_close($process);

        $decoded = $stdout === false || $stdout === '' ? null : \json_decode($stdout, true);
        if (!\is_array($decoded) || isset($decoded['error'])) {
            $message = \is_array($decoded) && isset($decoded['error'])
                ? (string) $decoded['error']
                : "cold rector subprocess produced no output (exit {$exitCode}): " . \trim((string) $stderr);
            throw new \RuntimeException($message);
        }

        /** @var array{exit_code: int, output: string, warm_boot: bool} $decoded */
        return $decoded;
    }

    /**
     * Encode a forked/cold call's result for its transport. Rector's own JSON output
     * embeds raw source-derived text; a genuinely successful analysis of a file
     * containing non-UTF-8 byte sequences must not be turned into a spurious
     * error just because THIS encoding step failed. JSON_INVALID_UTF8_SUBSTITUTE
     * replaces invalid bytes rather than throwing, so a real result still
     * reaches the caller as a result. Without this, a thrown JsonException here
     * would be reported to the caller as a hard error for an analysis that
     * actually succeeded: RectorTool's reboot-and-retry recovery
     * (isRecoverableWarmCorruption()) only matches Rector/PHPStan corruption
     * messages, never an encoding failure, so it would never kick in.
     *
     * @param array<string, mixed> $payload
     */
    private function encodeForkResult(array $payload): string
    {
        $encoded = \json_encode($payload, \JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded !== false) {
            return $encoded;
        }

        $fallback = \json_encode([
            'error' => 'failed to encode the forked call result: ' . \json_last_error_msg(),
            'error_class' => 'JsonException',
        ]);

        return $fallback !== false ? $fallback : '{"error":"failed to encode the forked call result","error_class":"JsonException"}';
    }

    /**
     * Write $payload as one length-prefixed frame (a 4-byte big-endian length header,
     * then the bytes) on the persistent worker socket -- unlike forkAndExecute()'s
     * one-shot exchange (read-until-EOF is enough for a single message), this socket
     * carries many request/response pairs for the life of a worker, so each message
     * needs its own boundary.
     *
     * @param resource $socket
     */
    private function writeFrame($socket, string $payload): void
    {
        $data = \pack('N', \strlen($payload)) . $payload;
        $length = \strlen($data);
        $written = 0;
        while ($written < $length) {
            $n = \fwrite($socket, \substr($data, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('Failed writing a frame to the warm-worker socket.');
            }
            $written += $n;
        }
    }

    /**
     * Read one length-prefixed frame written by writeFrame(), or null on a clean EOF
     * before any bytes of a new frame arrived (the other end closed the connection).
     *
     * @param resource $socket
     */
    private function readFrame($socket): ?string
    {
        $header = $this->readExactly($socket, 4);
        if ($header === null) {
            return null;
        }
        $unpacked = \unpack('N', $header);
        $length = \is_array($unpacked) ? (int) $unpacked[1] : 0;
        if ($length === 0) {
            return '';
        }

        return $this->readExactly($socket, $length);
    }

    /**
     * @param resource $socket
     */
    private function readExactly($socket, int $length): ?string
    {
        $buffer = '';
        while (\strlen($buffer) < $length) {
            $chunk = \fread($socket, $length - \strlen($buffer));
            if ($chunk === false || $chunk === '') {
                // Same distinction as forkAndExecute()'s wait loop (#32): a read
                // timeout is not EOF. This channel is the persistent worker<->daemon
                // socket, so an analysis that legitimately runs longer than
                // default_socket_timeout must not be mistaken for the peer closing
                // the connection here either.
                $meta = \stream_get_meta_data($socket);
                if ($meta['timed_out'] ?? false) {
                    continue;
                }
                return null;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @param list<string> $argv
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    protected function execute(array $argv, bool $warmBoot): array
    {
        $inputClass = $this->inputClass;
        $outputClass = $this->outputClass;
        \assert($inputClass !== null && $outputClass !== null);
        \assert($this->application !== null);
        \assert($this->container !== null);

        // A rector.php that loads fine but registers zero rules (and zero sets)
        // hits ProcessCommand::execute()'s own "!areSomeRectorsLoaded()" onboarding
        // branch, which prints through the SymfonyStyle service -- backed by a
        // ConsoleOutput that holds a stream resource on the REAL \STDOUT, bypassing
        // the ob_*() wrap below entirely (#27, follow-up to #14's "no config file
        // at all" case already refused in boot()). Ask the same question boot()
        // asks, one level later, before $application->run() is ever called: a real,
        // reported error the caller cannot mistake for a completed run, and
        // Rector's onboarding text never reaches this process's stdout in either
        // the forked or the no-pcntl fallback path (both call this same method).
        // The class_exists()/method_exists() checks below fail OPEN if
        // ConfigInitializer is ever renamed upstream (skip the guard, run as
        // before #27) rather than breaking every call over a class that no
        // longer exists. container->get() itself is deliberately left
        // unguarded: if it throws for some other reason, that is no worse
        // than the status quo -- ProcessCommand's own later construction of
        // the identical ConfigInitializer service would hit the same failure
        // a few lines further in, also uncaught.
        if (\class_exists(\Rector\Configuration\ConfigInitializer::class)) {
            $configInitializer = $this->container->get(\Rector\Configuration\ConfigInitializer::class);
            if (
                \is_object($configInitializer)
                && \method_exists($configInitializer, 'areSomeRectorsLoaded')
                && !$configInitializer->areSomeRectorsLoaded()
            ) {
                throw new \RuntimeException(
                    'The rector.php config loaded, but registers no rules or sets. '
                    . 'Refusing to run Rector with nothing to do: add ->withRules() '
                    . 'or ->withSets() (or a preset) to the config.'
                );
            }
        }

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

    /**
     * The actual, once-per-OS-process container build: resolves Rector configs and
     * builds the container. rector.php is a plain PHP file `require`d while
     * resolving/building -- a project's own config (or a dependency it pulls in) can
     * `echo` while it loads (#15), and that must never reach the MCP transport's
     * stdout. Wrap the whole step the same way execute() already wraps
     * $application->run() for Rector's own raw-echo JSON formatter: ob_start()/
     * ob_get_clean() catches an explicit echo/print at PHP's output-buffer layer, before
     * it ever becomes a raw write(1, ...); it does NOT catch a notice/deprecation/warning
     * display (that bypasses the buffer stack entirely) -- display_errors=stderr, set in
     * bin/mcp-rector-warm (and bin/rector-cold-call.php) before any of this runs, covers
     * that half.
     *
     * Called from exactly two places, each of which runs it in a process that has never
     * called it before (#31): serveWorker(), inside a freshly forked worker child, and
     * runOnceInThisProcess(), inside a freshly spawned cold subprocess. Never called
     * directly by run() any more -- that would be back to re-requiring rector.php in a
     * process that already required it, which is the fatal this class exists to avoid.
     *
     * Nothing here catches a failure of provide() or createFromBootstrapConfigs():
     * provide() itself throws when an explicit --config path no longer exists on disk
     * (RectorConfigsResolver::resolveFromInput() -> Assert::fileExists()), and
     * createFromBootstrapConfigs() throws on e.g. an unknown rule class. Both, like the
     * "no config file at all" refusal just below, are left to escape uncaught -- the
     * caller (boot()'s handshake read, or runCold()'s subprocess decode) turns that into
     * a reported error, exactly like every other boot-time failure -- there is no
     * separate, CLI-shaped "fatal_errors JSON" reporting path here; #25/#26 already
     * established isError: true + structuredContent as this server's one error shape.
     */
    private function bootInPlace(): void
    {
        $this->ensureRectorAutoloaded();
        $this->ensureProjectAutoloaded();

        ob_start();
        try {
            $resolver = new RectorConfigsResolver();
            $bootstrapConfigs = $resolver->provide();

            if ($bootstrapConfigs->getMainConfigFile() === null) {
                // Rector's own CLI treats this as friendly onboarding: ProcessCommand
                // sees !areSomeRectorsLoaded(), offers to generate a rector.php via
                // SymfonyStyle, and returns Command::SUCCESS regardless (#14) -- a
                // silent no-op reported as success, from a call whose console
                // output we cannot even suppress: SymfonyStyle's ConsoleOutput
                // writes straight to \STDOUT, bypassing this very ob_*() wrap (it
                // holds a stream resource, not a userland echo). Refuse before the
                // container is even built, as a real, reported error the caller
                // cannot mistake for a completed run. NB this covers only the "no
                // config file at all" half of !areSomeRectorsLoaded(): a rector.php
                // that exists and loads fine but registers zero rules hits the
                // same SymfonyStyle path later, inside ProcessCommand::execute(),
                // unguarded by this check -- out of scope for #14 as filed, not
                // closed by this diff.
                throw new \RuntimeException(
                    'No rector.php (or rector.dist.php) config found in the working '
                    . 'directory, and no --config was given when the server started. '
                    . 'Refusing to run Rector without a config: pass --config=PATH to '
                    . 'mcp-rector-warm, or add a rector.php to the project.'
                );
            }

            $factory = new RectorContainerFactory();
            $container = $factory->createFromBootstrapConfigs($bootstrapConfigs);
        } finally {
            ob_end_clean();
        }

        $this->configFile = $bootstrapConfigs->getMainConfigFile();
        $this->configFileHash = $this->hashConfigFile($this->configFile);

        $this->prefix = $this->detectRectorPrefix();
        if ($this->prefix === null) {
            throw new \RuntimeException('Could not detect Rector prefix namespace.');
        }

        $this->bootstrapFileHashes = $this->resolveBootstrapFileHashes();

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
     * Resolve the main config file the same way bootInPlace() would, without building
     * anything -- safe to call in THIS (never-booting) process. Used by
     * configFileChanged() and refreshConfigFileState().
     */
    private function resolveMainConfigFile(): ?string
    {
        $this->ensureRectorAutoloaded();
        $resolver = new RectorConfigsResolver();

        return $resolver->provide()->getMainConfigFile();
    }

    /**
     * Hash every file the loaded config registered via withBootstrapFiles(), for
     * configFileChanged() (#33). Only callable AFTER createFromBootstrapConfigs() has
     * actually required rector.php: the list of bootstrap files is not resolvable from
     * the config path alone (unlike getMainConfigFile()) -- it only exists as a side
     * effect of Rector's own SimpleParameterProvider being populated while rector.php
     * runs, inside the very container build this is called right after (bootInPlace()).
     * Fails open (returns []) rather than throwing: a defensive fallback if the
     * SimpleParameterProvider class or its Option::BOOTSTRAP_FILES key ever moves
     * upstream, exactly like execute()'s ConfigInitializer check just below in this file
     * -- missing this file-list must never break a boot that otherwise succeeded, only
     * quietly lose the "picked up a bootstrap edit" behaviour this method exists for.
     *
     * @return array<string, string|null>
     */
    private function resolveBootstrapFileHashes(): array
    {
        try {
            $bootstrapFiles = \Rector\Configuration\Parameter\SimpleParameterProvider::provideArrayParameter('bootstrap_files');
        } catch (\Throwable) {
            return [];
        }

        $hashes = [];
        foreach ($bootstrapFiles as $bootstrapFile) {
            if (is_string($bootstrapFile)) {
                $hashes[$bootstrapFile] = $this->hashConfigFile($bootstrapFile);
            }
        }

        return $hashes;
    }

    /**
     * After a worker has booted successfully, mirror its $configFile/$configFileHash
     * bookkeeping onto THIS instance: the worker's own copy of those fields lives in a
     * different OS process's memory (set by bootInPlace() inside the fork) and is not
     * visible here, but configFileChanged() (called on THIS instance, before deciding
     * whether to keep talking to the existing worker) needs them. Resolving the path and
     * hashing its bytes never requires the file, so redoing that work here is safe.
     */
    private function refreshConfigFileState(): void
    {
        try {
            $mainConfigFile = $this->resolveMainConfigFile();
        } catch (\Throwable) {
            $mainConfigFile = null;
        }
        $this->configFile = $mainConfigFile;
        $this->configFileHash = $this->hashConfigFile($mainConfigFile);
    }

    /**
     * Load rector's scoped autoload lazily. Find it by reflecting on a Rector class: works
     * whether mcp-rector-warm is installed as a local clone (nested vendor) or as a
     * project/composer-global dep (rector lives parallel under the same vendor dir).
     * Idempotent -- safe to call before bootInPlace() has ever run, e.g. from
     * configFileChanged().
     */
    private function ensureRectorAutoloaded(): void
    {
        if (class_exists(RectorConfigsResolver::class, false)) {
            return;
        }
        // file = .../rector/rector/src/Bootstrap/RectorConfigsResolver.php → 3 levels up = package root
        $rectorPkgDir = dirname((new \ReflectionClass(RectorConfigsResolver::class))->getFileName(), 3);
        $scoperAutoload = $rectorPkgDir . '/vendor/scoper-autoload.php';
        if (!is_file($scoperAutoload)) {
            throw new \RuntimeException("scoper-autoload.php not found at: {$scoperAutoload}");
        }
        require_once $scoperAutoload;
    }

    /**
     * Load the target project's own Composer autoloader, exactly like cold Rector's
     * AutoloadIncluder::autoloadRectorInstalledAsGlobalDependency() does (bin/rector.php,
     * rector/rector package) before it boots the container (#30). mcp-rector-warm bundles
     * its own scoped copy of Rector (ensureRectorAutoloaded() above); the project being
     * analysed is never Rector's own dependency, so nothing else ever requires the
     * project's vendor/autoload.php, and a class that only resolves through it (a
     * Composer-autoloaded parent class, most commonly) is invisible -- warm silently
     * reports 0 changes where cold reports 1.
     *
     * Resolved relative to the current working directory: bin/mcp-rector-warm already
     * chdir()s to --working-dir before the server (and every fork) ever runs, so getcwd()
     * here is the project root, exactly like cold Rector's own relative 'vendor/autoload.php'
     * require is resolved against its process's cwd.
     *
     * Idempotent via get_included_files(), the same guard Rector's own AutoloadIncluder
     * uses (loadIfExistsAndNotLoadedYet()) -- safe to call every time bootInPlace() runs
     * (each boot is a never-booted-before process, per the class docblock, so in practice
     * this only ever runs once per process, but the guard costs nothing and matches the
     * upstream behaviour it mirrors).
     *
     * Known limitation, not silently ignored: this loads the project autoloader once, at
     * boot, in a worker process that then serves every warm call for the rest of its
     * session. A `composer dump-autoload` mid-session (a newly generated class in the
     * project's classmap) is invisible until the worker next reboots -- the same class of
     * staleness #20 (PR #29) already accepts for rector.php itself between edits, not
     * newly introduced here. Extending change detection to the project's own
     * vendor/composer/installed.php is a separate, wider change, left to #33/#34's own
     * "widen the watched-file set" discussion rather than folded in here.
     */
    private function ensureProjectAutoloaded(): void
    {
        $projectAutoload = getcwd() . '/vendor/autoload.php';
        if (!is_file($projectAutoload)) {
            return;
        }
        $realPath = realpath($projectAutoload);
        if ($realPath !== false && in_array($realPath, get_included_files(), true)) {
            return;
        }
        require_once $projectAutoload;
    }

    /**
     * True when the resolved main config file (--config, or rector.php/rector.dist.php --
     * exactly what bootInPlace() would use) no longer matches what the last successful
     * boot read. Compared by content hash rather than mtime+size: mtime has whole-second
     * resolution on common filesystems (two edits inside the same second are
     * indistinguishable), and some save strategies (an editor's atomic-rename-back, a git
     * checkout that restores the original bytes) can leave mtime and size unchanged, or both
     * changed with identical content -- either way mtime+size alone can miss or mis-detect a
     * real change. A hash of the actual bytes about to be require()'d has neither failure
     * mode, and the file is a handful of KB: cheap to hash before every call next to the
     * reboot it may trigger.
     *
     * Only the resolved main config file is tracked. A rector.php that itself
     * requires/includes a shared file is a known limitation, not silently ignored: building
     * the DI container in bootInPlace() autoloads hundreds of unrelated classes through the
     * same require/include machinery, so there is no reliable way to isolate "a file
     * rector.php chose to include" from that noise without parsing rector.php's own source
     * for require/include statements -- which would still miss a dynamically computed path.
     * Editing (even a no-op touch of) the main config file itself is the reliable way to
     * force a reboot after changing a file it includes.
     */
    protected function configFileChanged(): bool
    {
        try {
            $mainConfigFile = $this->resolveMainConfigFile();
        } catch (\Throwable) {
            // provide() itself can throw (e.g. an explicit --config path deleted since the
            // last boot). Don't let this check crash run() with an opaque exception -- report
            // "changed" so the reboot below runs boot() again, which hits the exact same
            // failure and reports it properly (isError: true, via RectorTool's own catch)
            // instead of this pre-call check crashing first.
            return true;
        }

        if ($mainConfigFile !== $this->configFile) {
            return true;
        }

        if ($this->hashConfigFile($mainConfigFile) !== $this->configFileHash) {
            return true;
        }

        foreach ($this->bootstrapFileHashes as $bootstrapFile => $hash) {
            if ($this->hashConfigFile($bootstrapFile) !== $hash) {
                return true;
            }
        }

        return false;
    }

    private function hashConfigFile(?string $path): ?string
    {
        if ($path === null || !is_file($path)) {
            return null;
        }
        $hash = hash_file('sha256', $path);
        return $hash !== false ? $hash : null;
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
