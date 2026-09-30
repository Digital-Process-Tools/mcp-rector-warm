<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm;

use Dpt\McpRectorWarm\Support\ProcessTree;
use Dpt\McpRectorWarm\Warm\DependencyFileTracker;
use Dpt\McpRectorWarm\Warm\DirectorySnapshot;
use Dpt\McpRectorWarm\Warm\Path;
use Dpt\McpRectorWarm\Warm\SessionHooks;
use Dpt\McpRectorWarm\Warm\WarmSession;
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
 * there is no copy-on-write snapshot of a booted container to isolate each call
 * in, so each call gets a whole fresh `php` process instead -- booted AHEAD of
 * the call as a standby (#108, runViaStandbyWorker()), so the caller pays only
 * for the analysis, as with the fork. MCP_RECTOR_WARM_NO_PCNTL=cold restores the
 * pre-#108 behaviour: boot and run in one fresh subprocess per call (runCold()).
 */
class RectorRunner implements RunnerInterface
{
    /**
     * #106: `--rector-warm-skip-as=<original path>` marks a call on a temp
     * copy of an unsaved editor buffer. It never reaches Rector: execute()
     * strips it and applies the original path's skip rules to the copy.
     */
    public const SKIP_AS_OPTION = '--rector-warm-skip-as';

    /**
     * #185: MCP_RECTOR_WARM_SESSION=1 (or on / true / yes) turns the session
     * child on. OFF by default (PR #189 review): with the session on, inputs a
     * custom rule reads itself at call time and keeps in a static are not
     * watched unless declared in SESSION_WATCH_ENV -- see docs/how-it-works.md
     * §1b. No effect without pcntl: there is no session there.
     */
    public const SESSION_ENV = 'MCP_RECTOR_WARM_SESSION';

    /**
     * #185: extra inputs of the session (files or directories, relative to the
     * project, PATH_SEPARATOR-separated) -- what a custom rule reads itself.
     * A change to any file there, or a file appearing there, starts a fresh
     * session, the same as a changed PHP dependency.
     */
    public const SESSION_WATCH_ENV = 'MCP_RECTOR_WARM_SESSION_WATCH';

    /** #185: the session child retires after a call once its own memory passes this many MB (default 1024). */
    public const SESSION_MAX_MB_ENV = 'MCP_RECTOR_WARM_SESSION_MAX_MB';

    /** #185: the session child retires after this many calls it served itself (default 250). */
    public const SESSION_MAX_CALLS_ENV = 'MCP_RECTOR_WARM_SESSION_MAX_CALLS';

    private const SESSION_DEFAULT_MAX_MB = 1024;
    private const SESSION_DEFAULT_MAX_CALLS = 250;

    /**
     * #185: MCP_RECTOR_WARM_SESSION_LOG=1 makes the warm worker log on stderr
     * what served each analysis call -- see sessionLog().
     */
    public const SESSION_LOG_ENV = 'MCP_RECTOR_WARM_SESSION_LOG';

    /** #185: a session child retires after a call once it holds this share of memory_limit. */
    private const SESSION_MEMORY_SHARE = 0.75;

    /** #185, worker side: pid of the live session child, or null. */
    private ?int $sessionPid = null;

    /** @var resource|null #185, worker side: the worker's end of its session child's socket. */
    private $sessionSocket = null;

    /** #185, worker side: set once a session child could not start; fork per call from then on. */
    private bool $sessionUnavailable = false;

    /** #185, worker side: the project's directory listings, refreshed before each session child is forked. */
    private ?DirectorySnapshot $sessionDirectories = null;

    /**
     * @var resource|null #185, worker side: the worker's own end of the
     * daemon<->worker socket. A session child closes its inherited copy, or the
     * daemon would never read EOF from a worker that died while the child lived.
     */
    private $workerDaemonSocket = null;

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

    /** Whether the call most recently ATTEMPTED (not necessarily completed) was
     *  served by an already-warm container/worker, captured at the moment $warmBoot
     *  was decided for that call -- before anything the call itself does (a
     *  --call-timeout kill, a crash) can tear the worker down. Unlike isWarm(),
     *  which reports whether a warm worker/container exists RIGHT NOW, this
     *  survives that teardown, so an error payload built after a failed call can
     *  still report accurately whether THAT call was warm (#126). */
    private bool $lastCallWasWarm = false;

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

    /** Absolute path of the project's composer.json (getcwd() . '/composer.json', matching
     *  ensureProjectAutoloaded()'s own resolution -- bin/mcp-rector-warm chdir()s to
     *  --working-dir before anything forks) as resolved the last time a worker booted, or
     *  null when the file does not exist. withPhpSets() called with no argument reads this
     *  file's `require.php` once, at boot, to pick its rule sets -- unlike rector.php itself
     *  or a withBootstrapFiles() file, composer.json is never required by rector.php, so a
     *  mid-session edit to it went undetected by configFileChanged() and the worker kept
     *  serving the PHP-set selection from the stale constraint (#34). Refreshed the same way
     *  $configFile is: resolving the path and hashing its bytes never requires the file.
     *  Deliberately coarse, same trade-off as the main config file already accepts: whether
     *  a given rector.php actually calls withPhpSets() with no argument is not knowable
     *  without executing it, so ANY composer.json edit forces a reboot on the next call, not
     *  only one that changes require.php -- a project with a composer.json but no bare
     *  withPhpSets() call reboots on an unrelated composer.json edit (a new dependency, a
     *  reformat) exactly as an unrelated rector.php edit already forces a reboot today. */
    private ?string $composerFile = null;

    /** sha256 of $composerFile's contents as of the last successful boot, or null when
     *  $composerFile is null. Same rationale and comparison method as $configFileHash. */
    private ?string $composerFileHash = null;

    /** Hard per-call deadline in seconds, independent of the socket read timeout
     *  (#32/#58): 0 means unlimited (today's pre-#58 behaviour). Defaults to
     *  self::$defaultCallTimeoutSeconds so every RectorRunner built by the MCP SDK's
     *  own autowiring (RectorTool's constructor -- see there for why it cannot take
     *  this as its own constructor argument) still gets the value bin/mcp-rector-warm
     *  set from --call-timeout, without needing a constructor argument only tests
     *  actually pass. */
    private int $callTimeoutSeconds;

    /** Set once, at process start, by bin/mcp-rector-warm's --call-timeout parsing,
     *  before any RectorTool/RectorRunner is constructed. bin/rector-cold-call.php
     *  -- the no-pcntl one-shot subprocess spawned BY runCold() -- never calls this
     *  setter and never needs to: its own RectorRunner only ever reaches
     *  runOnceInThisProcess() -> execute(), never forkAndExecute()/runForked(), so
     *  its $callTimeoutSeconds is simply unused; the deadline for a cold call is
     *  enforced one level up, by the DAEMON's own runCold() (the process that
     *  spawned and is timing that subprocess), not inside the subprocess itself.
     *  Never read directly: every RectorRunner instance resolves its OWN
     *  $callTimeoutSeconds once, in its constructor, so a later call to this
     *  setter cannot change the timeout an already-built instance enforces
     *  mid-call. */
    private static ?int $defaultCallTimeoutSeconds = null;

    public static function setDefaultCallTimeoutSeconds(int $seconds): void
    {
        self::$defaultCallTimeoutSeconds = $seconds;
    }

    /**
     * @param int|null $callTimeoutSeconds 0 = unlimited; null = use whatever
     *   setDefaultCallTimeoutSeconds() set, or 600 if that was never called
     *   (matches bin/mcp-rector-warm's own --call-timeout default, #58).
     */
    public function __construct(?int $callTimeoutSeconds = null)
    {
        $this->callTimeoutSeconds = $callTimeoutSeconds ?? self::$defaultCallTimeoutSeconds ?? 600;
    }

    public function isWarm(): bool
    {
        return $this->workerPid !== null || $this->procWorker !== null;
    }

    /** Whether the call most recently attempted was served by an already-warm
     *  worker/container -- see $lastCallWasWarm. Distinct from isWarm(), and the
     *  one to read from an error payload built AFTER a failed call (#126): a
     *  --call-timeout kill (or any other failure that discards the worker) makes
     *  isWarm() say false from that point on regardless of what actually served
     *  the call, while this stays put. */
    public function wasLastCallWarm(): bool
    {
        return $this->lastCallWasWarm;
    }

    /** @inheritDoc */
    public function getCallTimeoutSeconds(): int
    {
        return $this->callTimeoutSeconds;
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
        // #108 (no pcntl): the standby, and any worker still exiting after its call.
        // Synchronous, so no process is left holding the project dir as its cwd
        // (which on Windows blocks removing it).
        $this->discardProcWorker(false);
        $this->stopRetiredProcWorkers();
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
     * @param bool $dryRun Whether THIS call is analysis-only. Gates whether the
     *   --call-timeout deadline applies at all (#72 correction): a dryRun:false
     *   call is never killed by it, at any of the kill sites below.
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    public function run(array $argv, bool $dryRun = true): array
    {
        // Reset up front, not merely at each decision point below: a call that
        // fails BEFORE reaching one (boot(), spawnProcWorker(), or
        // awaitProcWorkerReady() throwing) must not inherit whatever a PREVIOUS,
        // unrelated call last decided -- that would misreport exactly the class
        // of thing #126 itself was filed for, just in the opposite direction
        // (self-review finding).
        $this->lastCallWasWarm = false;
        if (!$this->canFork()) {
            // No pcntl (Windows, or #18's disable_functions case): no fork, so no
            // copy-on-write snapshot of a booted container to isolate each call in.
            // #108: a pre-booted, single-use worker PROCESS stands in for the fork --
            // see runViaStandbyWorker(). MCP_RECTOR_WARM_NO_PCNTL=cold is the escape
            // hatch back to the pre-#108 cold subprocess per call.
            $mode = $this->noPcntlMode();
            if ($mode === self::NO_PCNTL_MODE_COLD) {
                return $this->runCold($argv, $dryRun);
            }

            return $this->runViaStandbyWorker($argv, $dryRun);
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
        return $this->runForked($argv, $warmBoot, $dryRun);
    }

    protected function canFork(): bool
    {
        return \function_exists('pcntl_fork')
            && \function_exists('pcntl_waitpid')
            && \function_exists('stream_socket_pair');
    }

    /**
     * #157: this process's own pid, taken the same way boot() always has --
     * but logged, not silently folded into null, when \getmypid() itself
     * returns false (a documented-but-essentially-never-seen PHP edge case).
     * A null $daemonPid makes forkAndExecute()'s wait loop skip #127's whole
     * mid-call orphan-kill check with no signal that it did; this is the one
     * place that decision is made, so it is the one place that can log it.
     * \getmypid() is wrapped in its own overridable method (protected, same
     * seam canFork()/hasPosixKill() already use) purely so a test can force
     * the branch: forcing the real builtin to return false is not achievable
     * from a test, matching the issue's own stated limit.
     */
    protected function currentPid()
    {
        return \getmypid();
    }

    private function daemonPidOrWarn(): ?int
    {
        $pid = $this->currentPid();
        if ($pid === false) {
            $this->logGetmypidFailure("#127's mid-call orphan-kill detection is disabled for this warm worker");

            return null;
        }

        return $pid;
    }

    /**
     * Self-review finding on #157: two sibling call sites in this file also
     * read \getmypid() raw -- spawnProcWorker()'s 'daemon_pid' payload (the
     * no-pcntl standby worker's own idle-wait daemonPid, already coerced to
     * null downstream by bin/rector-warm-worker.php's is_int() check, so
     * failing open there was already safe -- only the signal was missing)
     * and spawnOrphanWatchdog()'s own workerPid argv (where a false here
     * becomes an empty string, then (int) '' === 0, tripping the watchdog's
     * own <= 0 guard and exiting silently -- the same #134-inert shape #159
     * describes, one level further out). Neither call site changes shape
     * here, same as #157's own fix: this only adds the missing log line.
     */
    private function logGetmypidFailure(string $consequence): void
    {
        if (\defined('STDERR') && \is_resource(\STDERR)) {
            @\fwrite(\STDERR, "mcp-rector-warm: getmypid() returned false; {$consequence} (#157)\n");
        }
    }

    /** Extra seconds runForked()'s own (daemon-side) deadline gets on top of
     *  $callTimeoutSeconds, so forkAndExecute()'s deadline (inside the worker,
     *  same $callTimeoutSeconds, no grace) reliably fires FIRST and gets the
     *  chance to SIGKILL the grandchild, reap it, and write a clean error frame
     *  back before the daemon-side backstop would also expire. Without this, the
     *  two independent deadlines racing on the identical instant means the
     *  daemon-side one can fire concurrently with -- not only after -- the
     *  worker's own, defeating "only a genuinely unresponsive WORKER trips the
     *  daemon-side kill" (#58): SIGKILL + pcntl_waitpid + one writeFrame() is not
     *  free, and none of it happens before the worker's own deadline is reached. */
    private const RUN_FORKED_DEADLINE_GRACE_SECONDS = 5;

    /**
     * Monotonic deadline (hrtime(true) nanoseconds) for the call this instance is
     * about to make, or null when $callTimeoutSeconds is 0 (unlimited, #58). Read
     * once per call at the point the call starts, never cached across calls: each
     * call gets its own fresh deadline. hrtime(true), not time()/microtime(), so a
     * system clock adjustment mid-call can never shorten or extend it.
     */
    private function callDeadlineNs(int $graceSeconds = 0): ?int
    {
        return $this->callTimeoutSeconds > 0
            ? \hrtime(true) + ($this->callTimeoutSeconds + $graceSeconds) * 1_000_000_000
            : null;
    }

    /**
     * Deadline for boot()'s handshake read: the bare call deadline, no grace
     * (see the comment at its call site). Its own method only so a test can
     * keep a real container build out of the short --call-timeout it measures
     * a CALL against (#113) -- boot()'s deadline is computed here in the
     * daemon process, after the worker has already forked with its own copy of
     * $callTimeoutSeconds, so overriding this never changes the worker's
     * forkAndExecute() deadline.
     */
    protected function bootDeadlineNs(): ?int
    {
        return $this->callDeadlineNs();
    }

    /**
     * Best-effort SIGKILL of a wedged $pid, then reap it -- the shared shape
     * behind every deadline-expiry path in this file (boot(), runForked(),
     * forkAndExecute()). Blocks on the reap ONLY when a kill signal was
     * actually sent: pcntl_waitpid() with no WNOHANG waits for the process to
     * exit, and a process that was just SIGKILLed exits promptly, so that wait
     * is bounded in practice. Without posix_kill() (self-review finding,
     * #58 follow-up: pcntl and posix are separate extensions -- a build can
     * have one without the other), nothing was ever sent to stop the wedge, so
     * a BLOCKING wait here would trade "the caller never returns" for "this
     * cleanup step never returns" on the exact same wedged process --
     * defeating the whole point of the deadline. WNOHANG there instead:
     * best-effort, never blocks. If the process is still wedged, it is not
     * reaped here and may rarely linger as a real zombie until it exits (or
     * the daemon itself does) -- a narrower, platform-specific gap than an
     * unbounded hang, and the caller gets its clean, timely error either way.
     */
    private function killAndReap(int $pid): void
    {
        $status = 0;
        if ($this->hasPosixKill()) {
            // #112: the whole tree, not only $pid -- Rector's parallel workers
            // (and anything a rector.php starts) are $pid's descendants, and a
            // SIGKILL to $pid alone left them running as orphans.
            ProcessTree::killTree($pid);
            @\posix_kill($pid, \SIGKILL);
            \pcntl_waitpid($pid, $status);
        } else {
            \pcntl_waitpid($pid, $status, \WNOHANG);
        }
    }

    /**
     * #71: extracted so tests can force killAndReap()'s WNOHANG branch (pcntl
     * present, posix absent) independent of what this environment actually has
     * installed -- the same reason canFork() below exists as its own overridable
     * method rather than an inline function_exists() check. No CI leg exercises
     * that specific combination (the no-pcntl job disables pcntl itself, which
     * disables both branches' precondition), so a test is the only coverage
     * this repo can have for it.
     */
    protected function hasPosixKill(): bool
    {
        return \function_exists('posix_kill');
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

        // #127: this process's OWN pid, taken before the fork, so the worker
        // it is about to fork can recognise when THIS daemon is gone (a
        // kill -9 closes no fd the worker is waiting on mid-call -- see
        // forkAndExecute()'s orphan check) rather than only when it happens
        // to be idle between calls (where EOF on $socket already covers it).
        $daemonPid = $this->daemonPidOrWarn();

        $pid = \pcntl_fork();
        if ($pid === -1) {
            \fclose($parentSocket);
            \fclose($childSocket);
            throw new \RuntimeException('pcntl_fork() failed while starting the warm worker.');
        }

        if ($pid === 0) {
            \fclose($parentSocket);
            $this->serveWorker($childSocket, $daemonPid);
            // serveWorker() always exit()s; this line is unreachable.
        }

        \fclose($childSocket);
        // #58 follow-up: a worker that wedges DURING its own container build
        // (bootInPlace(), inside serveWorker(), before it ever writes the
        // handshake frame) used to block this read forever -- the very first
        // call a caller makes, with --call-timeout doing nothing, even though
        // this is exactly the class of unbounded wait --call-timeout exists to
        // close. No inner/outer grace period needed here (unlike runForked()'s
        // backstop over forkAndExecute()'s own deadline): this read is the ONLY
        // deadline layer over the boot handshake, there is no separate
        // worker-side sub-process boundary underneath it to give a head start
        // to, so the bare callDeadlineNs() (no grace) is the right one.
        $bootDeadline = $this->bootDeadlineNs();
        if ($bootDeadline !== null) {
            \stream_set_timeout($parentSocket, 1);
        }
        try {
            $handshake = $this->readFrame($parentSocket, $bootDeadline);
        } catch (RectorCallTimeoutException $e) {
            // The worker pid is known (pcntl_fork() just returned it) even
            // though it never finished booting -- SIGKILL + reap it here so it
            // never lingers as a zombie under THIS process, same as every other
            // deadline-expiry path in this file.
            $this->killAndReap($pid);
            \fclose($parentSocket);
            // #113: never rethrow readExactly()'s own message here. That text
            // ("... waiting on the warm worker") is runForked()'s outer
            // backstop's, which only ever fires after the call deadline PLUS
            // RUN_FORKED_DEADLINE_GRACE_SECONDS; reusing it for this ungraced
            // boot deadline made a slow container build read as the outer
            // backstop tripping at ~1s -- a kill site that cannot exist.
            // Deliberately not "exceeded {N}s" either: that figure is how the
            // wedge tests recognise forkAndExecute()'s inner kill site.
            throw new \RuntimeException(
                "rector call exceeded its configured --call-timeout ({$this->callTimeoutSeconds}s) before the "
                . 'warm worker finished booting; the worker was killed',
            );
        }
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
     * @param int|null $daemonPid #127: this worker's parent daemon's pid, taken
     *   BEFORE the fork in boot() -- threaded through to forkAndExecute() so its
     *   wait loop can notice the daemon dying mid-call, not only between calls
     *   (where EOF on $socket, below, already covers it).
     */
    private function serveWorker($socket, ?int $daemonPid = null): void
    {
        // #133: an independent E2E check of #127 found that ANY Throwable escaping
        // this method (not only bootInPlace()'s, which the old code caught) unwinds
        // this FORKED CHILD straight back into boot()'s own call frame -- pcntl_fork()
        // duplicates the whole PHP call stack and every inherited fd, including the
        // real daemon's own client stdin/stdout, so an uncaught exception here does
        // not "crash the worker": it resumes this forked child inside the SAME
        // request-handling loop the real daemon is running, which then answers the
        // client's own pipes as a second, bogus server. Concretely: the old code's
        // OWN writeFrame($socket, ...) call at the end of the request loop sat
        // OUTSIDE any try/catch -- once #127's orphan check started throwing on a
        // dead $socket, that write itself threw a broken-pipe RuntimeException
        // straight past this method's edge with nothing left to catch it. The same
        // escape already existed before #127, just far rarer: any writeFrame() call
        // here (the boot-failure report, the boot-success handshake, or a normal
        // call's reply) throws exactly this way whenever the daemon's end of
        // $socket has already closed by the time it runs. Wrapping the ENTIRE body
        // in one try/catch/finally, with an unconditional exit() in the finally, is
        // what actually closes this: this worker process must NEVER return or
        // unwind into its caller, no matter what throws or when.
        $exitCode = 0;
        $this->workerDaemonSocket = $socket;
        try {
            try {
                $this->bootInPlace();
            } catch (\Throwable $e) {
                $this->writeFrame($socket, $this->encodeHandshakeFrame([
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'error_class' => $e::class,
                ]));
                $exitCode = 1;

                return;
            }
            $this->writeFrame($socket, $this->encodeHandshakeFrame([
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
                // Default true (analysis, deadline applies) when the field is
                // somehow missing -- the safer of the two readings for an
                // unrecognised/older frame: it fails toward "may still be
                // killed", never toward "silently unkillable" (#72).
                $dryRun = !\is_array($request) || ($request['dry_run'] ?? true) === true;
                try {
                    $result = $this->serveRequest($argv, $warmBoot, $dryRun, $daemonPid);
                } catch (\Throwable $e) {
                    $result = ['error' => $e->getMessage(), 'error_class' => $e::class];
                }
                $encoded = \json_encode($result, \JSON_INVALID_UTF8_SUBSTITUTE);
                $this->writeFrame($socket, $encoded !== false ? $encoded : '{"error":"failed to encode the warm-worker result"}');
            }
        } catch (\Throwable) {
            // Catch-all safety net -- see the docblock above. Whatever threw
            // (writeFrame() on a dead socket, or anything else), this worker
            // still exits cleanly rather than escaping into boot()'s caller.
            $exitCode = 1;
        } finally {
            // #185: the session child dies with its worker, never outlives it.
            $this->stopSession();
            @\fclose($socket);
            exit($exitCode);
        }
    }

    /**
     * Ask the (already-booted) warm worker to run $argv, isolated in its own forked
     * grandchild (forkAndExecute(), inside the worker), and wait for the result over the
     * persistent socket boot() set up. Called from run() whenever pcntl is available,
     * warm or not: the very first call after a fresh boot() takes this same path, so
     * every call -- not only reused ones -- is isolated from the worker's own container.
     *
     * @param list<string> $argv
     * @param bool $dryRun #72 correction: the deadline below (and its grace
     *   period) applies ONLY when $dryRun is true. A dryRun:false call must
     *   never be killed by --call-timeout, so no deadline is computed for it
     *   at all -- it falls back to the pre-#58 behaviour of relying on
     *   whatever default_socket_timeout already governs, unbounded.
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    protected function runForked(array $argv, bool $warmBoot, bool $dryRun): array
    {
        $this->lastCallWasWarm = $warmBoot;
        \assert($this->workerSocket !== null);
        // #74 follow-up: same missing-flag pattern as the boot handshake frames
        // this file's own encodeHandshakeFrame()/encodeForkResult() guard
        // against -- an argv element that is not valid UTF-8 would otherwise
        // make json_encode() return false here, silently sending an empty
        // request frame to the worker instead of a real one.
        $payload = (string) \json_encode(
            ['argv' => $argv, 'warm_boot' => $warmBoot, 'dry_run' => $dryRun],
            \JSON_INVALID_UTF8_SUBSTITUTE,
        );
        // Same short-per-read-timeout rationale as forkAndExecute()'s wait loop
        // (#58): only touched when a deadline is actually in play, so the
        // unlimited case ($callTimeoutSeconds == 0) -- or a dryRun:false call,
        // #72 correction -- keeps relying on whatever default_socket_timeout
        // already governs, unchanged from before #58.
        // The grace period (see RUN_FORKED_DEADLINE_GRACE_SECONDS) is what makes
        // this a genuine backstop rather than a coin flip against
        // forkAndExecute()'s own, identically-timed deadline.
        $deadline = $dryRun ? $this->callDeadlineNs(self::RUN_FORKED_DEADLINE_GRACE_SECONDS) : null;
        if ($deadline !== null) {
            \stream_set_timeout($this->workerSocket, 1);
        }
        try {
            $this->writeFrame($this->workerSocket, $payload);
            $raw = $this->readFrame($this->workerSocket, $deadline);
        } catch (RectorCallTimeoutException $e) {
            // Defense in depth: forkAndExecute()'s OWN deadline (same budget,
            // checked inside the worker) should normally have already reported a
            // clean timeout error by now -- this only fires when the WORKER
            // ITSELF has gone unresponsive, not merely its forked grandchild.
            // Force it down so the next call self-heals into a fresh worker
            // (#43) instead of waiting on a read that may never arrive. This
            // process only ever learns the worker's own pid (boot()'s
            // pcntl_fork() return) -- never its grandchild's, which is forked
            // and reaped entirely inside the worker's own forkAndExecute().
            // killAndReap() kills the worker's whole process tree (#112), so an
            // in-flight grandchild and whatever it spawned go down with it
            // (reparented and reaped by init, never a zombie under THIS process).
            if ($this->workerPid !== null) {
                $this->killAndReap($this->workerPid);
            }
            $this->forgetDeadWorker();
            throw new \RuntimeException($e->getMessage());
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
     * @param bool $dryRun #72 correction: the wait-loop deadline below applies
     *   ONLY when $dryRun is true -- a dryRun:false call must never have its
     *   grandchild killed by --call-timeout, so no deadline is computed for it.
     * @param int|null $daemonPid #127: this worker's own parent daemon's pid
     *   (boot()'s pre-fork getmypid(), threaded through serveWorker()). Polled
     *   in the wait loop below so a daemon killed while THIS call is in
     *   flight is noticed within roughly ORPHAN_POLL_SECONDS, the same
     *   mechanism serveProcessWorker() already uses while idle -- rather than
     *   this worker only learning the daemon is gone once the grandchild
     *   itself finally returns (its own deadline, or never, when
     *   $callTimeoutSeconds is 0/$dryRun is false) and this loop falls
     *   through to the next readFrame($socket), which is the first place EOF
     *   on the DAEMON socket would otherwise be noticed.
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    private function forkAndExecute(array $argv, bool $warmBoot, bool $dryRun, ?int $daemonPid = null, ?int $deadlineNs = null): array
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
            // stdio file descriptors, which are the daemon's real stdin/stdout/stderr
            // (the worker inherits them unredirected; attempted as a #31 follow-up and
            // reverted after it interacted badly with PHP's own resource-refcount GC).
            // A stray PHP warning/deprecation notice during analysis -- routine for
            // PHPStan on real-world code -- must never land on a pipe something else
            // is reading a protocol from.
            //
            // The STDIN/STDOUT/STDERR constants must stay OPEN here, though (#192).
            // PHPStan reads their runtime values when it builds PhpStorm-stub
            // reflection for the stub file that declares them (on a PHPStan cache
            // miss, e.g. the first run on a fresh machine): an open stream becomes
            // constant('STDIN'), a closed one fails is_resource() and falls through
            // to BuilderHelpers::normalizeValue(), which throws "Invalid value" --
            // warm reported that where cold returned a diff. This grandchild used to
            // fclose() all three and reopen fd 0/1/2 on /dev/null; PHP has no dup2(),
            // so there is no way to move the fds without closing the constants.
            // Instead: every byte PHP's output layer would write to fd 1 goes into a
            // buffer that discards it (execute()'s own ob_start() nests inside it,
            // and a fatal's flush at shutdown lands here too), and PHP's own error
            // messages bound for fd 2 are switched off, as /dev/null had them. Not
            // covered any more: code that writes to STDOUT/STDERR or php://stdout
            // directly -- the same channel Rector's SymfonyStyle already had, since
            // it opens php://stdout in the worker, before this fork.
            \fclose($parentSocket);
            self::silenceChildOutput();

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
        // A short per-read timeout, independent of default_socket_timeout (#32),
        // so the deadline check below runs promptly -- default_socket_timeout can
        // be minutes; this loop must not wait a whole one of those just to notice
        // the deadline already passed. Only set when a deadline is actually in
        // play: with $callTimeoutSeconds == 0 (unlimited), OR $dryRun false
        // (#72 correction: a write call is never killed), leave the socket on
        // whatever default_socket_timeout already governs, unchanged from before
        // #58. #185: serveRequest() passes the call's deadline in, so a session
        // attempt that falls back to this fork does not restart the budget.
        $deadline = $dryRun ? ($deadlineNs ?? $this->callDeadlineNs()) : null;
        // #127: a 1s per-read timeout now runs UNCONDITIONALLY, not only when a
        // deadline is armed -- this loop must wake up periodically to poll
        // $daemonPid below even when $deadline is null ($callTimeoutSeconds ==
        // 0, or $dryRun false), which was previously the exact combination
        // under which this loop never woke up at all until the grandchild
        // itself returned. This does not change the deadline check's own
        // granularity (still 1s -- see the deadline-grace-gap jit-context
        // rule before touching either number in this file).
        \stream_set_timeout($parentSocket, 1);
        $raw = '';
        // #127: throttle the actual liveness check to roughly
        // ORPHAN_POLL_SECONDS, same rationale as serveProcessWorker()'s idle
        // poll (a posix_getppid() call is cheap, but the Windows
        // ProcessTree::isAlive() fallback spawns `tasklist`) -- the 1s
        // stream timeout above only needs to be shorter than this so the
        // deadline check keeps its own 1s granularity; the orphan check
        // itself does not need to run every single tick.
        $nextOrphanCheckNs = \hrtime(true);
        while (!\feof($parentSocket)) {
            $chunk = \fread($parentSocket, 65536);
            if ($chunk === false || $chunk === '') {
                $meta = \stream_get_meta_data($parentSocket);
                if ($meta['timed_out'] ?? false) {
                    if ($deadline !== null && \hrtime(true) >= $deadline) {
                        // The grandchild is genuinely wedged, not merely slow
                        // (#58): default_socket_timeout retrying forever (the #32
                        // fix) has no upper bound of its own, so this is the one.
                        // Force it down and reap it so it never lingers as this
                        // worker's own zombie (the very failure #48 described).
                        $this->killAndReap($pid);
                        \fclose($parentSocket);

                        throw new \RuntimeException(
                            "rector call exceeded {$this->callTimeoutSeconds}s (--call-timeout); "
                            . 'the analysis was killed',
                        );
                    }
                    if ($daemonPid !== null && \hrtime(true) >= $nextOrphanCheckNs) {
                        // Same POSIX/Windows split as serveProcessWorker()'s own
                        // orphan check: a dead daemon's children (this worker,
                        // and the grandchild it just forked) are reparented at
                        // once, while the daemon itself can linger as a zombie
                        // its own parent has not reaped yet -- and a zombie
                        // still answers kill(pid, 0), so posix_getppid() is the
                        // reliable signal where it exists.
                        $orphaned = \function_exists('posix_getppid')
                            ? \posix_getppid() !== $daemonPid
                            : ProcessTree::isAlive($daemonPid) === false;
                        if ($orphaned) {
                            $this->killAndReap($pid);
                            \fclose($parentSocket);

                            throw new \RuntimeException(
                                'the daemon that started this warm worker is gone; '
                                . 'the in-flight analysis was killed (#127)',
                            );
                        }
                        $nextOrphanCheckNs = \hrtime(true) + self::ORPHAN_POLL_SECONDS * 1_000_000_000;
                    }
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
     * Keep a forked child's output off the daemon's stdio -- a stray PHP
     * warning during analysis must never land on the MCP/LSP protocol stream --
     * WITHOUT closing the STDIN/STDOUT/STDERR constants (#192): PHPStan reads
     * their values when it builds PhpStorm-stub reflection on a cache miss, and
     * a closed stream there throws "Invalid value". Every byte PHP's output
     * layer would write goes into a buffer that discards it (execute()'s own
     * ob_start() nests inside it, and a fatal's flush at shutdown lands here
     * too), and PHP's own error messages bound for fd 2 are switched off. Not
     * covered: code writing to STDOUT/STDERR or php://stdout directly. Used by
     * forkAndExecute()'s grandchild (as #193 introduced it) and by the #185
     * session child; a grandchild forked from the session applies it again.
     *
     * @param int $chunkSize 0 for the grandchild, which exits after one call; a
     *   size for the long-lived session child, so it discards stray output as it
     *   goes instead of holding it until it exits.
     */
    private static function silenceChildOutput(int $chunkSize = 0): void
    {
        \ob_start(static fn (): string => '', $chunkSize);
        \ini_set('log_errors', '0');
        if (\strtolower((string) \ini_get('display_errors')) === 'stderr') {
            \ini_set('display_errors', '0');
        }
    }

    // ------------------------------------------------------------------------
    // #185: the session child
    //
    // Forking every call from the pristine worker (#8) throws away everything a
    // call learns: on a large project most of a warm call is PHPStan reflecting
    // the same dependency classes, and project rules rebuilding the same
    // indexes, every time. The worker now forks ONE long-lived session child
    // and routes single-file analysis calls to it; the session analyses them
    // in-process and keeps those caches between calls. WarmSession decides when
    // that is safe: before each call it checks every input it has read (and
    // every project directory listing) and, on any change, exits so the worker
    // forks a fresh session from the still-pristine worker. Anything the
    // session cannot serve exactly as a cold run would -- a write call, a
    // directory, a file whose classes only it declares -- still forks from the
    // pristine worker, as before.
    // ------------------------------------------------------------------------

    /**
     * #185: one request, inside the worker. A single-file analysis call goes to
     * the session child when there is one it accepts; everything else -- a write
     * call, a directory, the session switched off, declined or lost -- forks
     * from the pristine worker exactly as before (#8).
     *
     * @param list<string> $argv
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    private function serveRequest(array $argv, bool $warmBoot, bool $dryRun, ?int $daemonPid): array
    {
        // One deadline for the whole call: a session attempt that falls back to
        // a fork must not restart the --call-timeout budget.
        $deadline = $dryRun ? $this->callDeadlineNs() : null;
        $path = $this->sessionCandidate($argv, $dryRun);
        if ($path !== null) {
            $result = $this->runInSession($argv, $warmBoot, $path, $daemonPid, $deadline);
            if ($result !== null) {
                return $result;
            }
        }

        return $this->forkAndExecute($argv, $warmBoot, $dryRun, $daemonPid, $deadline);
    }

    /**
     * The one existing file a call analyses, when the session may take it: a
     * dry run (#72: a write call is never routed away from the path that has
     * always guarded it) on exactly one file.
     *
     * @param list<string> $argv
     */
    private function sessionCandidate(array $argv, bool $dryRun): ?string
    {
        if (!$dryRun || $this->sessionUnavailable || self::sessionSwitchedOff()) {
            return null;
        }
        [$argv] = self::extractSkipAs($argv);
        $end = \array_search('--', $argv, true);
        if ($end === false) {
            return null;
        }
        $paths = \array_slice($argv, (int) $end + 1);
        if (\count($paths) !== 1 || !\is_file($paths[0])) {
            return null;
        }

        return $paths[0];
    }

    /** Off unless MCP_RECTOR_WARM_SESSION says on (PR #189 review: opt-in). */
    public static function sessionSwitchedOff(): bool
    {
        $value = \getenv(self::SESSION_ENV);

        return !\is_string($value) || !\in_array(\strtolower(\trim($value)), ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * MCP_RECTOR_WARM_SESSION_WATCH as absolute, '/'-separated paths: split on
     * PATH_SEPARATOR, blanks dropped, relative entries taken from $projectDirectory.
     * Not resolved: a declared file may not exist yet.
     *
     * @return list<string>
     */
    public static function sessionWatchPaths(string $projectDirectory): array
    {
        $value = \getenv(self::SESSION_WATCH_ENV);
        if (!\is_string($value) || \trim($value) === '') {
            return [];
        }
        $paths = [];
        foreach (\explode(\PATH_SEPARATOR, $value) as $entry) {
            $entry = Path::normalise(\trim($entry));
            if ($entry === '') {
                continue;
            }
            $absolute = \str_starts_with($entry, '/') || \preg_match('#^[A-Za-z]:/#', $entry) === 1;
            $path = $absolute ? $entry : \rtrim(Path::normalise($projectDirectory), '/') . '/' . $entry;
            $paths[] = \rtrim($path, '/') ?: '/';
        }

        return \array_values(\array_unique($paths));
    }

    /**
     * Why the session child should retire after the call it just served, or
     * null. PR #189 finding C: both bins set memory_limit to -1, so a share of
     * memory_limit alone never fired; the session has its own caps --
     * MCP_RECTOR_WARM_SESSION_MAX_MB of its own memory, MCP_RECTOR_WARM_SESSION_MAX_CALLS
     * calls -- and still honours 75% of a finite memory_limit.
     */
    public static function sessionRetireReason(int $calls, int $usageBytes, string $memoryLimit, int $maxMb, int $maxCalls): ?string
    {
        $mb = 1024 * 1024;
        if ($maxMb > 0 && $usageBytes > $maxMb * $mb) {
            return \sprintf('%d MB in use, over the %d MB cap', \intdiv($usageBytes, $mb), $maxMb);
        }
        if ($maxCalls > 0 && $calls >= $maxCalls) {
            return "served {$calls} calls, the cap";
        }
        $limit = \trim($memoryLimit);
        if ($limit !== '' && $limit !== '-1') {
            $bytes = (int) $limit * match (\strtolower(\substr($limit, -1))) {
                'g' => 1024 ** 3,
                'm' => $mb,
                'k' => 1024,
                default => 1,
            };
            if ($bytes > 0 && $usageBytes > $bytes * self::SESSION_MEMORY_SHARE) {
                return \sprintf('%d MB in use, over 75%% of memory_limit %s', \intdiv($usageBytes, $mb), $limit);
            }
        }

        return null;
    }

    private static function positiveIntEnv(string $name, int $default): int
    {
        $value = \getenv($name);

        return \is_string($value) && \ctype_digit(\trim($value)) ? (int) \trim($value) : $default;
    }

    /**
     * Serve one call through the session child, forking a fresh one when there
     * is none or the current one reports itself stale. Null when the call must
     * fork from the pristine worker instead -- the session declined it, or could
     * not be started, or died mid-call (a crash there is not trusted to be the
     * call's own fault; the fork answers it).
     *
     * @param list<string> $argv
     * @return array{exit_code: int, output: string, warm_boot: bool}|null
     */
    private function runInSession(array $argv, bool $warmBoot, string $path, ?int $daemonPid, ?int $deadline): ?array
    {
        $request = [
            'argv' => $argv,
            'warm_boot' => $warmBoot,
            'path' => $path,
            'buffer_copy' => self::extractSkipAs($argv)[1] !== null,
            'deadline_ns' => $deadline,
        ];
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            if ($this->sessionPid !== null && $this->sessionExited()) {
                $this->sessionLog('respawn', 'the session child exited between calls');
            }
            if ($this->sessionPid === null && !$this->spawnSession($deadline)) {
                $this->sessionLog('decline', 'no session child could be started');

                return null;
            }
            $reply = $this->askSession($request, $deadline, $daemonPid);
            if ($reply === null) {
                $this->killSession();
                $this->sessionLog('decline', 'the session child exited during the call; forked from the worker instead');

                return null;
            }
            $kind = $reply['kind'] ?? null;
            if ($kind === 'stale') {
                $this->reapSession();
                $this->sessionLog('respawn', (string) ($reply['reason'] ?? 'stale'));

                continue;
            }
            if ($kind === 'decline') {
                $this->sessionLog('decline', (string) ($reply['reason'] ?? ''));

                return null;
            }
            if ($kind === 'result' && \is_array($reply['result'] ?? null)) {
                $this->sessionLog(
                    ($reply['route'] ?? '') === WarmSession::FORK ? 'fork' : 'serve',
                    \sprintf(
                        'pid %d, call %d, %d files tracked',
                        (int) $this->sessionPid,
                        (int) ($reply['calls'] ?? 0),
                        (int) ($reply['tracked'] ?? 0),
                    ),
                );
                if (($reply['retire'] ?? false) === true) {
                    // The session exits by itself right after this reply.
                    $this->sessionLog('retire', (string) ($reply['retire_reason'] ?? ''));
                    $this->reapSession();
                }

                /** @var array{exit_code: int, output: string, warm_boot: bool} */
                return $reply['result'];
            }
            $this->killSession();
            $this->sessionLog('decline', 'unreadable reply from the session child');

            return null;
        }
        $this->sessionLog('decline', 'a freshly started session child was already stale');

        return null;
    }

    /**
     * Fork a session child from this (pristine) worker and wait for it to
     * report that its hooks are in place. The directory snapshot is refreshed
     * here, in the worker, so each new session inherits an up-to-date one.
     * False when no session can run: a hook that no longer fits this Rector or
     * PHPStan disables the session for this worker's lifetime (and says so on
     * stderr); a transient failure (fork, socket) only skips this call.
     */
    private function spawnSession(?int $deadline): bool
    {
        try {
            $this->sessionDirectories ??= (new SessionHooks($this->container))->directorySnapshot(
                (string) \getcwd(),
                self::sessionWatchPaths((string) \getcwd()),
            );
            $this->sessionDirectories->refresh();
        } catch (\Throwable $e) {
            $this->disableSession('cannot watch the project directories: ' . $e->getMessage());

            return false;
        }
        $workerPid = $this->currentPid();
        $sockets = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if ($workerPid === false || $sockets === false) {
            return false;
        }
        [$workerEnd, $sessionEnd] = $sockets;
        $pid = \pcntl_fork();
        if ($pid === -1) {
            \fclose($workerEnd);
            \fclose($sessionEnd);

            return false;
        }
        if ($pid === 0) {
            \fclose($workerEnd);
            $this->serveSession($sessionEnd, $workerPid);
            // serveSession() always exit()s; this line is unreachable.
        }
        \fclose($sessionEnd);
        \stream_set_timeout($workerEnd, 1);
        try {
            $handshake = $this->readFrame($workerEnd, $deadline);
        } catch (RectorCallTimeoutException) {
            $this->killAndReap($pid);
            \fclose($workerEnd);

            throw new \RuntimeException(
                "rector call exceeded {$this->callTimeoutSeconds}s (--call-timeout); the analysis was killed",
            );
        }
        $decoded = $handshake === null ? null : \json_decode($handshake, true);
        if (!\is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            \fclose($workerEnd);
            $this->killAndReap($pid);
            $this->disableSession(\is_array($decoded) ? (string) ($decoded['error'] ?? 'unknown error') : 'no handshake');

            return false;
        }
        $this->sessionPid = $pid;
        $this->sessionSocket = $workerEnd;
        $this->sessionLog('spawn', \sprintf(
            'pid %d, %d files and %d directories tracked',
            $pid,
            (int) ($decoded['tracked'] ?? 0),
            (int) ($decoded['directories'] ?? 0),
        ));

        return true;
    }

    /**
     * Send one request to the session child and wait for its reply, under the
     * call's deadline, polling the daemon's liveness the way forkAndExecute()'s
     * own wait loop does (#127). Null when the child is gone (EOF, or the write
     * failed). Throws, after killing the child, on the deadline or a dead daemon
     * -- with forkAndExecute()'s own messages, so every caller and test that
     * recognises those kill sites recognises this one.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function askSession(array $request, ?int $deadline, ?int $daemonPid): ?array
    {
        \assert($this->sessionSocket !== null);
        try {
            $this->writeFrame($this->sessionSocket, (string) \json_encode($request, \JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (\RuntimeException) {
            return null;
        }
        $orphaned = false;
        $nextOrphanCheckNs = \hrtime(true) + self::ORPHAN_POLL_SECONDS * 1_000_000_000;
        $onIdle = static function () use ($daemonPid, &$orphaned, &$nextOrphanCheckNs): void {
            if ($daemonPid === null || \hrtime(true) < $nextOrphanCheckNs) {
                return;
            }
            $orphaned = \function_exists('posix_getppid')
                ? \posix_getppid() !== $daemonPid
                : ProcessTree::isAlive($daemonPid) === false;
            if ($orphaned) {
                throw new \RuntimeException('orphaned');
            }
            $nextOrphanCheckNs = \hrtime(true) + self::ORPHAN_POLL_SECONDS * 1_000_000_000;
        };
        try {
            $raw = $this->readFrame($this->sessionSocket, $deadline, $onIdle);
        } catch (RectorCallTimeoutException) {
            $this->killSession();

            throw new \RuntimeException(
                "rector call exceeded {$this->callTimeoutSeconds}s (--call-timeout); the analysis was killed",
            );
        } catch (\RuntimeException $e) {
            if (!$orphaned) {
                throw $e;
            }
            $this->killSession();

            throw new \RuntimeException(
                'the daemon that started this warm worker is gone; the in-flight analysis was killed (#127)',
            );
        }
        if ($raw === null) {
            return null;
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : ['kind' => 'unreadable'];
    }

    /**
     * The session child's main loop -- see the section comment above and
     * WarmSession. Like serveWorker(), it never returns: whatever happens, the
     * process exits here and never unwinds into the worker's call stack (#133).
     *
     * @param resource $socket
     */
    private function serveSession($socket, int $workerPid): void
    {
        $exitCode = 0;
        try {
            // The daemon must read EOF from a dead worker even while this child
            // lives, and nothing here may ever write to the daemon's stdio (the
            // std constants themselves stay open, #192).
            if (\is_resource($this->workerDaemonSocket)) {
                @\fclose($this->workerDaemonSocket);
            }
            $this->workerDaemonSocket = null;
            self::silenceChildOutput(4096);
            try {
                $hooks = new SessionHooks($this->container);
                $session = new WarmSession(
                    $hooks,
                    new DependencyFileTracker(),
                    $this->sessionDirectories,
                    \get_included_files(),
                    $hooks->autoloadPathList(),
                    $hooks->cacheDirectories(),
                );
                $hooks->installParserHook(static function (string $file) use ($session): void {
                    $session->recordRead($file);
                });
                foreach ($hooks->staticInputFiles() as $file) {
                    $session->recordRead($file);
                }
                // Inputs a custom rule reads itself, declared by the user.
                foreach (WarmSession::watchedFiles(self::sessionWatchPaths((string) \getcwd())) as $file) {
                    $session->recordRead($file);
                }
            } catch (\Throwable $e) {
                $this->writeFrame($socket, $this->encodeHandshakeFrame(['ok' => false, 'error' => $e->getMessage()]));
                $exitCode = 1;

                return;
            }
            $this->writeFrame($socket, $this->encodeHandshakeFrame([
                'ok' => true,
                'tracked' => $session->trackedFiles(),
                'directories' => $this->sessionDirectories?->count() ?? 0,
            ]));

            while (true) {
                $frame = $this->readFrame($socket);
                if ($frame === null) {
                    break;
                }
                $request = \json_decode($frame, true);
                $argv = \is_array($request) && \is_array($request['argv'] ?? null) ? $request['argv'] : [];
                $path = \is_array($request) ? (string) ($request['path'] ?? '') : '';
                $warmBoot = \is_array($request) && ($request['warm_boot'] ?? false) === true;
                $bufferCopy = \is_array($request) && ($request['buffer_copy'] ?? false) === true;
                $deadline = \is_array($request) && \is_int($request['deadline_ns'] ?? null) ? $request['deadline_ns'] : null;

                $stale = $session->staleReason();
                if ($stale !== null) {
                    $this->writeFrame($socket, $this->encodeForkResult(['kind' => 'stale', 'reason' => $stale]));

                    break;
                }
                try {
                    [$route, $reason] = $session->route($path, $bufferCopy);
                } catch (\Throwable $e) {
                    [$route, $reason] = [WarmSession::DECLINE, 'could not inspect the file: ' . $e->getMessage()];
                }
                if ($route === WarmSession::DECLINE) {
                    $this->writeFrame($socket, $this->encodeForkResult(['kind' => 'decline', 'reason' => $reason]));

                    continue;
                }
                if ($route === WarmSession::FORK) {
                    // Warm caches inherited, and whatever the buffer adds dies
                    // with the grandchild. Its orphan check watches this
                    // session's own parent, the worker.
                    try {
                        $result = $this->forkAndExecute($argv, $warmBoot, true, $workerPid, $deadline);
                    } catch (\Throwable $e) {
                        $result = ['error' => $e->getMessage(), 'error_class' => $e::class];
                    }
                    $this->writeFrame($socket, $this->encodeForkResult([
                        'kind' => 'result',
                        'route' => WarmSession::FORK,
                        'result' => $result,
                        'calls' => $session->calls(),
                        'tracked' => $session->trackedFiles(),
                    ]));

                    continue;
                }

                $retireReason = null;
                try {
                    $result = $this->execute($argv, $warmBoot);
                } catch (\Throwable $e) {
                    // A throw out of Rector may leave half-built state behind:
                    // report it, then let the next call start a fresh session.
                    $result = ['error' => $e->getMessage(), 'error_class' => $e::class];
                    $retireReason = 'the call threw ' . $e::class;
                }
                $session->afterInSessionCall($path);
                $retireReason ??= self::sessionRetireReason(
                    $session->calls(),
                    \memory_get_usage(true),
                    (string) \ini_get('memory_limit'),
                    self::positiveIntEnv(self::SESSION_MAX_MB_ENV, self::SESSION_DEFAULT_MAX_MB),
                    self::positiveIntEnv(self::SESSION_MAX_CALLS_ENV, self::SESSION_DEFAULT_MAX_CALLS),
                );
                $retire = $retireReason !== null;
                $this->writeFrame($socket, $this->encodeForkResult([
                    'kind' => 'result',
                    'route' => WarmSession::SERVE,
                    'result' => $result,
                    'retire' => $retire,
                    'retire_reason' => $retireReason,
                    'calls' => $session->calls(),
                    'tracked' => $session->trackedFiles(),
                ]));
                if ($retire) {
                    break;
                }
            }
        } catch (\Throwable) {
            $exitCode = 1;
        } finally {
            @\fclose($socket);
            exit($exitCode);
        }
    }

    /**
     * True, with the child reaped and forgotten, when the session child is no
     * longer running.
     */
    private function sessionExited(): bool
    {
        if ($this->sessionPid === null) {
            return false;
        }
        $status = 0;
        if (\pcntl_waitpid($this->sessionPid, $status, \WNOHANG) === 0) {
            return false;
        }
        $this->forgetSessionSocket();
        $this->sessionPid = null;

        return true;
    }

    /** Wait for a session child that is exiting on its own (stale, retired). */
    private function reapSession(): void
    {
        $this->forgetSessionSocket();
        if ($this->sessionPid !== null) {
            $status = 0;
            \pcntl_waitpid($this->sessionPid, $status);
        }
        $this->sessionPid = null;
    }

    /** SIGKILL and reap the session child (and whatever it forked). */
    private function killSession(): void
    {
        $this->forgetSessionSocket();
        if ($this->sessionPid !== null) {
            $this->killAndReap($this->sessionPid);
        }
        $this->sessionPid = null;
    }

    /** The worker is exiting: its session child goes with it. */
    private function stopSession(): void
    {
        $this->killSession();
    }

    private function forgetSessionSocket(): void
    {
        if (\is_resource($this->sessionSocket)) {
            @\fclose($this->sessionSocket);
        }
        $this->sessionSocket = null;
    }

    private function disableSession(string $reason): void
    {
        $this->sessionUnavailable = true;
        $this->sessionLog('disable', $reason . '; every call forks from the warm worker instead');
    }

    /**
     * One line on the worker's stderr: `mcp-rector-warm: session <event>: <detail>`.
     * Every call the session was asked about logs exactly one terminal event --
     * serve (analysed in the session), fork (in a child forked from it), or
     * decline (forked from the pristine worker) -- after `respawn` when the
     * previous session went stale and `spawn` when a new one started. Only with
     * MCP_RECTOR_WARM_SESSION_LOG set, except `disable`, which is always worth
     * knowing: the session is off for the rest of this worker's life.
     */
    private function sessionLog(string $event, string $detail): void
    {
        $enabled = $event === 'disable'
            || \in_array(\strtolower(\trim((string) \getenv(self::SESSION_LOG_ENV))), ['1', 'true', 'yes', 'on'], true);
        if ($enabled && \defined('STDERR') && \is_resource(\STDERR)) {
            @\fwrite(\STDERR, "mcp-rector-warm: session {$event}: {$detail}\n");
        }
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
     * @param bool $dryRun #72 correction: the poll-and-kill deadline below
     *   applies ONLY when $dryRun is true -- a dryRun:false call must never
     *   have this subprocess proc_terminate()d by --call-timeout, so no
     *   deadline is computed for it (falls back to plain proc_close(), the
     *   pre-#58 behaviour).
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    protected function runCold(array $argv, bool $dryRun = true): array
    {
        // Cold is never warm by definition -- there is no worker to have been
        // pre-booted before this call started.
        $this->lastCallWasWarm = false;
        // Both the result (#46) and the child's stdout/stderr (#45) travel through
        // temp files, never through OS pipes. A pipe has a small, fixed OS buffer:
        // reading two of them one after another (the original code) deadlocks the
        // moment either pipe fills before the parent gets to it, because the child
        // then blocks writing while the parent blocks reading the other one (#45).
        // stream_select() can drain multiple pipes concurrently to avoid that -- but
        // stream_select() over proc_open() pipes is documented not to work on
        // Windows at all ("Use of stream_select() on file descriptors returned by
        // proc_open() will fail and return false under Windows", php.net), and
        // Windows is this fallback's own stated reason to exist (no pcntl, ever).
        // A file has no such bounded buffer: proc_open()'s own 'file' descriptor
        // type lets the child write directly to a file, sidestepping the whole
        // pipe-buffer-deadlock class rather than working around it, identically on
        // every platform. Rector's own console writes (SymfonyStyle/ConsoleOutput)
        // bypass ob_start() and display_errors and land straight on the real fd 1,
        // so json_decode()ing stdout as the result (the original bug behind #46)
        // is still wrong even once #45 is fixed -- the result never shares a
        // channel with anything Rector itself may write.
        $resultFile = \tempnam(\sys_get_temp_dir(), 'rector-cold-result-');
        $stdoutFile = \tempnam(\sys_get_temp_dir(), 'rector-cold-stdout-');
        $stderrFile = \tempnam(\sys_get_temp_dir(), 'rector-cold-stderr-');
        if ($resultFile === false || $stdoutFile === false || $stderrFile === false) {
            throw new \RuntimeException('Could not allocate temp files for the cold rector subprocess channels.');
        }

        try {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutFile, 'w'],
                2 => ['file', $stderrFile, 'w'],
            ];
            $command = [\PHP_BINARY, \dirname(__DIR__) . '/bin/rector-cold-call.php'];
            $process = \proc_open($command, $descriptors, $pipes);
            if (!\is_resource($process)) {
                throw new \RuntimeException('Could not spawn a cold rector subprocess (no-pcntl fallback, #31).');
            }

            // #74 follow-up: same missing-flag pattern as the boot handshake
            // frames -- see encodeHandshakeFrame().
            $request = (string) \json_encode(
                [
                    'daemon_argv' => $_SERVER['argv'] ?? [],
                    'call_argv' => $argv,
                    'cwd' => \getcwd(),
                    'result_file' => $resultFile,
                ],
                \JSON_INVALID_UTF8_SUBSTITUTE,
            );
            \fwrite($pipes[0], $request);
            \fclose($pipes[0]);

            // #58: the no-pcntl fallback (this whole method) had no deadline at
            // all -- proc_close() blocks unconditionally until the subprocess
            // exits, so a genuinely wedged cold call hung the daemon forever,
            // same class of regression as the two pcntl-path wait loops above,
            // just with no retry-on-timeout to even add a check to: a single
            // blocking call. Poll proc_get_status() against the same deadline
            // instead when one is configured; proc_close() alone (today's
            // pre-#58 behaviour) when $callTimeoutSeconds == 0, or when
            // $dryRun is false (#72 correction: a write call is never killed).
            $deadline = $dryRun ? $this->callDeadlineNs() : null;
            if ($deadline === null) {
                $exitCode = \proc_close($process);
            } else {
                $exitCode = null;
                while (true) {
                    $procStatus = \proc_get_status($process);
                    if ($procStatus === false || !$procStatus['running']) {
                        // #73: proc_get_status()'s exitcode field is only valid the
                        // FIRST time it is read after the child has exited -- exactly
                        // this call, since the loop breaks right here -- and on POSIX
                        // PHP < 8.3, proc_close() returns -1 once that exit status has
                        // already been consumed by an earlier proc_get_status() call
                        // (PHP's own long-standing proc_close()/proc_get_status()
                        // interaction, fixed upstream in PHP 8.3). Prefer the real
                        // code observed here; proc_close()'s own return value is only
                        // a fallback for the $procStatus === false case, where no
                        // exit code was ever observed at all.
                        $closeExitCode = \proc_close($process);
                        $exitCode = $procStatus !== false ? $procStatus['exitcode'] : $closeExitCode;
                        break;
                    }
                    if (\hrtime(true) >= $deadline) {
                        // Signal 9 (SIGKILL), NOT the \SIGKILL constant: this whole
                        // branch is the fallback for when pcntl is unavailable
                        // (Windows, or #18's disable_functions case) -- the ONE
                        // platform band this code exists for -- and \SIGKILL is
                        // defined by the pcntl extension, not by core PHP or by
                        // proc_open()'s own family of functions. Referencing it
                        // here would throw "Undefined constant SIGKILL" on exactly
                        // the builds this fallback is for, in the one branch meant
                        // to make a wedged call fail cleanly instead of hanging.
                        // The literal is safe everywhere: POSIX assigns 9 to
                        // SIGKILL universally, and proc_terminate()'s signal
                        // parameter is documented as ignored on Windows (it calls
                        // TerminateProcess() instead), so passing 9 there is a
                        // harmless no-op rather than a platform mismatch. Not
                        // asking nicely first (SIGTERM, proc_terminate()'s own
                        // default) for the same reason the pcntl paths above go
                        // straight to a hard kill: a wedged process is, by
                        // definition, not responding to signals it could choose to
                        // handle.
                        // #112: the whole tree first. proc_terminate() reaches
                        // only the direct child; Rector's parallel workers
                        // (spawned by it) kept running as orphans, and on
                        // Windows kept the call's working directory locked.
                        // `taskkill /T` must see the root alive to find the
                        // tree, hence before proc_terminate(), never after.
                        ProcessTree::killTree((int) $procStatus['pid']);
                        \proc_terminate($process, 9);
                        \proc_close($process);

                        throw new \RuntimeException(
                            "rector call exceeded {$this->callTimeoutSeconds}s (--call-timeout); "
                            . 'the cold rector subprocess was killed',
                        );
                    }
                    \usleep(100_000);
                }
            }

            $resultJson = \is_file($resultFile) ? \file_get_contents($resultFile) : false;
            $decoded = $resultJson === false || $resultJson === '' ? null : \json_decode($resultJson, true);
            if (!\is_array($decoded) || isset($decoded['error'])) {
                $stderr = (string) \file_get_contents($stderrFile);
                $consoleOutput = (string) \file_get_contents($stdoutFile);
                $diagnostic = \trim($stderr . ($consoleOutput !== '' ? \PHP_EOL . $consoleOutput : ''));
                $message = \is_array($decoded) && isset($decoded['error'])
                    ? (string) $decoded['error']
                    : "cold rector subprocess produced no output (exit {$exitCode}): " . $diagnostic;
                throw new \RuntimeException($message);
            }

            /** @var array{exit_code: int, output: string, warm_boot: bool} $decoded */
            return $decoded;
        } finally {
            foreach ([$resultFile, $stdoutFile, $stderrFile] as $tempFile) {
                if (\is_string($tempFile) && \is_file($tempFile)) {
                    @\unlink($tempFile);
                }
            }
        }
    }

    // ------------------------------------------------------------------ #108
    // Warm path without pcntl: a worker PROCESS instead of a forked worker.

    /** Env var selecting the no-pcntl strategy (#108). Unset = standby. */
    public const NO_PCNTL_MODE_ENV = 'MCP_RECTOR_WARM_NO_PCNTL';

    /** Default: one pre-booted worker process per call, each serving exactly one call. */
    public const NO_PCNTL_MODE_STANDBY = 'standby';

    /** Escape hatch: the pre-#108 cold `php` subprocess per call (runCold()). */
    public const NO_PCNTL_MODE_COLD = 'cold';

    /** Recorded for a bootstrap file modified while the container booted: matches no
     *  real hash, so the next check always reboots. See resolveBootstrapFileHashes(). */
    private const BOOT_RACE_HASH = 'modified-during-boot';

    /** serveProcessWorker()'s exit code when its daemon died while it waited. */
    public const WORKER_EXIT_ORPHANED = 3;

    /** How often an idle standby checks that its daemon is alive. 2s, not less: on
     *  Windows each check starts a `tasklist`. #127: also throttles
     *  forkAndExecute()'s orphan check while a call is IN FLIGHT on the pcntl
     *  warm-worker path -- not only an idle standby's poll any more. #134: also
     *  the poll interval bin/rector-warm-orphan-watchdog.php is spawned with
     *  (spawnOrphanWatchdog() passes it as that script's own argv), the same
     *  idea's third consumer -- the no-pcntl path's own in-call orphan check. */
    private const ORPHAN_POLL_SECONDS = 2;

    /** The no-pcntl worker this instance talks to, or null.
     *  @var array{proc: resource, pid: int, server: resource|null, socket: resource|null, token: string, stderr: string, ready: bool}|null */
    private ?array $procWorker = null;

    /** Workers that served their one call and are exiting on their own; reaped lazily
     *  so a call never waits on a process's shutdown.
     *  @var list<array{proc: resource, stderr: string}> */
    private array $retiredProcWorkers = [];

    /** Resolve the no-pcntl strategy. Overridable so tests can pin one. */
    protected function noPcntlMode(): string
    {
        $mode = \getenv(self::NO_PCNTL_MODE_ENV);

        return \is_string($mode) && \strtolower(\trim($mode)) === self::NO_PCNTL_MODE_COLD
            ? self::NO_PCNTL_MODE_COLD
            : self::NO_PCNTL_MODE_STANDBY;
    }

    /**
     * #108: the no-pcntl warm path. Without fork there is no copy-on-write snapshot of a
     * booted container to run each call in, and running a second call in a container a
     * first call already used is NOT equivalent to cold: measured in #108 with one
     * long-lived worker process serving every call, 25 of the 55 E2E oracle scenarios
     * diverged -- a dependency's edited method signature still answered with the old
     * type (#8's own bug), and even a second, unedited file in the same container was
     * reported unchanged when cold changes it. So each call still gets a container
     * nothing was analysed in, exactly like the forked grandchild: what moves is WHEN it
     * boots. A standby worker process is started right after a call returns and boots
     * while the caller is busy elsewhere; the next call finds it booted and only pays for
     * the analysis. The worker serves that one call and exits.
     *
     * @param list<string> $argv
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    protected function runViaStandbyWorker(array $argv, bool $dryRun): array
    {
        $this->reapRetiredProcWorkers();

        // warm_boot: this call is served by a worker booted before it started
        // (the standby a previous call left behind), not one booted on demand.
        $warmBoot = $this->procWorker !== null;
        if ($warmBoot) {
            try {
                // Normally already booted; a burst of calls may find it still
                // booting, and then waits for the rest of the boot only.
                $this->awaitProcWorkerReady($this->bootDeadlineNs());
            } catch (\Throwable) {
                // A standby that died or failed to boot is not this call's error:
                // boot a fresh one below, which reports its own failure if any.
                $this->discardProcWorker(true);
                $warmBoot = false;
            }
        }
        if ($warmBoot && !$this->procWorkerAlive()) {
            $this->discardProcWorker(false);
            $warmBoot = false;
        }
        if ($warmBoot && $this->configFileChanged()) {
            // Compared against the snapshot THIS worker took before it booted
            // (awaitProcWorkerReady() stored its handshake), so an edit that raced
            // its boot always forces a fresh one (#20/#33/#34).
            $this->discardProcWorker(false);
            $warmBoot = false;
        }
        if (!$warmBoot) {
            $this->spawnProcWorker();
            try {
                $this->awaitProcWorkerReady($this->bootDeadlineNs());
            } catch (\Throwable $e) {
                $this->discardProcWorker(true);
                throw $e;
            }
        }

        try {
            return $this->callProcWorker($argv, $warmBoot, $dryRun);
        } finally {
            // Still set unless the call killed it: its container has now analysed
            // something (or failed trying), so retire it -- it exits on its own after
            // its one call -- and start the next call's standby now, so that boot
            // overlaps the caller's think time.
            if ($this->procWorker !== null) {
                $this->retireProcWorker();
                try {
                    $this->spawnProcWorker();
                } catch (\Throwable) {
                    // No standby: the next call boots on demand instead.
                    $this->procWorker = null;
                }
            }
        }
    }

    /**
     * Start a worker process (bin/rector-warm-worker.php) without waiting for it. It
     * connects back over loopback TCP -- not a pipe: stream_set_timeout() and a
     * non-blocking wait on proc_open() pipes do not work on Windows, which is the
     * platform this path exists for, while sockets behave the same everywhere.
     */
    /** @var array<string, array{global_value: string|null, local_value: string|null}>|null
     *  Cached across the whole daemon process lifetime -- see loadPristineIniBaseline(). */
    private static ?array $pristineIniBaseline = null;

    /**
     * A fresh `php -n` process's own ini_get_all(null, true), memoised for this daemon
     * process lifetime. -n loads no php.ini and no scanned ini directory -- the ONLY way
     * to see PHP's true compiled-in defaults, unaffected by anything the daemon was
     * actually invoked with. Spawned once (this is a real subprocess + PHP interpreter
     * start, not something to pay on every call) and cached, since it never changes for
     * the life of the daemon.
     *
     * @return array<string, array{global_value: string|null, local_value: string|null}>
     */
    private static function loadPristineIniBaseline(): array
    {
        if (self::$pristineIniBaseline !== null) {
            return self::$pristineIniBaseline;
        }
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']];
        $proc = @\proc_open(
            [\PHP_BINARY, '-n', '-r', 'echo json_encode(ini_get_all(null, true));'],
            $descriptors,
            $pipes,
        );
        if (!\is_resource($proc)) {
            return self::$pristineIniBaseline = [];
        }
        $out = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \proc_close($proc);
        $decoded = \is_string($out) ? \json_decode($out, true) : null;

        /** @var array<string, array{global_value: string|null, local_value: string|null}> $decoded */
        return self::$pristineIniBaseline = \is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether $current (this process's active value for one directive) has to be
     * forwarded as a -d flag: it differs from $default, that directive's TRUE
     * compiled-in default from loadPristineIniBaseline(). This is the check #125's
     * original version got wrong (comparing local_value against THIS process's own
     * global_value): PHP's CLI SAPI folds a real -d flag into BOTH local_value AND
     * global_value from process start, so the two never diverge for exactly the case
     * this method exists to catch -- confirmed empirically (`php -d precision=15 -r
     * '...'` reports global_value === local_value === "15", both stuck at the
     * override, not the compiled default of "14"). A baseline $default of null (a
     * directive with no string default at all, or one loadPristineIniBaseline()
     * could not see -- e.g. one only an extension not loaded under `-n` provides)
     * must never read as "unchanged": only a STRING default that equals $current
     * skips forwarding.
     */
    private static function isIniOverridden(?string $default, ?string $current): bool
    {
        if ($current === null) {
            return false; // nothing to forward
        }

        return !(\is_string($default) && $default === $current);
    }

    /**
     * The -d ini overrides given to the daemon at startup, reconstructed for
     * re-passing to a brand-new proc_open() child (#125). Unlike pcntl_fork()
     * (this file, the other worker path), which clones the SAME process image --
     * ini overrides included -- for free, the PHP CLI SAPI consumes -d flags before
     * $_SERVER["argv"] is even populated, so they are simply gone by the time this
     * process can inspect its own argv; there is also no portable way to read back
     * the daemon original command line (no /proc on Windows, the platform this
     * whole no-pcntl path exists for).
     *
     * @return list<string>
     */
    private static function collectIniOverrideArgs(): array
    {
        return self::collectIniOverrideArgsFrom(\ini_get_all(null, true));
    }

    /**
     * Split out from collectIniOverrideArgs() so a test can force the
     * ini_get_all()-failed branch directly, without needing the real builtin
     * itself to return false (#156: no concrete trigger for that has been
     * constructed in this daemon's own runtime -- plain CLI SAPI, no
     * extension-name argument passed). $iniAll is exactly what
     * \ini_get_all(null, true) returns: an array of directives, or false when
     * it could not enumerate them at all -- previously collapsed by the
     * caller's own `?: []` into "nothing to forward", indistinguishable from
     * the genuinely-empty case.
     *
     * @param array<string, array<string, mixed>>|false $iniAll
     * @return list<string>
     */
    private static function collectIniOverrideArgsFrom($iniAll): array
    {
        if ($iniAll === false) {
            // Fail open (still forward nothing): a worker that boots with no
            // -d overrides is better than one that fails to boot at all, but
            // this must never look identical to the genuinely-empty case.
            if (\defined('STDERR') && \is_resource(\STDERR)) {
                @\fwrite(\STDERR, 'mcp-rector-warm: ini_get_all() could not enumerate ini directives; '
                    . "no -d overrides will be forwarded to this warm worker (#156)\n");
            }
            $iniAll = [];
        }
        $baseline = self::loadPristineIniBaseline();
        $args = [];
        foreach ($iniAll as $name => $info) {
            if (!\is_array($info)) {
                continue;
            }
            $current = $info['local_value'] ?? null;
            if (!\is_string($current)) {
                continue;
            }
            $default = $baseline[$name]['local_value'] ?? null;
            if (!self::isIniOverridden(\is_string($default) ? $default : null, $current)) {
                continue;
            }
            $args[] = '-d';
            $args[] = $name . '=' . self::quoteIniValue($current);
        }

        return $args;
    }

    /**
     * Quote a -d value the way PHP own ini-value parser needs it, not the way a
     * shell would: -d values go through the identical parser php.ini itself uses,
     * where an unquoted value ends at the first "reserved" character (confirmed
     * empirically: an unquoted -d user_agent=Mozilla/5.0 (X11; Linux) silently
     * truncates to "Mozilla/5.0 " with no forwarding error at all -- caught by an
     * independent end-to-end review). Wrapping disables that special-character
     * handling entirely, so this always wraps, never only when a "risky"
     * character is spotted.
     *
     * Single-quoted raw INI (name='value') is preferred whenever the value has
     * no single quote in it: unlike a double-quoted INI string, a single-quoted
     * one is never subject to `${OTHER_DIRECTIVE}`-style interpolation by PHP's
     * own ini parser, and it has no escape processing at all, so a value ending
     * in a backslash (or containing one anywhere) round-trips unchanged with no
     * escaping needed. That is exactly what a forwarded literal value needs: an
     * independent E2E review of #129 found that a literal `${VAR}` inside a
     * forwarded double-quoted value gets silently expanded by the standby
     * worker's own INI parser rather than passed through as the literal
     * four-character sequence it was in the parent daemon (#130).
     *
     * A value containing a literal single quote cannot wrap the WHOLE value in
     * single quotes (there is no escape for a quote inside single quotes), and
     * falling back to one double-quoted whole-value form for that case -- #125's
     * original fallback -- reintroduces ${...} interpolation for the rest of a
     * value that also contains a single quote: "it's ${HOME}" reached the worker
     * as "it's /Users/..." (#135, split out of an independent E2E review of #133).
     * PHP's own ini parser accepts several adjacent quoted segments with nothing
     * between them as one concatenated value (confirmed empirically: name='a'"'"'b'
     * parses to a'b, matching how a shell itself lets 'a'"'"'b' mean a'b), so each
     * literal single quote in $value becomes its own one-character double-quoted
     * segment -- trivially safe, since a lone quote needs no backslash/quote
     * escaping there -- and every run BETWEEN quotes, which may still contain
     * ${...} or a backslash, stays single-quoted: never interpolated, never
     * escape-processed, exactly like the no-quote case above.
     */
    private static function quoteIniValue(string $value): string
    {
        if (!\str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        $parts = \explode("'", $value);
        $lastIndex = \count($parts) - 1;
        $segments = [];
        foreach ($parts as $i => $part) {
            if ($part !== '') {
                $segments[] = "'" . $part . "'";
            }
            if ($i < $lastIndex) {
                $segments[] = '"\'"';
            }
        }

        return \implode('', $segments);
    }

    private function spawnProcWorker(): void
    {
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException("Could not listen on loopback for the warm worker process: {$errstr}");
        }
        $address = (string) \stream_socket_get_name($server, false);
        $token = \bin2hex(\random_bytes(16));
        $stderrFile = \tempnam(\sys_get_temp_dir(), 'rector-warm-worker-stderr-');
        if ($stderrFile === false) {
            \fclose($server);
            throw new \RuntimeException('Could not allocate a temp file for the warm worker process stderr.');
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            // Never the daemon's own stdout: that is the MCP/LSP transport.
            1 => ['file', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];
        $proc = \proc_open(
            [\PHP_BINARY, ...self::collectIniOverrideArgs(), \dirname(__DIR__) . '/bin/rector-warm-worker.php'],
            $descriptors,
            $pipes,
        );
        if (!\is_resource($proc)) {
            \fclose($server);
            @\unlink($stderrFile);
            throw new \RuntimeException('Could not spawn the warm worker process (no-pcntl path, #108).');
        }
        $ownPid = $this->currentPid();
        if ($ownPid === false) {
            $this->logGetmypidFailure('the standby worker will not receive a daemon pid to watch, disabling #127-style orphan detection there too');
        }
        \fwrite($pipes[0], (string) \json_encode(
            [
                'daemon_argv' => $_SERVER['argv'] ?? [],
                'cwd' => \getcwd(),
                'address' => $address,
                'token' => $token,
                'daemon_pid' => $ownPid,
                'stderr_file' => $stderrFile,
            ],
            \JSON_INVALID_UTF8_SUBSTITUTE,
        ));
        \fclose($pipes[0]);
        $status = \proc_get_status($proc);

        $this->procWorker = [
            'proc' => $proc,
            'pid' => (int) $status['pid'],
            'server' => $server,
            'socket' => null,
            'token' => $token,
            'stderr' => $stderrFile,
            'ready' => false,
        ];
    }

    /**
     * Wait until the current worker process has connected back (and proved it is ours
     * with the token) and sent its boot handshake, then adopt the config snapshot the
     * handshake carries. Idempotent once ready.
     */
    private function awaitProcWorkerReady(?int $deadlineNs): void
    {
        \assert($this->procWorker !== null);
        if ($this->procWorker['ready']) {
            return;
        }
        $timedOut = fn (): bool => $deadlineNs !== null && \hrtime(true) >= $deadlineNs;

        while ($this->procWorker['socket'] === null) {
            // Checked on EVERY pass, not only when nobody connected: a local process
            // that keeps connecting must not keep this wait alive past the deadline.
            if ($timedOut()) {
                throw new \RuntimeException(
                    "rector call exceeded its configured --call-timeout ({$this->callTimeoutSeconds}s) before the "
                    . 'warm worker finished booting; the worker was killed',
                );
            }
            $server = $this->procWorker['server'];
            \assert($server !== null);
            $connection = @\stream_socket_accept($server, 1);
            if ($connection !== false) {
                // Loopback is reachable by any local process: only a peer that
                // sends the token first is our worker. Fixed length, so a stranger
                // cannot make this read allocate more; and at most 5s, never past
                // the call's own deadline.
                \stream_set_timeout($connection, 1);
                $expected = \pack('N', 32) . $this->procWorker['token'];
                $helloDeadline = \hrtime(true) + 5_000_000_000;
                if ($deadlineNs !== null) {
                    $helloDeadline = \min($helloDeadline, $deadlineNs);
                }
                try {
                    $hello = $this->readExactly($connection, \strlen($expected), $helloDeadline);
                } catch (RectorCallTimeoutException) {
                    $hello = null;
                }
                if ($hello !== null && \hash_equals($expected, $hello)) {
                    $this->procWorker['socket'] = $connection;
                    \fclose($server);
                    $this->procWorker['server'] = null;
                    break;
                }
                \fclose($connection);
                continue;
            }
            if (!$this->procWorkerAlive()) {
                throw new \RuntimeException('the warm worker process exited before it connected: ' . $this->procWorkerStderrTail());
            }
        }

        $socket = $this->procWorker['socket'];
        \stream_set_timeout($socket, 1);
        try {
            $handshake = $this->readFrame($socket, $deadlineNs);
        } catch (RectorCallTimeoutException) {
            throw new \RuntimeException(
                "rector call exceeded its configured --call-timeout ({$this->callTimeoutSeconds}s) before the "
                . 'warm worker finished booting; the worker was killed',
            );
        }
        $decoded = $handshake === null ? null : \json_decode($handshake, true);
        if (!\is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new \RuntimeException(
                \is_array($decoded) && isset($decoded['error'])
                    ? (string) $decoded['error']
                    : 'the warm worker process failed to boot: ' . $this->procWorkerStderrTail(),
            );
        }

        $this->procWorker['ready'] = true;
        $this->configFile = \is_string($decoded['config_file'] ?? null) ? $decoded['config_file'] : null;
        $this->configFileHash = \is_string($decoded['config_file_hash'] ?? null) ? $decoded['config_file_hash'] : null;
        $this->composerFile = \is_string($decoded['composer_file'] ?? null) ? $decoded['composer_file'] : null;
        $this->composerFileHash = \is_string($decoded['composer_file_hash'] ?? null) ? $decoded['composer_file_hash'] : null;
        $this->bootstrapFileHashes = \is_array($decoded['bootstrap_files'] ?? null) ? $decoded['bootstrap_files'] : [];
    }

    /**
     * Send one call to the ready worker process and wait for its result. One deadline
     * layer only: the analysis runs in the worker's own process, so there is no inner
     * kill site to give a head start to (unlike runForked()'s grace period).
     *
     * @param list<string> $argv
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    private function callProcWorker(array $argv, bool $warmBoot, bool $dryRun): array
    {
        // Captured here, before anything below (a --call-timeout kill, a closed
        // connection) can discard $this->procWorker: isWarm() alone cannot tell an
        // error payload built after such a discard whether THIS call was warm (#126).
        $this->lastCallWasWarm = $warmBoot;
        \assert($this->procWorker !== null && $this->procWorker['socket'] !== null);
        $socket = $this->procWorker['socket'];
        $payload = (string) \json_encode(
            ['argv' => $argv, 'warm_boot' => $warmBoot, 'dry_run' => $dryRun],
            \JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $deadline = $dryRun ? $this->callDeadlineNs() : null;
        \stream_set_timeout($socket, 1);
        try {
            $this->writeFrame($socket, $payload);
            $raw = $this->readFrame($socket, $deadline);
        } catch (RectorCallTimeoutException) {
            // #112: the whole tree -- the worker and anything the analysis spawned
            // (Rector's parallel workers). The next call boots a fresh worker.
            $this->discardProcWorker(true);
            throw new \RuntimeException(
                "rector call exceeded {$this->callTimeoutSeconds}s (--call-timeout); "
                . 'the warm worker process was killed',
            );
        } catch (\RuntimeException $e) {
            $this->discardProcWorker(true);
            throw $e;
        }
        $decoded = $raw === null ? null : \json_decode($raw, true);
        if (!\is_array($decoded)) {
            $message = 'the warm worker process closed its connection unexpectedly: ' . $this->procWorkerStderrTail();
            $this->discardProcWorker(true);
            throw new \RuntimeException($message);
        }
        if (isset($decoded['error'])) {
            throw new \RuntimeException((string) $decoded['error']);
        }

        /** @var array{exit_code: int, output: string, warm_boot: bool} $decoded */
        return $decoded;
    }

    private function procWorkerAlive(): bool
    {
        if ($this->procWorker === null) {
            return false;
        }
        $status = \proc_get_status($this->procWorker['proc']);

        return $status['running'];
    }

    private function procWorkerStderrTail(): string
    {
        if ($this->procWorker === null || !\is_file($this->procWorker['stderr'])) {
            return '(no stderr)';
        }
        $text = \trim((string) \file_get_contents($this->procWorker['stderr']));

        return $text === '' ? '(no stderr)' : \substr($text, -2000);
    }

    /** Hand the current worker to the retired list: it exits on its own after its one call. */
    private function retireProcWorker(): void
    {
        \assert($this->procWorker !== null);
        if (\is_resource($this->procWorker['socket'])) {
            \fclose($this->procWorker['socket']);
        }
        if (\is_resource($this->procWorker['server'])) {
            \fclose($this->procWorker['server']);
        }
        $this->retiredProcWorkers[] = ['proc' => $this->procWorker['proc'], 'stderr' => $this->procWorker['stderr']];
        $this->procWorker = null;
    }

    /**
     * Stop the current worker now. $tree: kill its whole process tree first (#112) --
     * the call-timeout path; otherwise the worker alone, which holds nothing worth a
     * graceful shutdown.
     *
     * $deadlineNs (#153): proc_close() blocks unconditionally until the OS reports
     * the child dead, with no timeout of its own. Sending it a kill signal first
     * makes that wait bounded IN PRACTICE almost everywhere -- the same reasoning
     * killAndReap() above already relies on for the pcntl path -- but a worker
     * killed while still mid-boot (loading its own PHP extensions) can rarely take
     * Windows noticeably longer to actually die than an already-connected one
     * does, because TerminateProcess() there can be deferred behind that process's
     * own DLL loader lock. Given a deadline, this polls instead of blocking
     * unconditionally and, past the deadline, returns without proc_close()ing --
     * a real resource "leak" (the handle -- not the OS process, which is still
     * being torn down) that only matters if this instance keeps running
     * afterwards. $deadlineNs is null everywhere except __destruct(), where the
     * whole PHP process is exiting anyway and the OS reclaims it regardless.
     */
    private function discardProcWorker(bool $tree, ?int $deadlineNs = null): void
    {
        if ($this->procWorker === null) {
            return;
        }
        $worker = $this->procWorker;
        $this->procWorker = null;
        foreach (['socket', 'server'] as $key) {
            if (\is_resource($worker[$key])) {
                @\fclose($worker[$key]);
            }
        }
        $status = \proc_get_status($worker['proc']);
        if ($status['running']) {
            if ($tree) {
                // Before proc_terminate(): taskkill /T must find the root alive.
                ProcessTree::killTree($worker['pid']);
            }
            // 9, not \SIGKILL: that constant belongs to pcntl, absent here.
            \proc_terminate($worker['proc'], 9);
        }
        if ($deadlineNs !== null && !$this->awaitProcExitOrDeadline($worker['proc'], $deadlineNs)) {
            // Still running past the deadline: do not let proc_close() hold this
            // caller's own exit hostage waiting for it (#153). Its stderr temp
            // file is left behind too -- unlinking it while the process might
            // still have it open is itself platform-risky on Windows.
            return;
        }
        \proc_close($worker['proc']);
        @\unlink($worker['stderr']);
    }

    /**
     * Poll $proc's status until it is no longer running or $deadlineNs passes,
     * whichever comes first -- never a single unconditional blocking wait.
     * Extracted so #153's regression test can drive it directly with a process
     * that is deliberately never signalled, independent of how fast SIGKILL/
     * TerminateProcess actually resolves on whatever platform the test runs on.
     *
     * @param resource $proc
     */
    private function awaitProcExitOrDeadline($proc, int $deadlineNs): bool
    {
        while (\proc_get_status($proc)['running']) {
            if (\hrtime(true) >= $deadlineNs) {
                return false;
            }
            \usleep(10_000);
        }

        return true;
    }

    private function reapRetiredProcWorkers(): void
    {
        foreach ($this->retiredProcWorkers as $i => $retired) {
            $status = \proc_get_status($retired['proc']);
            if (!$status['running']) {
                \proc_close($retired['proc']);
                @\unlink($retired['stderr']);
                unset($this->retiredProcWorkers[$i]);
            }
        }
        $this->retiredProcWorkers = \array_values($this->retiredProcWorkers);
    }

    /**
     * The daemon exiting must not leave a booted standby behind -- on Windows a child
     * holding inherited handles can also keep the host's pipes open.
     *
     * Bounded (#153): the standby this discards may still be mid-boot -- the
     * client closing stdin right after a call that forced a config-change
     * reboot (runViaStandbyWorker()'s finally block always starts a fresh one,
     * fire-and-forget) races exactly this destructor. The E2E harness this
     * issue was filed against (tests/E2E/stdio_tap.py's EXIT_GRACE) gives the
     * whole daemon only 1.5s to exit after stdin closes, so ONE shared 1.2s
     * budget covers both this call and stopRetiredProcWorkers() below, with
     * margin under that window for the rest of this method's own work --
     * not 2s per call, which could clear either wait alone yet still miss
     * the harness's own combined deadline. See discardProcWorker()'s own
     * docblock for why the wait is bounded rather than unconditional at all.
     */
    public function __destruct()
    {
        $deadlineNs = \hrtime(true) + 1_200_000_000;
        $this->discardProcWorker(false, $deadlineNs);
        $this->stopRetiredProcWorkers($deadlineNs);
    }

    /**
     * Stop and reap every retired worker now, rather than waiting for it to exit on
     * its own -- they have all served their call already.
     *
     * $deadlineNs (#153): shared with __destruct()'s own discardProcWorker() call --
     * a retired worker has already connected and served its call, so it is far less
     * likely to be caught by the mid-boot DLL-loader-lock race that section theorizes
     * about, but nothing rules it out, and an unconditional proc_close() here would
     * undo the whole point of bounding the standby's own wait right above it. null
     * (every other call site, via reboot()) keeps the old fully-blocking behaviour.
     */
    private function stopRetiredProcWorkers(?int $deadlineNs = null): void
    {
        foreach ($this->retiredProcWorkers as $retired) {
            $status = \proc_get_status($retired['proc']);
            if ($status['running']) {
                \proc_terminate($retired['proc'], 9);
            }
            if ($deadlineNs !== null && !$this->awaitProcExitOrDeadline($retired['proc'], $deadlineNs)) {
                continue;
            }
            \proc_close($retired['proc']);
            @\unlink($retired['stderr']);
        }
        $this->retiredProcWorkers = [];
    }

    /**
     * Worker-process side of #108, run by bin/rector-warm-worker.php: prove identity,
     * boot once, report the config snapshot, serve exactly ONE call in this process,
     * and return -- a second call here would run in a container a first one already
     * used, which #108 measured to diverge from cold. The config/composer hashes are
     * taken BEFORE the boot requires anything, so an edit that races the boot can only
     * make the daemon's next check see a change (a needless reboot), never hide one.
     *
     * $daemonPid: the daemon that spawned this worker. While idle, the worker checks it
     * is still alive and exits (WORKER_EXIT_ORPHANED) once it is not: a daemon killed
     * without cleaning up (kill -9, a crash) never closes this connection, because a
     * standby waits in the daemon's accept backlog and holds an inherited copy of
     * that very listening socket -- so EOF alone never comes, and without this check
     * the booted standby lived on forever.
     *
     * @param resource $socket
     * @return int exit code
     */
    public function serveProcessWorker($socket, string $token, ?int $daemonPid = null): int
    {
        $this->writeFrame($socket, $token);

        try {
            $mainConfigFile = $this->resolveMainConfigFile();
        } catch (\Throwable) {
            $mainConfigFile = null;
        }
        $snapshot = [
            'config_file' => $mainConfigFile,
            'config_file_hash' => $this->hashConfigFile($mainConfigFile),
            'composer_file' => $this->resolveComposerFile(),
        ];
        $snapshot['composer_file_hash'] = $this->hashConfigFile($snapshot['composer_file']);

        try {
            $this->bootInPlace();
        } catch (\Throwable $e) {
            $this->writeFrame($socket, $this->encodeHandshakeFrame([
                'ok' => false,
                'error' => $e->getMessage(),
                'error_class' => $e::class,
            ]));

            return 1;
        }
        $this->writeFrame($socket, $this->encodeHandshakeFrame(
            ['ok' => true, 'bootstrap_files' => $this->bootstrapFileHashes] + $snapshot,
        ));

        // Idle until the call arrives: a standby legitimately waits here for as long
        // as the caller takes, so no deadline -- but not past its daemon's death.
        // EOF = the daemon discarded it.
        while ($daemonPid !== null) {
            $read = [$socket];
            $write = $except = null;
            $ready = @\stream_select($read, $write, $except, self::ORPHAN_POLL_SECONDS);
            if ($ready !== 0) {
                break; // readable (a frame, or EOF), or select failed: readFrame() decides
            }
            // POSIX: the daemon is this worker's direct parent (proc_open() with an
            // array command, no shell between them). A dead daemon's children are
            // reparented at once, while the daemon itself can linger as a zombie its
            // own parent has not reaped yet -- and a zombie still answers kill(pid, 0),
            // so asking whether the daemon is alive would keep waiting on a corpse.
            // Windows (and POSIX without the posix extension): no reparenting to
            // watch, so ask whether it still runs. An unknown answer (null) keeps
            // waiting -- never exit on a guess.
            $orphaned = \function_exists('posix_getppid')
                ? \posix_getppid() !== $daemonPid
                : ProcessTree::isAlive($daemonPid) === false;
            if ($orphaned) {
                return self::WORKER_EXIT_ORPHANED;
            }
        }
        $frame = $this->readFrame($socket);
        if ($frame === null) {
            return 0;
        }
        $request = \json_decode($frame, true);
        $argv = \is_array($request) && \is_array($request['argv'] ?? null) ? $request['argv'] : [];
        $warmBoot = \is_array($request) && ($request['warm_boot'] ?? false) === true;
        // #134: execute() below runs the real analysis synchronously, in this
        // process, with no fork and no tick point to hang a daemon-liveness poll
        // off (that is exactly why the idle-wait loop above cannot help once a
        // frame has arrived -- see its own comment). A separate watchdog process
        // is the one primitive still available: it kills this worker's whole
        // process tree if $daemonPid dies while execute() is running, instead of
        // the call running to completion for a daemon (and client) that is gone.
        $watchdog = $this->spawnOrphanWatchdog($daemonPid);
        try {
            $result = $this->execute($argv, $warmBoot);
        } catch (\Throwable $e) {
            $result = ['error' => $e->getMessage(), 'error_class' => $e::class];
        } finally {
            $this->stopOrphanWatchdog($watchdog);
        }
        $this->writeFrame($socket, $this->encodeForkResult($result));

        return 0;
    }

    /**
     * #134: start bin/rector-warm-orphan-watchdog.php watching $daemonPid, or null
     * when there is no daemon to watch ($daemonPid === null) or it could not be
     * spawned -- best effort, same as the rest of this no-pcntl path's process-tree
     * handling (ProcessTree itself is best-effort by design).
     *
     * @return resource|null
     */
    private function spawnOrphanWatchdog(?int $daemonPid)
    {
        if ($daemonPid === null || !\function_exists('proc_open')) {
            return null;
        }
        $ownPid = $this->currentPid();
        if ($ownPid === false) {
            // Unlike spawnProcWorker()'s own getmypid() (also #157-adjacent, see
            // logGetmypidFailure()'s own docblock): there is no safe payload to
            // send here at all -- the watchdog's own argv parsing treats a
            // missing/zero workerPid as a hard refusal (<= 0 guard, exit(1)),
            // silently. Skip spawning it, same "best effort, no watchdog" shape
            // $daemonPid === null already takes above, instead of spawning one
            // that will exit immediately with nothing to show for it.
            $this->logGetmypidFailure('#134\'s orphan watchdog will not be spawned for this call (no worker pid to give it)');

            return null;
        }
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']];
        $watchdog = @\proc_open(
            [
                \PHP_BINARY,
                \dirname(__DIR__) . '/bin/rector-warm-orphan-watchdog.php',
                (string) $daemonPid,
                (string) $ownPid,
                (string) self::ORPHAN_POLL_SECONDS,
            ],
            $descriptors,
            $pipes,
        );

        return \is_resource($watchdog) ? $watchdog : null;
    }

    /** @param resource|null $watchdog */
    private function stopOrphanWatchdog($watchdog): void
    {
        if ($watchdog === null) {
            return;
        }
        $status = @\proc_get_status($watchdog);
        if (\is_array($status) && $status['running']) {
            \proc_terminate($watchdog, 9);
        }
        @\proc_close($watchdog);
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
     * #74: boot()'s handshake frames (serveWorker()'s success AND failure frames)
     * used plain json_encode() with no JSON_INVALID_UTF8_SUBSTITUTE flag, unlike
     * encodeForkResult() above and the other json_encode() call sites in this
     * file -- so a bootstrap file path (#33) that is not valid UTF-8 made
     * json_encode() return false, cast to '', and boot() then
     * reported a misleading "the warm worker failed to boot (exit status N)"
     * instead of the real cause. The substitute flag makes the encode succeed
     * (with the offending bytes replaced) instead of silently discarding the
     * whole payload; the same fallback-to-a-real-error convention as
     * encodeForkResult() covers the (now much narrower) case where encoding
     * still fails outright.
     */
    private function encodeHandshakeFrame(array $payload): string
    {
        $encoded = \json_encode($payload, \JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded !== false) {
            return $encoded;
        }

        $fallback = \json_encode([
            'ok' => false,
            'error' => 'failed to encode the warm-worker handshake frame: ' . \json_last_error_msg(),
        ]);

        return $fallback !== false ? $fallback : '{"ok":false,"error":"failed to encode the warm-worker handshake frame"}';
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
    private function readFrame($socket, ?int $deadlineNs = null, ?\Closure $onIdle = null): ?string
    {
        $header = $this->readExactly($socket, 4, $deadlineNs, $onIdle);
        if ($header === null) {
            return null;
        }
        $unpacked = \unpack('N', $header);
        $length = \is_array($unpacked) ? (int) $unpacked[1] : 0;
        if ($length === 0) {
            return '';
        }

        return $this->readExactly($socket, $length, $deadlineNs, $onIdle);
    }

    /**
     * @param resource $socket
     * @param int|null $deadlineNs When given (callDeadlineNs(), #58), a read still
     *   timing out past this point throws RectorCallTimeoutException instead of
     *   looping forever -- distinct from returning null (a real EOF), so the
     *   caller (runForked()) can tell "the worker is genuinely gone" apart from
     *   "the worker has gone quiet past its deadline" and react differently.
     *   null (the default) preserves the pre-#58 behaviour: loop on a timeout
     *   forever, distinguishing only real EOF from a live peer. Two of THREE
     *   current callers now pass a real deadline (boot()'s handshake,
     *   runForked()'s call-response read); the third, serveWorker()'s own
     *   idle-between-calls readFrame(), deliberately keeps the null default --
     *   a worker legitimately blocks indefinitely waiting for its NEXT request
     *   from the daemon, which is not a call in progress and has no
     *   --call-timeout budget to spend while idle.
     */
    private function readExactly($socket, int $length, ?int $deadlineNs = null, ?\Closure $onIdle = null): ?string
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
                    if ($deadlineNs !== null && \hrtime(true) >= $deadlineNs) {
                        // No specific "Ns" figure here, deliberately: this method
                        // has no idea whether its caller's $deadlineNs is the bare
                        // --call-timeout value or one with a grace period added
                        // (runForked() passes callDeadlineNs(RUN_FORKED_DEADLINE_
                        // GRACE_SECONDS), never the bare $this->callTimeoutSeconds)
                        // -- a message quoting $this->callTimeoutSeconds here would
                        // understate how long this specific wait actually ran.
                        throw new RectorCallTimeoutException(
                            'rector call exceeded its configured --call-timeout waiting on the warm worker',
                        );
                    }
                    // #185: the worker's wait on its session child polls the
                    // daemon's liveness here, as forkAndExecute()'s loop does (#127).
                    if ($onIdle !== null) {
                        $onIdle();
                    }
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

        [$argv, $skipAs] = self::extractSkipAs($argv);
        if ($skipAs !== null) {
            $this->applySkipsOfOriginalPath($skipAs, (string) end($argv));
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

        // #184: Rector\Console\ConsoleApplication::doRun() unconditionally calls
        // XdebugHandler::check() before running any command. When Xdebug is loaded,
        // check() restarts the OS process using $_SERVER['argv'] as-is. On the
        // worker/no-pcntl-cold paths $_SERVER['argv'][1..] is still whatever the daemon
        // was originally launched with (the argv[0] spoof above only ever touches index
        // 0), never THIS call's own $argv -- so a restart silently runs Rector for real,
        // without --dry-run, over every path in the daemon's config. XdebugHandler's own
        // documented escape hatch is an env var named "{envPrefix}_ALLOW_XDEBUG" (Rector
        // constructs it with envPrefix 'rector', i.e. RECTOR_ALLOW_XDEBUG); setting it
        // makes check() skip the restart outright, independent of what argv contains.
        // Symmetric with the argv[0] save/restore just above: set for this call only,
        // restore whatever the env held before, so nothing leaks across calls.
        $origAllowXdebugEnv = getenv('RECTOR_ALLOW_XDEBUG');
        putenv('RECTOR_ALLOW_XDEBUG=1');

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
            if ($origAllowXdebugEnv === false) {
                putenv('RECTOR_ALLOW_XDEBUG');
            } else {
                putenv('RECTOR_ALLOW_XDEBUG=' . $origAllowXdebugEnv);
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
     * @param list<string> $argv
     * @return array{0: list<string>, 1: ?string} argv without the option, and its value
     */
    private static function extractSkipAs(array $argv): array
    {
        $skipAs = null;
        $kept = [];
        $prefix = self::SKIP_AS_OPTION . '=';
        $optionsEnded = false;

        foreach ($argv as $arg) {
            if (!$optionsEnded && $arg === '--') {
                $optionsEnded = true;
            } elseif (!$optionsEnded && str_starts_with($arg, $prefix)) {
                $skipAs = substr($arg, strlen($prefix));
                continue;
            }
            $kept[] = $arg;
        }

        return [$kept, $skipAs];
    }

    /**
     * #106: Rector decides skips by matching the path it processes against
     * `withSkip()` (FileInfoMatcher: exact path, prefix, suffix, fnmatch).
     * The temp copy of an unsaved buffer lives at a different path, so a skip
     * naming the original -- `__DIR__ . '/src/Foo.php'`, `'src/Foo.php'`, a
     * glob ending in `/src/Foo.php`, or `Rule::class => [that path]` --
     * never matched it.
     *
     * Asks Rector's own Skipper about the ORIGINAL path, and extends the
     * resolved skip lists so the copy is skipped the same way: the whole file
     * if the original is path-skipped, otherwise each rule skipped for the
     * original. This runs only in a process that dies after this one call
     * (the forked grandchild, or the no-pcntl cold subprocess), so the warm
     * worker's own container is never changed. It writes the two resolvers'
     * cached lists, which are private: if Rector ever renames them, this
     * fails open (the copy is diagnosed without those skips) and says so on
     * stderr, rather than failing the call.
     */
    private function applySkipsOfOriginalPath(string $originalPath, string $copyPath): void
    {
        \assert($this->container !== null);

        try {
            $skipper = $this->container->get(\Rector\Skipper\Skipper\Skipper::class);
            $copyPaths = \array_values(\array_unique(\array_filter([$copyPath, \realpath($copyPath) ?: null])));

            if ($skipper->shouldSkipFilePath($originalPath)) {
                $pathsResolver = $this->container->get(\Rector\Skipper\SkipCriteriaResolver\SkippedPathsResolver::class);
                self::overwriteResolved($pathsResolver, 'skippedPaths', \array_merge($pathsResolver->resolve(), $copyPaths));

                return;
            }

            $classResolver = $this->container->get(\Rector\Skipper\SkipCriteriaResolver\SkippedClassResolver::class);
            $classes = $classResolver->resolve();
            $changed = false;
            foreach ($classes as $class => $files) {
                if ($files === null) {
                    continue; // skipped everywhere, the copy included
                }
                if (self::ruleSkippedFor($skipper, $class, $originalPath) && !self::ruleSkippedFor($skipper, $class, $copyPath)) {
                    $classes[$class] = \array_merge($files, $copyPaths);
                    $changed = true;
                }
            }

            if ($changed) {
                self::overwriteResolved($classResolver, 'skippedClassesToFiles', $classes);
            }
        } catch (\Throwable $e) {
            // Fail open: the copy is diagnosed without these skips. The
            // forked grandchild has already closed fd 2 (runForked()), and an
            // fwrite() to a closed STDERR throws a TypeError out of this
            // catch -- failing the whole call. Write only where it is open.
            if (\defined('STDERR') && \is_resource(\STDERR)) {
                @\fwrite(\STDERR, \sprintf(
                    "mcp-rector-warm: could not apply the skip rules of %s to its buffer copy: %s\n",
                    $originalPath,
                    $e->getMessage(),
                ));
            }
        }
    }

    /**
     * Skipper::matchSkip() exists from Rector 2.5.2 and does not mark the
     * skip as used; composer.json allows ^2.4, whose Skipper only has
     * shouldSkipElementAndFilePath() (same answer, and no used-skip
     * tracking to disturb before 2.5.2).
     */
    private static function ruleSkippedFor(object $skipper, string $class, string $path): bool
    {
        if (\method_exists($skipper, 'matchSkip')) {
            return $skipper->matchSkip($class, $path) !== null;
        }

        return $skipper->shouldSkipElementAndFilePath($class, $path);
    }

    private static function overwriteResolved(object $resolver, string $property, array $value): void
    {
        $reflection = new \ReflectionProperty($resolver, $property);
        $reflection->setValue($resolver, $value);
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
        // Before anything is required: see resolveBootstrapFileHashes().
        $bootStartedAt = \time();
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

        $this->bootstrapFileHashes = $this->resolveBootstrapFileHashes($bootStartedAt);

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
     * Resolve the project's composer.json the same way ensureProjectAutoloaded() resolves
     * vendor/autoload.php: relative to getcwd(), which bin/mcp-rector-warm has already
     * chdir()'d to --working-dir before this (or any forked worker) process runs. Used by
     * configFileChanged() and refreshConfigFileState() (#34).
     */
    private function resolveComposerFile(): ?string
    {
        $path = getcwd() . '/composer.json';

        return is_file($path) ? $path : null;
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
     * upstream -- missing this file-list must never break a boot that otherwise
     * succeeded, only quietly lose the "picked up a bootstrap edit" behaviour this
     * method exists for. Unlike execute()'s ConfigInitializer check earlier in this
     * file (deliberately left UNCAUGHT -- see the comment there), this one is a
     * genuine try/catch, so the catch branch writes one line to stderr (#63):
     * display_errors=stderr is already set (bin/mcp-rector-warm), so this lands in
     * the same server logs a boot failure would, instead of a silent, permanent
     * return to pre-#33 behaviour indistinguishable from "this config simply
     * registers no bootstrap files".
     *
     * Hashed AFTER the boot that required them, so an edit landing between Rector's
     * require and this hash would be recorded as the version the container holds --
     * no reboot, a stale container, a silent wrong answer (#108 review; the no-pcntl
     * standby boots in the background after every call, which reopens this window
     * after every call). A file modified at or after $bootStartedAt is therefore
     * recorded as BOOT_RACE_HASH, which no real hash equals: the next
     * configFileChanged() reports it changed. filemtime() has 1s resolution, so an
     * edit in the same second just before the boot costs one needless reboot --
     * the safe direction.
     *
     * @return array<string, string|null>
     */
    private function resolveBootstrapFileHashes(int $bootStartedAt): array
    {
        try {
            $bootstrapFiles = \Rector\Configuration\Parameter\SimpleParameterProvider::provideArrayParameter('bootstrap_files');
        } catch (\Throwable $e) {
            \fwrite(
                \STDERR,
                "mcp-rector-warm: could not resolve withBootstrapFiles() paths ({$e->getMessage()}); "
                . "bootstrap-file edits will not force a reboot until the next boot (#63)\n",
            );

            return [];
        }

        $hashes = [];
        foreach ($bootstrapFiles as $bootstrapFile) {
            if (!is_string($bootstrapFile)) {
                continue;
            }
            \clearstatcache(true, $bootstrapFile);
            $mtime = @\filemtime($bootstrapFile);
            $hashes[$bootstrapFile] = $mtime !== false && $mtime >= $bootStartedAt
                ? self::BOOT_RACE_HASH
                : $this->hashConfigFile($bootstrapFile);
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

        $composerFile = $this->resolveComposerFile();
        $this->composerFile = $composerFile;
        $this->composerFileHash = $this->hashConfigFile($composerFile);
    }

    /**
     * Load rector's scoped autoload lazily. Find it by reflecting on a Rector class: works
     * whether mcp-rector-warm is installed as a local clone (nested vendor) or as a
     * project/composer-global dep (rector lives parallel under the same vendor dir).
     * Idempotent -- safe to call before bootInPlace() has ever run, e.g. from
     * configFileChanged().
     *
     * Rector's preload.php first, like cold bin/rector.php (#192) -- see preloadLikeCold().
     */
    private function ensureRectorAutoloaded(): void
    {
        if (class_exists(RectorConfigsResolver::class, false)) {
            return;
        }
        // file = .../rector/rector/src/Bootstrap/RectorConfigsResolver.php → 3 levels up = package root
        $rectorPkgDir = dirname((new \ReflectionClass(RectorConfigsResolver::class))->getFileName(), 3);
        $this->preloadLikeCold($rectorPkgDir);
        $scoperAutoload = $rectorPkgDir . '/vendor/scoper-autoload.php';
        if (!is_file($scoperAutoload)) {
            throw new \RuntimeException("scoper-autoload.php not found at: {$scoperAutoload}");
        }
        require_once $scoperAutoload;
    }

    /**
     * Require the files Rector's preload.php requires, in its order, as cold
     * bin/rector.php does before any other autoloader is loaded (#192). nikic/php-parser
     * and phpstan/phpdoc-parser are not prefixed in Rector's build, so without this each
     * of their classes is autoloaded on first use by whichever loader answers first --
     * the project's Composer loader and phpstan.phar's both prepend themselves ahead of
     * Rector's, and each can carry a different version. Measured on laravel/framework
     * with an empty PHPStan cache: the warm worker took 103 php-parser classes
     * (BuilderHelpers among them) from phpstan.phar and 15 from Rector's copy, and failed
     * with "Invalid value"; cold took all 263 from Rector's copy.
     *
     * Not a plain require of preload.php: this process may already hold some classes of
     * one of those packages from another copy (the MCP server's own mcp/sdk loads
     * phpstan/phpdoc-parser from this package's vendor before any tool call), and
     * redeclaring one is a fatal. Such a package is left as it is -- its mix already
     * happened -- and the other one is still preloaded.
     */
    private function preloadLikeCold(string $rectorPkgDir): void
    {
        $preload = $rectorPkgDir . '/preload.php';
        if (!is_file($preload) || !is_dir($rectorPkgDir . '/vendor')) {
            return;
        }
        $namespaces = ['nikic/php-parser' => 'PhpParser\\', 'phpstan/phpdoc-parser' => 'PHPStan\\PhpDocParser\\'];
        $declared = [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()];
        $loadable = [];
        foreach ($namespaces as $package => $namespace) {
            $own = realpath($rectorPkgDir . '/vendor/' . $package);
            $loadable[$package] = $own !== false;
            foreach ($declared as $name) {
                if ($loadable[$package] && str_starts_with($name, $namespace)) {
                    $file = (new \ReflectionClass($name))->getFileName();
                    $loadable[$package] = $file !== false && str_starts_with((string) realpath($file), $own . DIRECTORY_SEPARATOR);
                }
            }
        }
        preg_match_all("#^require_once __DIR__ \. '(/vendor/([^/']+/[^/']+)/[^']+)';#m", (string) file_get_contents($preload), $requires, PREG_SET_ORDER);
        foreach ($requires as [, $relative, $package]) {
            if ($loadable[$package] ?? false) {
                require_once $rectorPkgDir . $relative;
            }
        }
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
     * #195: skipped entirely when the project ships its own vendor/rector/rector --
     * see projectShipsOwnRector(). AutoloadIncluder::autoloadRectorInstalledAsGlobalDependency()
     * (rector/rector's bin/rector.php) checks the exact same thing and returns early rather
     * than loading getcwd() . '/vendor/autoload.php' when it is true, because that signal means
     * Rector is being run standalone against a foreign project that already has its own Rector
     * install -- a genuine cold `vendor/bin/rector process` run in that situation never touches
     * the project's autoloader either. Without this guard, warm loaded the project's real
     * Composer autoloader unconditionally and could resolve classes/types a matching cold run
     * never sees, so warm inferred strictly more than cold on every mismatching file (checkout
     * mode against laravel/framework and symfony/symfony, both of which carry their own
     * vendor/rector/rector as a dev dependency).
     *
     * Known limitation, not silently ignored: this loads the project autoloader once, at
     * boot, in a worker process that then serves every warm call for the rest of its
     * session. A `composer dump-autoload` mid-session (a newly generated class in the
     * project's classmap, with composer.json itself untouched) is invisible until the
     * worker next reboots -- the same class of staleness #20 (PR #29) already accepts for
     * rector.php itself between edits, not newly introduced here. #34 closed the narrower
     * gap (composer.json's own bytes -- read by withPhpSets() -- are now tracked by
     * configFileChanged() via resolveComposerFile()); extending detection further, to the
     * project's own vendor/composer/installed.php so a dump-autoload with no composer.json
     * edit is also caught, remains a separate, wider change.
     */
    private function ensureProjectAutoloaded(): void
    {
        $cwd = (string) getcwd();
        if ($this->projectShipsOwnRector($cwd)) {
            return;
        }
        $projectAutoload = $cwd . '/vendor/autoload.php';
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
     * #195: true when $cwd (the analysed project) has its own vendor/rector/rector -- the
     * exact directory rector/rector's own bin/rector.php AutoloadIncluder checks
     * (`is_dir('vendor/rector/rector')`, relative to getcwd()) before deciding NOT to load
     * `vendor/autoload.php` in autoloadRectorInstalledAsGlobalDependency(). mcp-rector-warm is
     * never that project's own installed Rector (it always analyses through its own bundled
     * copy, ensureRectorAutoloaded()), so a project with its own vendor/rector/rector is exactly
     * the "checkout mode against a project that ships its own Rector" case: cold's own
     * `vendor/bin/rector process` run there never loads the project's autoloader, so neither
     * should warm. Protected (rather than a free function) so a test can override it without
     * needing a real vendor/rector/rector directory on disk.
     */
    protected function projectShipsOwnRector(string $cwd): bool
    {
        return is_dir($cwd . '/vendor/rector/rector');
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
     * The resolved main config file, every file the config registered via
     * withBootstrapFiles() (#33) -- see resolveBootstrapFileHashes() -- and the project's
     * composer.json (#34) -- see resolveComposerFile(), needed because withPhpSets() with no
     * argument reads composer.json's require.php once at boot -- are all tracked this way.
     * A rector.php that itself requires/includes some OTHER shared file directly (not via
     * withBootstrapFiles()) is a known limitation, not silently ignored: building the DI
     * container in bootInPlace() autoloads hundreds of unrelated classes through the same
     * require/include machinery, so there is no reliable way to isolate "a file rector.php
     * chose to include" from that noise without parsing rector.php's own source for
     * require/include statements -- which would still miss a dynamically computed path.
     * Editing (even a no-op touch of) the main config file itself is the reliable way to
     * force a reboot after changing such a file.
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

        $composerFile = $this->resolveComposerFile();
        if ($composerFile !== $this->composerFile) {
            return true;
        }

        if ($this->hashConfigFile($composerFile) !== $this->composerFileHash) {
            return true;
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
