<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Support\ProcessTree;
use PHPUnit\Framework\TestCase;

/**
 * #159: ProcessTree::parentOf() (and isAlive(), sharing the same private run()
 * probe) return null for two different reasons -- the pid/parent is simply
 * gone, or `ps`/`tasklist` could not be run at all -- with no way for a
 * caller to tell them apart. ProcessTree::lastProbeRanOk() is the new third
 * signal this adds: whether the most recent probe actually managed to
 * execute a command, independent of what it found. No such coverage existed
 * for ProcessTree at all before this file.
 */
final class ProcessTreeTest extends TestCase
{
    private static function currentPid(): int
    {
        $pid = \getmypid();
        self::assertNotFalse($pid, 'getmypid() unexpectedly failed');

        return $pid;
    }

    /**
     * Positive control for the test below: with `ps` fully available, a pid
     * this unlikely to exist must read as "no parent" AND the probe itself
     * must be reported as having run fine -- proving lastProbeRanOk() is a
     * real signal about the PROBE, not merely mirroring parentOf()'s own
     * null/non-null result.
     */
    public function testLastProbeRanOkIsTrueWhenPsRanFineAndFoundNothing(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('parentOf() is POSIX-only; always null on Windows');
        }

        $result = ProcessTree::parentOf(0x7FFFFFFE);

