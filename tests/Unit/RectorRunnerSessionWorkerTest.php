<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorCallTimeoutException;
use Dpt\McpRectorWarm\RectorRunner;
use Dpt\McpRectorWarm\Tests\Support\TempPath;
use PHPUnit\Framework\TestCase;

/**
 * #245: the session CHILD's own instance methods -- runInSession(),
 * spawnSession(), askSession(), serveSession()/runSessionBody(),
 * sessionExited(), reapSession() -- have no direct test anywhere.
 * RectorRunnerSessionTest.php covers the static pure helpers
 * (sessionCandidate, sessionSwitchedOff, sessionWatchPaths,
 * sessionRetireReason, and -- as of this same commit -- positiveIntEnv).
 * These drive the real thing end to end: a real boot() (real pcntl_fork(),
 * a real Rector container), a real dry run on one file that routes to the
 * session, and a real second pcntl_fork() for the session child itself.
 * execute() is overridden -- the same seam every fork/worker test in
 * RectorRunnerTest.php already uses -- so these pin the session MACHINERY
 * alone, never Rector's own analysis.
 */
final class RectorRunnerSessionWorkerTest extends TestCase
{
    private string $tmp;

    private string $target;

    private string $callLog;

    private string|false $previousSessionEnv;

    private string|false $previousMaxCallsEnv;

    private string $previousCwd;

    /** $_SERVER['argv'] exactly as found, restored verbatim in tearDown() */
    private mixed $previousArgv;

    protected function setUp(): void
    {
        if (
            !\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')
            || !\function_exists('stream_socket_pair') || !\function_exists('posix_kill')
        ) {
            self::markTestSkipped('pcntl_fork/pcntl_waitpid/stream_socket_pair/posix_kill unavailable in this environment');
        }

        $this->tmp = \sys_get_temp_dir() . '/rector-runner-session-worker-test-' . \bin2hex(\random_bytes(8));
        \mkdir($this->tmp);
        \file_put_contents(
            $this->tmp . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\Config\RectorConfig;\n\nreturn RectorConfig::configure();\n",
        );
        // No declared class/function in here: WarmSession::route() must
        // return SERVE for it ("found only through this file" never fires).
        $this->target = $this->tmp . '/target.php';
        \file_put_contents($this->target, "<?php\necho 1;\n");
        $this->callLog = $this->tmp . '/calls.log';

        $this->previousSessionEnv = \getenv(RectorRunner::SESSION_ENV);
        $this->previousMaxCallsEnv = \getenv(RectorRunner::SESSION_MAX_CALLS_ENV);
        \putenv(RectorRunner::SESSION_ENV . '=1');

        $this->previousCwd = (string) \getcwd();
        $this->previousArgv = $_SERVER['argv'] ?? ['rector'];
        \chdir($this->tmp);
        $_SERVER['argv'] = ['rector'];
    }

    protected function tearDown(): void
    {
        \chdir($this->previousCwd);
        $_SERVER['argv'] = $this->previousArgv;
        \putenv(
            $this->previousSessionEnv === false
                ? RectorRunner::SESSION_ENV
                : RectorRunner::SESSION_ENV . '=' . $this->previousSessionEnv,
        );
        \putenv(
            $this->previousMaxCallsEnv === false
                ? RectorRunner::SESSION_MAX_CALLS_ENV
                : RectorRunner::SESSION_MAX_CALLS_ENV . '=' . $this->previousMaxCallsEnv,
        );
        TempPath::unlink($this->target);
        TempPath::unlink($this->tmp . '/rector.php');
        TempPath::unlink($this->callLog);
        TempPath::rmdir($this->tmp);
    }

    private function newRunner(): RectorRunner
    {
        $log = $this->callLog;

        return new class ($log) extends RectorRunner {
            public function __construct(private readonly string $log)
            {
                parent::__construct(0);
            }

            protected function execute(array $argv, bool $warmBoot): array
            {
                \file_put_contents($this->log, \getmypid() . "\n", \FILE_APPEND);

                return ['exit_code' => 0, 'output' => '', 'warm_boot' => $warmBoot];
            }
        };
    }

    /** @return list<int> */
    private function loggedPids(): array
    {
        if (!\is_file($this->callLog)) {
            return [];
        }
        $lines = \array_filter(
            \explode("\n", (string) \file_get_contents($this->callLog)),
            static fn(string $line): bool => $line !== '',
        );

        return \array_map(\intval(...), \array_values($lines));
    }

