<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorRunner;
use Dpt\McpRectorWarm\Tests\Support\TempPath;
use PHPUnit\Framework\TestCase;

/**
 * #112: a call killed at its --call-timeout deadline must take EVERY process
 * spawned for it down with it, not only the one process this code spawned
 * directly. Rector's own config.php turns parallel mode on by default, so the
 * analysis itself -- and the wedged rule -- runs in a Rector worker that the
 * process we kill spawned through proc_open() (a grandchild on the cold path, a
 * great-grandchild on the forked one). Killing only the direct child orphaned
 * that worker: CI's runner cleanup reported "Terminate orphan process: ... (php)"
 * on macOS and Windows, and on Windows the orphan's open handle on the temp dir
 * made rmdir() fail.
 *
 * The fixture's rector.php appends "config:<pid>" to a file every time a process
 * loads it (the cold subprocess, the warm worker, every Rector worker), and its
 * one rule appends "rule:<pid>" before sleeping far past the deadline. After the
 * kill, every recorded pid must be gone -- except the warm worker's, which is the
 * paired control: the deadline kills the call, never the worker serving it.
 */
final class RectorRunnerProcessTreeKillTest extends TestCase
{
    private const DEADLINE_SECONDS = 8;

    public function testColdCallKilledAtItsDeadlineLeavesNoProcessBehind(): void
    {
        [$tmp, $pidFile] = self::makeWedgedProject('cold');
        $previousCwd = getcwd();
        self::assertNotFalse($previousCwd);
        $previousArgv = $_SERVER['argv'] ?? ['rector'];

        try {
            chdir($tmp);
            $_SERVER['argv'] = ['rector'];

            // The pre-#108 cold subprocess per call: since #108 only reached through
            // the MCP_RECTOR_WARM_NO_PCNTL=cold escape hatch, pinned here directly.
            $runner = new class (self::DEADLINE_SECONDS) extends RectorRunner {
                protected function canFork(): bool
                {
                    return false;
                }

                protected function noPcntlMode(): string
                {
                    return self::NO_PCNTL_MODE_COLD;
                }
            };

            try {
                $runner->run(['rector', 'process', '--no-progress-bar', '--', $tmp . '/src/Foo.php']);
                self::fail('expected the wedged cold call to be killed at its deadline');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('cold rector subprocess was killed', $e->getMessage());
            }

            $pids = self::recordedPids($pidFile);
            self::assertNotSame([], $pids['rule'], 'must fire: the wedged rule never ran, so nothing here was tested');

            self::assertSame(
                [],
                self::stillAlive(\array_values(\array_unique(\array_merge($pids['config'], $pids['rule'])))),
                'every process spawned for a timed-out cold call must be gone after the kill (#112)',
            );
        } finally {
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            self::removeProject($tmp);
        }
    }

    public function testForkedCallKilledAtItsDeadlineLeavesNoProcessBehindButKeepsTheWorker(): void
    {
        if (!\function_exists('posix_kill') || !\function_exists('pcntl_fork')) {
            self::markTestSkipped('posix_kill/pcntl_fork unavailable in this environment');
        }

        [$tmp, $pidFile] = self::makeWedgedProject('forked');
        $previousCwd = getcwd();
        self::assertNotFalse($previousCwd);
        $previousArgv = $_SERVER['argv'] ?? ['rector'];
        $runner = null;

        try {
            chdir($tmp);
            $_SERVER['argv'] = ['rector'];

            // The boot is setup, not what is under test (#113).
            $runner = new class (self::DEADLINE_SECONDS) extends RectorRunner {
                protected function bootDeadlineNs(): ?int
                {
                    return null;
                }
            };

            try {
                $runner->run(['rector', 'process', '--no-progress-bar', '--', $tmp . '/src/Foo.php']);
                self::fail('expected the wedged forked call to be killed at its deadline');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('the analysis was killed', $e->getMessage());
            }

            $workerPidProperty = new \ReflectionProperty(RectorRunner::class, 'workerPid');
            $workerPid = $workerPidProperty->getValue($runner);
            self::assertIsInt($workerPid);

            $pids = self::recordedPids($pidFile);
            self::assertNotSame([], $pids['rule'], 'must fire: the wedged rule never ran, so nothing here was tested');
            self::assertContains($workerPid, $pids['config'], 'the warm worker loads rector.php once, at boot');

            // Control: the kill is scoped to the call. The worker serving it
            // must survive, and the probe must see it as alive -- otherwise the
            // assertion below could pass on a probe that sees nothing at all.
            self::assertTrue($runner->isWarm(), 'a timed-out call must not take the warm worker down');
            self::assertTrue(self::isAlive($workerPid), 'control: the warm worker must still be alive');

            $spawnedForTheCall = \array_values(\array_diff(
                \array_unique(\array_merge($pids['config'], $pids['rule'])),
                [$workerPid],
            ));
            self::assertNotSame([], $spawnedForTheCall);
            self::assertSame(
                [],
                self::stillAlive($spawnedForTheCall),
                'every process spawned for a timed-out forked call must be gone after the kill (#112)',
            );
        } finally {
            $runner?->reboot();
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            self::removeProject($tmp);
        }
    }

