<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Integration;

use Dpt\McpRectorWarm\Support\ProcessTree;
use PHPUnit\Framework\TestCase;

/**
 * #269 (part of #245): bin/rector-warm-orphan-watchdog.php had 58.53%
 * Codecov-measured line coverage -- nothing in the suite spawned the script
 * itself as a subprocess. RectorRunnerStandbyWorkerTest's two E2E tests
 * (testTheStandbyExitsWhenTheDaemonIsKilled{,MidCall}) spawn the real
 * daemon+worker+watchdog stack and exercise the main detection/kill path,
 * but never the argv guard, the already-dead-worker exit, or the `ps`-probe-
 * failure branch. This spawns the script directly via proc_open(), mirroring
 * LspServerStdioTest's/ServerStdioTest's PHP_BINARY-prepend pattern so
 * pcov's auto_prepend_file instrumentation (tests/coverage/prepend.php)
 * sees it run and credits the lines.
 *
 * Lines 88-98 (the Windows-only daemon-liveness branch) are deliberately not
 * exercised anywhere in this file: PHP_OS_FAMILY is a compile-time constant,
 * not overridable at runtime, so that branch is unreachable on whatever
 * single platform this suite actually runs on -- per #269's own
 * instructions, listed here rather than forced:
 *   - 88, 89, 90, 91, 92, 93, 94, 95, 98: the `elseif (PHP_OS_FAMILY ===
 *     'Windows')` arm (daemon-alive check via `tasklist`, its own log-once
 *     block, and the resulting $orphaned assignment) can only run on an
 *     actual Windows process; on POSIX it is dead code for this process,
 *     and on Windows the POSIX-only lines this file DOES cover (79-83, 100)
 *     would themselves be unreachable instead (parentOf() short-circuits to
 *     null without ever calling `ps` once PHP_OS_FAMILY is Windows -- see
 *     ProcessTree::parentOf()). No single CI leg can exercise both halves.
 */
final class OrphanWatchdogTest extends TestCase
{
    private static string $bin;

    public static function setUpBeforeClass(): void
    {
        self::$bin = \dirname(__DIR__, 2) . '/bin/rector-warm-orphan-watchdog.php';
        if (!\is_file(self::$bin)) {
            self::markTestSkipped('bin/rector-warm-orphan-watchdog.php missing');
        }
    }

    /**
     * Line 42-43: the argv guard. Spawned with no daemon/worker pids at
     * all, the script must exit(1) immediately rather than ever entering
     * the poll loop.
     */
    public function testExitsOneWhenCalledWithoutAValidDaemonOrWorkerPid(): void
    {
        $exitCode = $this->runWatchdog([]);

        self::assertSame(1, $exitCode, 'must fire: an invalid argv must exit(1) before the poll loop ever starts');
    }

    /**
     * Line 51-54: the worker is already gone by the time the FIRST poll
     * runs -- nothing left to guard, so the watchdog must exit(0) quietly
     * rather than looping forever.
     */
    public function testExitsZeroWhenTheWorkerIsAlreadyDead(): void
    {
        // Same "pid this unlikely to exist" sentinel ProcessTreeTest uses --
        // isAlive() must read it as dead on both the posix_kill and `ps`
        // fallback paths.
        $deadWorkerPid = 0x7FFFFFFE;

        $exitCode = $this->runWatchdog(['1', (string) $deadWorkerPid, '1']);

        self::assertSame(0, $exitCode, 'must fire: an already-dead worker must make the watchdog exit(0) on its first poll');
    }

    /**
     * Lines 79-83 (the #159 log-once block for a failed `ps` probe) and
     * line 100 (the final `else` arm): the code does not distinguish "the
     * probe failed" from "the probe ran fine and genuinely found nothing"
     * when deciding $orphaned, only when deciding whether to log -- parent
     * === null and non-Windows reaches the same $orphaned = false either
     * way. Breaking PATH for the watchdog subprocess alone (the same
     * technique ProcessTreeTest::testLastProbeRanOkIsFalse... uses) with a
     * genuinely alive worker makes parentOf() unable to resolve a parent on
     * every poll, hitting both lines together.
     */
    public function testLogsOnceAndTreatsAnUnresolvableParentAsNotOrphanedWhenPsCannotRun(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('this forces `ps` off PATH, which is POSIX-specific -- ProcessTree::parentOf() is POSIX-only');
        }

        $null = '/dev/null';
        $worker = \proc_open(
            [\PHP_BINARY, '-r', 'sleep(20);'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $workerPipes,
        );
        self::assertIsResource($worker, 'must be able to spawn a disposable worker process');
        $workerStatus = \proc_get_status($worker);
        $workerPid = (int) $workerStatus['pid'];
        self::assertGreaterThan(0, $workerPid, 'proc_get_status() must report a real worker pid');

        $env = \getenv();
        $env['PATH'] = \sys_get_temp_dir() . '/nonexistent-path-for-269-test';

        $stderrFile = (string) \tempnam(\sys_get_temp_dir(), 'orphan-watchdog-269-stderr-');
        $watchdog = \proc_open(
            [\PHP_BINARY, self::$bin, '1', (string) $workerPid, '1'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $stderrFile, 'w']],
            $watchdogPipes,
            null,
            $env,
        );
        self::assertIsResource($watchdog, 'must be able to spawn the watchdog with PATH broken');

        try {
            $deadline = \microtime(true) + 10.0;
            $stderr = '';
            while (\microtime(true) < $deadline) {
                $stderr = (string) \file_get_contents($stderrFile);
                if (\str_contains($stderr, 'could not run `ps`')) {
                    break;
                }
                \usleep(100_000);
            }

            self::assertStringContainsString(
                'could not run `ps`',
                $stderr,
                "must fire: with PATH broken, the watchdog must log the ps-probe-failure message; stderr so far: {$stderr}",
            );
            self::assertSame(
                1,
                \substr_count($stderr, 'could not run `ps`'),
                'the #159 log-once guard must suppress every poll after the first -- more than one means it logged on every loop',
            );

            // Positive control for the $orphaned = false branch (line 100):
            // with the parent unresolvable, the watchdog must NOT treat the
            // worker as orphaned and must NOT kill it -- it must still be
            // alive here, well past one poll interval. Checked from THIS
            // process, whose own PATH was never touched (only the spawned
            // watchdog's $env was), so ProcessTree::isAlive() here is a
            // reliable probe regardless of the watchdog's own broken PATH.
            self::assertTrue(
                ProcessTree::isAlive($workerPid),
                'must fire: an unresolvable parent must not be treated as orphaned -- the worker must still be alive',
            );
        } finally {
            @\proc_terminate($watchdog, 9);
            \proc_close($watchdog);
            @\proc_terminate($worker, 9);
            \proc_close($worker);
            @\unlink($stderrFile);
        }
    }

    /**
     * @param list<string> $args
     */
    private function runWatchdog(array $args): int
    {
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = \proc_open(
            \array_merge([\PHP_BINARY, self::$bin], $args),
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
        );
        self::assertIsResource($proc, 'must be able to spawn the watchdog subprocess');

        return \proc_close($proc);
    }
}