    /**
     * #185 happy path: runInSession()/spawnSession()/askSession()/
     * serveSession()/runSessionBody() all run for real across three calls on
     * the same file. The session child must be REUSED across calls that
     * change nothing (same pid every time) -- the whole reason the session
     * exists at all (#185's own docblock: forking fresh per call throws away
     * everything a call learns).
     *
     * Positive control folded in at the end: with the session switched off,
     * every call forks straight from the pristine worker instead, so the pid
     * MUST differ call to call -- proving the "same pid" assertion above is a
     * real signal of reuse, not an artefact of a harness that cannot tell
     * two calls apart.
     */
    public function testTheSessionChildIsReusedAcrossCallsThatChangeNothing(): void
    {
        $runner = $this->newRunner();
        $argv = ['rector', 'process', '--', $this->target];

        try {
            $runner->run($argv);
            $runner->run($argv);
            $runner->run($argv);

            $pids = $this->loggedPids();
            self::assertCount(3, $pids, 'execute() must have run exactly three times, once per call');
            self::assertSame(
                $pids[0],
                $pids[1],
                'the second call must be served by the SAME session child as the first -- spawnSession() must not run again',
            );
            self::assertSame($pids[1], $pids[2], 'the third call must still be served by the same session child');
        } finally {
            // Every call above booted a real worker (and, with the session
            // on, a real session child it never retired on its own) via real
            // pcntl_fork() -- reboot() is this repo's own established
            // cleanup for exactly that, matching the finally-block pattern
            // RectorRunnerTest.php and RectorRunnerStandbyWorkerTest.php use
            // in every test that boots a real worker. Without it the process
            // is left running past this test, with its cwd still pointed at
            // $this->tmp, which tearDown() then rmdir()s. This MUST happen
            // before the positive control below boots a second, independent
            // worker: a live worker's socket fd is inherited by every LATER
            // pcntl_fork() from this same test process, so a second worker
            // forked while this one is still up holds a duplicate copy of
            // its socket open -- closing the original from here then never
            // produces real EOF for it, and reboot() hangs waiting for a
            // worker that cannot ever see its own socket close (found by
            // self-review: this hung for real, 90s+, until reordered).
            $runner->reboot();
        }

        // Positive control: session switched off -> no reuse is even
        // possible, a fresh fork every time, so the pid MUST change. A
        // SEPARATE runner/worker, started only after the first is fully
        // rebooted above -- see the finally block's comment for why.
        \putenv(RectorRunner::SESSION_ENV . '=0');
        \unlink($this->callLog);
        $runner2 = $this->newRunner();
        try {
            $runner2->run($argv);
            $runner2->run($argv);
            $controlPids = $this->loggedPids();
            self::assertCount(2, $controlPids);
            self::assertNotSame(
                $controlPids[0],
                $controlPids[1],
                'must fire: with the session off, every call forks fresh from the worker -- two different '
                . 'pids are the ONLY possible correct outcome, proving this harness can tell two calls apart',
            );
        } finally {
            $runner2->reboot();
        }
    }

    /**
     * #185: when the session child dies unexpectedly BETWEEN two calls (not
     * via its own orderly retire), the worker's sessionExited() check at the
     * top of runInSession() must notice (a non-blocking pcntl_waitpid(...,
     * WNOHANG)), reap it, and spawn a brand-new session for the very next
     * call -- never write to (or hang on) a socket whose peer is already gone.
     */
    public function testADeadSessionChildIsNoticedAndReplacedOnTheNextCall(): void
    {
        $runner = $this->newRunner();
        $argv = ['rector', 'process', '--', $this->target];

        try {
            self::assertSame(0, $runner->run($argv)['exit_code']);
            $firstPid = $this->loggedPids()[0];
            self::assertTrue(
                \posix_kill($firstPid, 0),
                'the session child must genuinely be alive right after serving the first call',
            );

            self::assertTrue(\posix_kill($firstPid, \SIGKILL), 'must be able to kill the session child directly');
            // Give the kernel a moment to deliver the signal before the next
            // call races pcntl_waitpid() against it.
            \usleep(200_000);

            self::assertSame(
                0,
                $runner->run($argv)['exit_code'],
                'the next call must self-heal into a fresh session, not hang or error',
            );
            $pids = $this->loggedPids();
            self::assertCount(2, $pids);
            self::assertNotSame(
                $firstPid,
                $pids[1],
                'the replacement session child must be a NEW process, not the one just killed',
            );

            // And it is reused from here on, same as the happy path above.
            self::assertSame(0, $runner->run($argv)['exit_code']);
            $pids = $this->loggedPids();
            self::assertSame($pids[1], $pids[2], 'once replaced, the new session child is reused exactly like any other');
        } finally {
            // See the matching comment in testTheSessionChildIsReusedAcrossCallsThatChangeNothing().
            $runner->reboot();
        }
    }