    /**
     * #108: without pcntl the call runs IN the standby worker process, so the deadline
     * kill takes the worker's whole tree (worker + Rector's parallel workers) down --
     * there is no inner grandchild to scope it to -- and the next call must boot a
     * fresh worker rather than write to the dead one.
     */
    public function testStandbyWorkerCallKilledAtItsDeadlineLeavesNoProcessBehindAndTheNextCallReboots(): void
    {
        [$tmp, $pidFile] = self::makeWedgedProject('standby');
        // No class, so the wedged rule (Class_ nodes only) never fires on it.
        file_put_contents($tmp . '/src/plain.php', "<?php\n\ndeclare(strict_types=1);\n\n\$x = 1;\n");
        $previousCwd = getcwd();
        self::assertNotFalse($previousCwd);
        $previousArgv = $_SERVER['argv'] ?? ['rector'];
        $runner = null;

        try {
            chdir($tmp);
            $_SERVER['argv'] = ['rector'];

            $runner = new class (self::DEADLINE_SECONDS) extends RectorRunner {
                protected function canFork(): bool
                {
                    return false;
                }

                protected function noPcntlMode(): string
                {
                    return self::NO_PCNTL_MODE_STANDBY;
                }

                // The boot is setup, not what is under test (#113).
                protected function bootDeadlineNs(): ?int
                {
                    return null;
                }
            };

            try {
                $runner->run(['rector', 'process', '--no-progress-bar', '--', $tmp . '/src/Foo.php']);
                self::fail('expected the wedged standby-worker call to be killed at its deadline');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('--call-timeout', $e->getMessage());
                self::assertStringContainsString('warm worker process was killed', $e->getMessage());
            }

            $pids = self::recordedPids($pidFile);
            self::assertNotSame([], $pids['rule'], 'must fire: the wedged rule never ran, so nothing here was tested');
            self::assertNotSame([], $pids['config'], 'must fire: the worker process loaded rector.php');
            $killed = \array_values(\array_unique(\array_merge($pids['config'], $pids['rule'])));
            self::assertSame(
                [],
                self::stillAlive($killed),
                'the worker and every process spawned for a timed-out call must be gone after the kill (#112)',
            );
            self::assertFalse($runner->isWarm(), 'a killed worker must be forgotten, not left as a standby');

            // The next call reboots a fresh worker (positive control: it succeeds and
            // is served by a process that was not among the killed ones).
            $next = $runner->run(['rector', 'process', '--no-progress-bar', '--dry-run', '--', $tmp . '/src/plain.php']);
            self::assertFalse($next['warm_boot'], 'the call after a kill must boot a fresh worker');
            $fresh = \array_values(\array_diff(self::recordedPids($pidFile)['config'], $killed));
            self::assertNotSame([], $fresh, 'a fresh worker process must have loaded rector.php for the next call');
        } finally {
            // The next call left a standby booting with its cwd in $tmp: reboot()
            // kills and reaps it, so the directory is free to remove (Windows).
            $runner?->reboot();
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            @unlink($tmp . '/src/plain.php');
            self::removeProject($tmp);
        }
    }

