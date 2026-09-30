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
            $result = ProcessTree::parentOf(\getmypid());
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
}