        self::assertNull($result, 'a pid this unlikely to exist must read as "no parent"');
        self::assertTrue(
            ProcessTree::lastProbeRanOk(),
            'the process-table probe itself must have run fine here -- this only forces the "not found" branch, not a probe failure',
        );
    }

    /**
     * #159's actual mechanism: with the process-table probe unable to run at
     * all (here, by making `ps` unresolvable via PATH -- the same failure
     * mode a `ps`-less container image would produce, which the issue itself
     * could not reproduce directly), parentOf() must still fail closed
     * (null, unchanged behaviour) but lastProbeRanOk() must now report the
     * failure distinctly from "ran fine, found nothing".
     */
    public function testLastProbeRanOkIsFalseWhenTheProbeCannotBeRunAtAll(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('this forces `ps` off PATH, which is POSIX-specific');
        }

        $previousPath = \getenv('PATH');

        try {
            \putenv('PATH=' . \sys_get_temp_dir() . '/nonexistent-path-for-159-test');
            $result = ProcessTree::parentOf(self::currentPid());
        } finally {
            \putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
        }

        self::assertNull($result, 'must still fail closed (null) exactly as before -- this only adds a signal, never changes the fallback');
        self::assertFalse(
            ProcessTree::lastProbeRanOk(),
            'with `ps` unresolvable, the probe itself must be reported as failed, not merely "found nothing"',
        );
    }

    /**
     * lastProbeRanOk() must be null before any probe has run in this process
     * -- distinct from both true and false, so a caller cannot mistake "no
     * probe has been attempted yet" for either outcome.
     *
     * Run in its own separate PHP process: every other test in this file (and
     * PHPUnit's own process-isolation setting for this suite) shares one PHP
     * process, so ProcessTree's static state has already been set by the time
     * any other test method runs -- only a fresh process genuinely starts
     * null.
     */
    public function testLastProbeRanOkIsNullBeforeAnyProbeHasRun(): void
    {
        $projectRoot = \dirname(__DIR__, 2);
        $script = 'require ' . \var_export($projectRoot . '/vendor/autoload.php', true) . ';'
            . 'echo \Dpt\McpRectorWarm\Support\ProcessTree::lastProbeRanOk() === null ? "NULL" : "NOT-NULL";';

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = \proc_open([\PHP_BINARY, '-r', $script], $descriptors, $pipes);
        self::assertIsResource($proc, 'must be able to spawn a fresh PHP process for this check');
        \fclose($pipes[0]);
        $out = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);

        self::assertSame('NULL', $out, 'lastProbeRanOk() must start null, before any probe has been attempted, in a fresh process');
    }

    /**
     * isAlive() was never exercised by any test before this one (#245). The
     * running PHP process itself is a trivially real positive case.
     */
    public function testIsAliveIsTrueForTheCurrentProcess(): void
    {
        self::assertTrue(ProcessTree::isAlive(self::currentPid()));
    }

    /**
     * Positive control for the test above: a pid this unlikely to exist must
     * read as not alive, proving isAlive() is not simply always true.
     */
    public function testIsAliveIsFalseForAPidThatDoesNotExist(): void
    {
        self::assertFalse(ProcessTree::isAlive(0x7FFFFFFE));
    }

    public function testIsAliveIsFalseForANonPositivePid(): void
    {
        self::assertFalse(ProcessTree::isAlive(0));
        self::assertFalse(ProcessTree::isAlive(-1));
    }

    /**
     * descendantsOf() was never exercised by any test before this one
     * (#245). Spawns a real child via proc_open() (no shell, no pcntl fork)
     * and confirms its pid shows up as a descendant of the current process --
     * the only way to prove this walks the real process table rather than
     * always returning [].
     */
    public function testDescendantsOfFindsARealSpawnedChild(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('descendantsOf() reads `ps`, which is POSIX-only');
        }
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open() must be available to spawn a real child to look for');
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        $child = \proc_open([\PHP_BINARY, '-r', 'sleep(5);'], $descriptors, $pipes);
        self::assertIsResource($child, 'must be able to spawn a real child process for this test');
        \fclose($pipes[0]);
        \fclose($pipes[1]);

        try {
            $status = \proc_get_status($child);
            $childPid = $status['pid'];

            $descendants = ProcessTree::descendantsOf(self::currentPid());

            self::assertContains(
                $childPid,
                $descendants,
                'a real child spawned via proc_open() must appear as a descendant of this process',
            );
        } finally {
            \proc_terminate($child, 9);
            \proc_close($child);
        }
    }

    /**
     * Review finding (first round): descendantsOf() returns [] both when a
     * pid genuinely has no descendants AND when the `ps` probe could not be
     * run at all (src/Support/ProcessTree.php:94-120, same ambiguity
     * lastProbeRanOk() exists to resolve for isAlive()/parentOf()). Without
     * also checking lastProbeRanOk(), this test would still pass on an
     * environment where the probe cannot run at all -- settled here by
     * asserting the probe itself is reported as having run fine, not merely
     * that it found nothing (mirrors testLastProbeRanOkIsTrueWhenPsRanFine...
     * above for the same class's isAlive()/parentOf() pair).
     */
    public function testDescendantsOfDoesNotIncludeUnrelatedProcesses(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('descendantsOf() reads `ps`, which is POSIX-only');
        }

        // A pid this unlikely to exist has no descendants at all -- proves
        // descendantsOf() does not simply return every pid in the table.
        self::assertSame([], ProcessTree::descendantsOf(0x7FFFFFFE));
        self::assertTrue(
            ProcessTree::lastProbeRanOk(),
            'the [] above must mean "no descendants", not "the ps probe itself could not run" -- '
                . 'without this, a ps-less environment would pass this assertion for the wrong reason',
        );
    }

    /**
     * killTree() was never exercised by any test before this one (#245).
     * NEVER pass getmypid() as $rootPid here: killTree() SIGSTOPs $rootPid
     * itself before enumerating descendants, so targeting the very process
     * running this test suite would freeze the test runner (hit once while
     * writing this file -- a warm PHPUnit worker process was left in state
     * T until a manual SIGCONT). Both tests below target a disposable child
     * process instead, never self.
     *
     * killTree($childPid) with no exclusion must actually kill the child --
     * proves killTree() has real effect rather than being a no-op.
     */
    public function testKillTreeKillsTheTargetProcess(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('killTree() SIGSTOP/SIGKILL freeze loop is POSIX-only; Windows uses taskkill /T');
        }
        if (!\function_exists('posix_kill')) {
            self::markTestSkipped('posix_kill() is needed to confirm the child died afterwards');
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        $child = \proc_open([\PHP_BINARY, '-r', 'sleep(10);'], $descriptors, $pipes);
        self::assertIsResource($child, 'must be able to spawn a real child process for this test');
        \fclose($pipes[0]);
        \fclose($pipes[1]);

        $status = \proc_get_status($child);
        $childPid = $status['pid'];

        ProcessTree::killTree($childPid);
        \proc_close($child);

        self::assertFalse(
            ProcessTree::isAlive($childPid),
            'killTree() on a real child with no exclusion must actually kill it',
        );
    }

    /**
     * $excludePids (#134) protects a DESCENDANT of $rootPid -- real callers
     * (bin/rector-warm-orphan-watchdog.php:120) pass `[\getmypid()]` where
     * the watchdog itself is a child of the worker it is killing, never the
     * root pid itself. This test mirrors that real shape: a genuine
     * grandchild of $rootPid, excluded, must come out of killTree() still
     * RUNNING -- not merely isAlive() (which also reads true for a
     * SIGSTOP'd process), confirmed with `ps -o stat=` so a process left
     * frozen in state T cannot pass as "excluded".
     *
     * (An earlier version of this test excluded $rootPid from its own kill
     * instead of a descendant. That is not the contract any real caller
     * uses, and it hits a real edge in killTree(): self::signal([$rootPid],
     * 'STOP') runs unconditionally before $excludePids is ever consulted,
     * so a root excluding itself ends up frozen in state T forever, not
     * left alone -- isAlive() cannot tell the difference since a SIGSTOP'd
     * process is still "alive" to posix_kill(pid, 0). Flagged in review;
     * replaced with this test of the actual contract rather than a fix to
     * an unreachable self-exclusion path no caller exercises.)
     */
    public function testKillTreeLeavesAnExcludedDescendantRunning(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('killTree() exclusion is POSIX-only (#134 taskkill /T has no per-pid exclusion)');
        }
        if (!\function_exists('posix_kill')) {
            self::markTestSkipped('posix_kill() is needed to confirm the excluded descendant afterwards');
        }

        // $root spawns $grandchild itself and prints its pid as the first
        // line of stdout before sleeping, so the test can read the real pid
        // without any IPC beyond a pipe.
        $rootScript = '$gc = proc_open([' . \var_export(\PHP_BINARY, true) . ', "-r", "sleep(10);"],'
            . ' [0 => ["pipe", "r"], 1 => ["file", "/dev/null", "w"], 2 => ["file", "/dev/null", "w"]], $p);'
            . 'fclose($p[0]); echo proc_get_status($gc)["pid"] . "\n"; sleep(10);';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        $root = \proc_open([\PHP_BINARY, '-r', $rootScript], $descriptors, $pipes);
        self::assertIsResource($root, 'must be able to spawn a real root process for this test');
        \fclose($pipes[0]);

        try {
            $rootPid = \proc_get_status($root)['pid'];
            $grandchildPid = (int) \trim((string) \fgets($pipes[1]));
            \fclose($pipes[1]);
            self::assertGreaterThan(0, $grandchildPid, 'must have read a real grandchild pid off the root process stdout');

            ProcessTree::killTree($rootPid, [$grandchildPid]);

            self::assertTrue(
                ProcessTree::isAlive($grandchildPid),
                'a genuinely excluded descendant must survive killTree() on its ancestor',
            );
            $stat = \trim((string) self::psOutput(['ps', '-o', 'stat=', '-p', (string) $grandchildPid]));
            self::assertStringNotContainsString(
                'T',
                $stat,
                "excluded descendant must still be RUNNING, not merely alive -- ps stat was '{$stat}'",
            );
        } finally {
            // SIGKILL is a pcntl constant, not a posix one -- this test's own
            // skip guard only checks posix_kill()'s availability, so a build
            // with posix but no pcntl must not hit "Undefined constant
            // SIGKILL" here (review finding: the first version of this line
            // did exactly that, with no fallback, unlike production
            // ProcessTree::signal()'s own `\defined('SIG' . $name)` guard).
            @\posix_kill($grandchildPid ?? 0, \defined('SIGKILL') ? \SIGKILL : 9);
            \proc_terminate($root, 9);
            \proc_close($root);
        }
    }

    /** @param list<string> $command */
    private static function psOutput(array $command): string
    {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        $process = \proc_open($command, $descriptors, $pipes);
        if (!\is_resource($process)) {
            return '';
        }
        $out = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \proc_close($process);

        return $out === false ? '' : $out;
    }

    public function testKillTreeIsANoOpForANonPositivePid(): void
    {
        // Must not throw -- the only observable behaviour for an invalid
        // root is that nothing happens. expectNotToPerformAssertions() makes
        // that requirement explicit rather than relying on an always-true
        // assertion, which static analysis correctly flags as pointless.
        self::expectNotToPerformAssertions();
        ProcessTree::killTree(0);
        ProcessTree::killTree(-1);
    }
}