    public function testLivenessProbeSeesALiveProcess(): void
    {
        // Positive control for the probe every "must be gone" assertion above
        // relies on: it must report this very process as alive.
        self::assertTrue(self::isAlive((int) \getmypid()));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function makeWedgedProject(string $label): array
    {
        $tmp = sys_get_temp_dir() . "/rector-runner-tree-kill-{$label}-" . bin2hex(random_bytes(8));
        mkdir($tmp);
        mkdir($tmp . '/src');
        $pidFile = $tmp . '/pids.txt';
        file_put_contents($tmp . '/src/Foo.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class Foo\n{\n}\n");
        $pidFileLiteral = var_export($pidFile, true);
        file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "use PhpParser\\Node;\n"
            . "use PhpParser\\Node\\Stmt\\Class_;\n"
            . "use Rector\\Config\\RectorConfig;\n"
            . "use Rector\\Rector\\AbstractRector;\n\n"
            . "file_put_contents({$pidFileLiteral}, 'config:' . getmypid() . \"\\n\", FILE_APPEND | LOCK_EX);\n\n"
            . "final class TreeKillWedgedRector extends AbstractRector\n"
            . "{\n"
            . "    public function getNodeTypes(): array\n"
            . "    {\n"
            . "        return [Class_::class];\n"
            . "    }\n\n"
            . "    public function refactor(Node \$node): ?Node\n"
            . "    {\n"
            . "        file_put_contents({$pidFileLiteral}, 'rule:' . getmypid() . \"\\n\", FILE_APPEND | LOCK_EX);\n"
            . "        sleep(120);\n\n"
            . "        return null;\n"
            . "    }\n"
            . "}\n\n"
            . "return RectorConfig::configure()->withRules([TreeKillWedgedRector::class]);\n",
        );

        return [$tmp, $pidFile];
    }

    /**
     * @return array{config: list<int>, rule: list<int>}
     */
    private static function recordedPids(string $pidFile): array
    {
        $pids = ['config' => [], 'rule' => []];
        $lines = is_file($pidFile) ? (array) file($pidFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        foreach ($lines as $line) {
            if (preg_match('/^(config|rule):(\d+)$/', (string) $line, $m) === 1) {
                $pids[$m[1]][] = (int) $m[2];
            }
        }

        return $pids;
    }

    /**
     * Polls briefly: a SIGKILLed orphan is reaped by init/launchd, and a
     * Windows process object goes away, a moment after the kill, not at it.
     *
     * @param list<int> $pids
     * @return list<int> the pids still alive after the grace period
     */
    private static function stillAlive(array $pids): array
    {
        $deadline = microtime(true) + 5.0;
        do {
            $alive = \array_values(\array_filter($pids, self::isAlive(...)));
            if ($alive === []) {
                return [];
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        return $alive;
    }

    private static function isAlive(int $pid): bool
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $out = (string) shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH /FO CSV 2>NUL');

            return str_contains($out, '"' . $pid . '"');
        }
        if (\function_exists('posix_kill')) {
            // Signal 0 probes existence without sending anything. EPERM (1)
            // still means "exists, owned by someone else".
            return posix_kill($pid, 0) || posix_get_last_error() === 1;
        }
        $out = (string) shell_exec('ps -o pid= -p ' . $pid . ' 2>/dev/null');

        return trim($out) !== '';
    }

    private static function removeProject(string $tmp): void
    {
        foreach (['/rector.php', '/pids.txt', '/src/Foo.php'] as $file) {
            if (is_file($tmp . $file)) {
                TempPath::unlink($tmp . $file);
            }
        }
        if (is_dir($tmp . '/src')) {
            TempPath::rmdir($tmp . '/src');
        }
        if (is_dir($tmp)) {
            TempPath::rmdir($tmp);
        }
    }
}