    /**
     * #185: MCP_RECTOR_WARM_SESSION_MAX_CALLS caps how many calls one session
     * child serves before retiring itself -- sessionRetireReason() decides
     * it, runInSession() reaps the retiring child (reapSession(), a BLOCKING
     * pcntl_waitpid() this time: the child is already exiting on its own, not
     * killed) and the very next call must spawn a fresh one, with no error
     * visible to the caller either way.
     *
     * A cap of exactly 1 would make every call look identical to a session
     * that never worked at all (a brand-new pid every single call either
     * way), so this uses a cap of 2: the first two calls must still be
     * REUSE (the cap is not yet hit -- proving the count is actually read,
     * not merely "always respawn"), and only the third call -- the one that
     * pushes the session over its cap -- gets a fresh one.
     */
    public function testASessionRetiresAfterItsConfiguredCallCapAndIsReplaced(): void
    {
        \putenv(RectorRunner::SESSION_MAX_CALLS_ENV . '=2');
        $runner = $this->newRunner();
        $argv = ['rector', 'process', '--', $this->target];

        try {
            $runner->run($argv);
            $runner->run($argv);
            $runner->run($argv);

            $pids = $this->loggedPids();
            self::assertCount(3, $pids);
            self::assertSame(
                $pids[0],
                $pids[1],
                'under the cap of 2 calls, the second call must still reuse the first session child',
            );
            self::assertNotSame(
                $pids[1],
                $pids[2],
                'the call that pushes the session past its cap must retire it -- the third call gets a brand-new session child',
            );
        } finally {
            // See the matching comment in testTheSessionChildIsReusedAcrossCallsThatChangeNothing().
            // The third session here already retired itself (reapSession()
            // already ran inside runInSession()), but the worker itself is
            // still live and must still be torn down.
            $runner->reboot();
        }
    }

    /**
     * #238: spawnSession()'s handshake read used to let
     * RectorCallTimeoutException escape straight past runInSession() as a
     * RuntimeException blaming "the analysis" (which never started), and
     * never called disableSession() -- so the fallback #189 documents for
     * every other declined/lost-session route never ran here, and the very
     * next call would spawn a fresh session and could time out identically.
     *
     * readSessionHandshake() is overridden to throw deterministically:
     * making the real session child's own startup genuinely outlast a real
     * --call-timeout is a timing race no test should depend on winning (see
     * that method's own docblock in RectorRunner.php). The bar this test
     * pins is that the call must still SUCCEED -- forked from the pristine
     * worker, #8's original fallback -- not throw, and the session must be
     * disabled for the rest of this worker's life so the next call does not
     * retry the exact same dead end.
     */
    public function testSpawnSessionHandshakeTimeoutDeclinesInsteadOfThrowing(): void
    {
        $log = $this->callLog;
        // spawnSession()/readSessionHandshake() run inside the forked WORKER,
        // a genuinely separate OS process from this test -- a property on
        // $runner mutated there is a copy-on-write fork no PHPUnit assertion
        // back here can ever see (the daemon and worker only ever talk over
        // the socket pair). A second log file, exactly like $this->callLog
        // above, is the only signal that actually crosses that boundary.
        $handshakeLog = $this->tmp . '/handshakes.log';

        $runner = new class ($log, $handshakeLog) extends RectorRunner {
            public function __construct(
                private readonly string $log,
                private readonly string $handshakeLog,
            ) {
                parent::__construct(0);
            }

            protected function execute(array $argv, bool $warmBoot): array
            {
                \file_put_contents($this->log, \getmypid() . "\n", \FILE_APPEND);

                return ['exit_code' => 0, 'output' => '', 'warm_boot' => $warmBoot];
            }

            protected function readSessionHandshake($socket, ?int $deadlineNs): ?string
            {
                \file_put_contents($this->handshakeLog, "attempt\n", \FILE_APPEND);

                throw new RectorCallTimeoutException('simulated: the session child never reported in');
            }
        };

        $argv = ['rector', 'process', '--', $this->target];

        try {
            $result = $runner->run($argv);

            self::assertSame(
                0,
                $result['exit_code'],
                'a handshake timeout must still be served by the plain fork fallback, not throw',
            );
            self::assertSame(
                1,
                \substr_count((string) \file_get_contents($handshakeLog), "attempt\n"),
                'must fire: the override must actually have run once, or the rest of this assertion proves nothing',
            );

            // A second call must not even attempt another handshake: a
            // handshake timeout must disable the session for the rest of
            // this worker's life, the same as the sibling branch for a
            // handshake that decoded but reported !ok -- otherwise the next
            // call spawns again and can time out identically (#238). The
            // SAME worker serving both calls (warm_boot true below) is what
            // makes this a real test of "stuck disabled", not an artefact of
            // a fresh worker that never tried in the first place.
            $second = $runner->run($argv);
            self::assertSame(0, $second['exit_code']);
            self::assertTrue($second['warm_boot'], 'the worker is now up -- this call must reuse it, not boot fresh');
            self::assertSame(
                1,
                \substr_count((string) \file_get_contents($handshakeLog), "attempt\n"),
                'the session stayed disabled -- a second attempt would mean disableSession() did not stick',
            );
        } finally {
            $runner->reboot();
            TempPath::unlink($handshakeLog);
        }
    }
}
