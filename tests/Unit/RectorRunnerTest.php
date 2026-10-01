<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorCallTimeoutException;
use Dpt\McpRectorWarm\RectorRunner;
use PHPUnit\Framework\TestCase;

final class RectorRunnerTest extends TestCase
{
    public function testIsWarmFalseBeforeBoot(): void
    {
        $runner = new RectorRunner();
        self::assertFalse($runner->isWarm());
    }

    /**
     * Control-flow pin for the no-pcntl fallback (#31, formerly #8): CI does not run
     * without pcntl (single ubuntu-latest job), so this exercises the branch
     * mechanically by forcing canFork() to report unavailable. Before #31, a warm
     * call rebooted the SAME process's container in place -- which crashed for real
     * whenever rector.php (or a withBootstrapFiles file) declared a class or
     * function, since that process had already required it once. Without pcntl there
     * is no OS-process boundary to isolate a reboot in at all, so run() must never
     * boot()/reboot() this instance in place any more: every call, including the
     * first, goes through runCold() (a genuinely fresh `php` subprocess per call) and
     * reports warm_boot=false. isWarm() must stay false throughout -- nothing here is
     * ever warm.
     */
    public function testRunWithoutForkSupportAlwaysRunsColdAndNeverBootsInPlace(): void
    {
        $runner = new class extends RectorRunner {
            /** @var list<string> */
            public array $log = [];

            protected function canFork(): bool
            {
                return false;
            }

            // #108: the cold escape hatch (MCP_RECTOR_WARM_NO_PCNTL=cold) -- the
            // default no-pcntl path is RectorRunnerStandbyWorkerTest's subject.
            protected function noPcntlMode(): string
            {
                return self::NO_PCNTL_MODE_COLD;
            }

            public function reboot(): void
            {
                $this->log[] = 'reboot';
            }

            protected function boot(): void
            {
                $this->log[] = 'boot';
            }

            /**
             * @param list<string> $argv
             * @return array{exit_code: int, output: string, warm_boot: bool}
             */
            protected function runCold(array $argv, bool $dryRun = true): array
            {
                $this->log[] = 'runCold';

                return ['exit_code' => 0, 'output' => '', 'warm_boot' => false];
            }
        };

        $first = $runner->run(['rector']);
        self::assertFalse($first['warm_boot'], 'the very first call is never warm');
        self::assertFalse($runner->isWarm(), 'without pcntl, nothing is ever warm');

        $second = $runner->run(['rector']);
        self::assertFalse(
            $second['warm_boot'],
            'without pcntl, every call is a fresh cold subprocess -- never a reused container',
        );
        self::assertFalse($runner->isWarm());
        self::assertSame(
            ['runCold', 'runCold'],
            $runner->log,
            'boot()/reboot() must never run in place without pcntl (#31): there is no process '
            . 'boundary available to isolate a reboot in, so run() must not call them at all',
        );
    }

    /**
     * #14: with no rector.php (and no --config on the server's own argv), Rector's
     * own ProcessCommand treats this as friendly onboarding -- it prints a warning
     * via a SymfonyStyle that writes straight to \STDOUT (bypassing our ob_*() wrap)
     * and reports Command::SUCCESS for a call that did nothing. boot() must refuse
     * before any of that -- a real, reported error, never a silent no-op -- and
     * must never mark the runner warm (isWarm() stays false, so a caller adding a
     * rector.php afterwards can simply retry the same call).
     */
    public function testRunThrowsWhenNoConfigResolvesAndNeverMarksWarm(): void
    {
        $tmp = sys_get_temp_dir() . '/rector-runner-test-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        $previousCwd = getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];

        try {
            chdir($tmp);
            // Neutralise the real process argv: RectorConfigsResolver reads --config
            // from $_SERVER['argv'] directly (see bin/mcp-rector-warm), and this
            // test's own argv (phpunit's) must not accidentally supply one.
            $_SERVER['argv'] = ['rector'];

            $runner = new RectorRunner();
            self::assertFalse($runner->isWarm());

            try {
                $runner->run(['rector', 'process']);
                self::fail('expected a RuntimeException for a missing config');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('No rector.php', $e->getMessage());
                self::assertStringContainsString('--config', $e->getMessage());
            }

            self::assertFalse($runner->isWarm(), 'a boot that refused must never leave the runner warm');
        } finally {
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            rmdir($tmp);
        }
    }

    /**
     * The fork-capable path (#20): boot() runs once in the parent and every later call is
     * isolated in a forked child (canFork() true), so nothing after the first call would
     * otherwise ever notice an edit to rector.php. run() must consult configFileChanged()
     * before deciding warm/cold, and reboot()+boot() before falling through to a call,
     * exactly when it reports a change -- never on an unchanged config, and never on the very
     * first call (nothing booted yet to go stale). runForked() is overridden to skip the real
     * pcntl_fork so this stays a fast, deterministic unit test; the real fork mechanics are
     * exercised end-to-end by tests/E2E/scenarios/rector-config-change.yaml and its siblings.
     */
    public function testConfigChangeForcesRebootBeforeAForkedWarmCall(): void
    {
        $runner = new class extends RectorRunner {
            /** @var list<string> */
            public array $log = [];
            public bool $configChanged = false;
            private bool $booted = false;

            protected function canFork(): bool
            {
                return true;
            }

            public function isWarm(): bool
            {
                return $this->booted;
            }

            public function reboot(): void
            {
                $this->log[] = 'reboot';
                $this->booted = false;
            }

            protected function boot(): void
            {
                $this->log[] = 'boot';
                $this->booted = true;
            }

            protected function configFileChanged(): bool
            {
                $this->log[] = 'config-check';

                return $this->configChanged;
            }

            /**
             * @param list<string> $argv
             * @return array{exit_code: int, output: string, warm_boot: bool}
             */
            protected function runForked(array $argv, bool $warmBoot, bool $dryRun = true): array
            {
                return $this->execute($argv, $warmBoot);
            }

            /**
             * @param list<string> $argv
             * @return array{exit_code: int, output: string, warm_boot: bool}
             */
            protected function execute(array $argv, bool $warmBoot): array
            {
                $this->log[] = 'execute:' . ($warmBoot ? 'warm' : 'cold');

                return ['exit_code' => 0, 'output' => '', 'warm_boot' => $warmBoot];
            }
        };

        $first = $runner->run(['rector']);
        self::assertFalse($first['warm_boot'], 'the very first call is never warm');
        self::assertSame(
            ['boot', 'execute:cold'],
            $runner->log,
            'nothing booted yet to go stale: the config check must not run before the first boot',
        );
        $runner->log = [];

        $second = $runner->run(['rector']);
        self::assertTrue($second['warm_boot'], 'unchanged config: the fork-capable path must stay warm');
        self::assertSame(['config-check', 'execute:warm'], $runner->log);
        $runner->log = [];

        $runner->configChanged = true;
        $third = $runner->run(['rector']);
        self::assertFalse($third['warm_boot'], 'a config change must force a fresh boot even though canFork() is true');
        self::assertSame(['config-check', 'reboot', 'boot', 'execute:cold'], $runner->log);
    }

    /**
     * #27, follow-up to #14: a rector.php that exists and loads fine, but
     * registers zero rules (and zero sets), takes a DIFFERENT route through
     * Rector's own ProcessCommand than a missing config file -- it is not
     * caught by boot()'s own getMainConfigFile() guard, which never fires
     * here (the file exists). Left unguarded, ProcessCommand::execute() hits
     * its own "!areSomeRectorsLoaded()" branch and prints onboarding text via
     * a SymfonyStyle bound to the real \STDOUT, bypassing ob_*() entirely,
     * then reports Command::SUCCESS for a no-op. execute() must refuse before
     * $application->run() is ever called -- a real, reported error. Unlike
     * boot()'s own no-config-file guard, this one fires one call later --
     * after boot() has already built a perfectly valid container from a
     * config that genuinely loads -- so isWarm() is expected to be TRUE
     * afterwards, and a second call against the same still-empty config
     * refuses identically rather than silently reusing a "warm" state that
     * can never do anything.
     *
     * isWarm() afterwards: with pcntl, boot() still builds a real container in a
     * worker before execute() refuses it, so isWarm() is true. Without pcntl it is
     * true too since #108: the refused call's worker process is retired and a fresh
     * standby is already booting for the next call. Only the
     * MCP_RECTOR_WARM_NO_PCNTL=cold escape hatch keeps it false there (every call a
     * disposable runCold() subprocess, #31) -- so the expectation is computed, not
     * hardcoded, and this test runs on the no-pcntl CI job as it is.
     */
    public function testRunThrowsWhenZeroRulesRegistered(): void
    {
        $tmp = sys_get_temp_dir() . '/rector-runner-test-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\nreturn RectorConfig::configure();\n",
        );
        $previousCwd = getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];
        $canFork = \function_exists('pcntl_fork')
            && \function_exists('pcntl_waitpid')
            && \function_exists('stream_socket_pair');
        $expectWarm = $canFork
            || \strtolower(\trim((string) \getenv(RectorRunner::NO_PCNTL_MODE_ENV))) !== RectorRunner::NO_PCNTL_MODE_COLD;
        $runner = null;

        try {
            chdir($tmp);
            $_SERVER['argv'] = ['rector'];

            $runner = new RectorRunner();
            self::assertFalse($runner->isWarm());

            try {
                $runner->run(['rector', 'process']);
                self::fail('expected a RuntimeException for a config with zero registered rules');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('registers no rules', $e->getMessage());
            }

            self::assertSame(
                $expectWarm,
                $runner->isWarm(),
                $expectWarm
                    ? 'a real container was built from a config that genuinely loaded (forked worker, or #108 standby); only execute() refused'
                    : 'MCP_RECTOR_WARM_NO_PCNTL=cold: every call is a disposable cold subprocess (#31); nothing is ever warm',
            );

            // The refusal is not a one-off: the same still-empty config refuses
            // identically on a warm reuse of the same container, never silently
            // succeeding once "warm".
            try {
                $runner->run(['rector', 'process']);
                self::fail('expected the second call to refuse identically');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('registers no rules', $e->getMessage());
            }
        } finally {
            // A worker (or #108 standby) with its cwd in $tmp would keep Windows
            // from removing the directory.
            $runner?->reboot();
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            unlink($tmp . '/rector.php');
            rmdir($tmp);
        }
    }

    /**
     * #31 follow-up: a worker killed (crashed, OOM-killed) between calls used to leave
     * isWarm() reporting true forever, since only an explicit reboot() ever cleared it.
     * Every subsequent call then threw "Failed writing a frame to the warm-worker
     * socket." -- a message RectorTool::isRecoverableWarmCorruption() never matches,
     * so the daemon stayed wedged until restarted externally. workerIsDead() (a
     * non-blocking pcntl_waitpid(..., WNOHANG) liveness check, run from run() before
     * trusting isWarm()) must reap the dead worker and boot a genuinely fresh one on
     * the very next call instead.
     */
    public function testDeadWorkerSelfHealsOnNextCall(): void
    {
        if (!\function_exists('posix_kill') || !\function_exists('pcntl_fork')) {
            self::markTestSkipped('posix_kill/pcntl_fork unavailable in this environment');
        }

        // A zero-rule config, same as testRunThrowsWhenZeroRulesRegistered above: boots
        // a real worker and refuses via execute()'s own guard BEFORE $application->run()
        // is ever called, so it never reaches Rector's/Symfony Console's real formatting
        // pipeline (which needs a real stdout tty and does not tolerate running twice,
        // once per real fork, inside a shared PHPUnit process). All this test needs is a
        // real worker to kill; which exact error it refuses each call with is incidental.
        $tmp = sys_get_temp_dir() . '/rector-runner-dead-worker-test-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\nreturn RectorConfig::configure();\n",
        );
        $previousCwd = getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];

        try {
            chdir($tmp);
            $_SERVER['argv'] = ['rector'];

            $runner = new RectorRunner();
            try {
                $runner->run(['rector', 'process']);
                self::fail('expected the zero-rule config to refuse');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('registers no rules', $e->getMessage());
            }
            self::assertTrue($runner->isWarm(), 'a real worker should have booted');

            $pidProperty = new \ReflectionProperty(RectorRunner::class, 'workerPid');
            $firstPid = $pidProperty->getValue($runner);
            self::assertIsInt($firstPid);

            \posix_kill($firstPid, \SIGKILL);
            // Reap it ourselves too so it never lingers as this test process's own
            // zombie regardless of what workerIsDead() does -- WNOHANG makes this safe
            // even if workerIsDead() already reaped it first.
            $status = 0;
            \pcntl_waitpid($firstPid, $status, \WNOHANG);

            // SIGKILL delivery/reaping is not instantaneous: the very next call can
            // legitimately race the kernel and hit runForked()'s own "worker closed its
            // connection unexpectedly" (or "Failed writing a frame...") path instead of
            // a clean fresh boot -- forgetDeadWorker() runs on THAT path too, so the
            // call after THAT is guaranteed a fresh boot. The bug this pins is a wedge
            // that repeats forever, never a single racy call; retry a bounded number of
            // times for exactly the same reason production code self-heals over
            // several calls rather than promising the very next one recovers.
            $recovered = false;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $runner->run(['rector', 'process']);
                    self::fail('expected the zero-rule refusal, from a freshly booted worker');
                } catch (\RuntimeException $e) {
                    if (str_contains($e->getMessage(), 'registers no rules')) {
                        $recovered = true;
                        break;
                    }
                }
            }
            self::assertTrue(
                $recovered,
                'a killed worker must self-heal into a fresh boot within a few calls, never wedge every later call identically',
            );
            self::assertTrue($runner->isWarm(), 'a fresh worker must have booted to replace the killed one');

            $secondPid = $pidProperty->getValue($runner);
            self::assertNotSame($firstPid, $secondPid, 'the NEW worker must be a different process, not the killed one');
        } finally {
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            unlink($tmp . '/rector.php');
            rmdir($tmp);
        }
    }

    /**
     * #71: killAndReap()'s WNOHANG branch fires when pcntl is available but
     * posix_kill is not -- nothing is signalled, so pcntl_waitpid() is called
     * with WNOHANG (never blocks) instead of the normal blocking form. This
     * combination has no CI leg (the no-pcntl job disables pcntl itself, which
     * disables both branches' precondition, not just posix_kill) and no
     * existing test isolates it -- every posix_kill-touching test above skips
     * when posix_kill is UNAVAILABLE, the opposite combination. Forces the
     * branch via hasPosixKill() (mirroring this file's own canFork() override
     * pattern), independent of what this environment actually has installed.
     *
     * Positive control: with hasPosixKill() true first (the branch every other
     * test already exercises), the child must actually be signalled dead --
     * proving the posix_kill($pid, 0) liveness probe below is a real signal
     * that can tell "alive" from "gone", not a broken check that would report
     * the same thing regardless of what killAndReap() did.
     */
    public function testKillAndReapWnohangBranchWithoutPosixNeverSignalsOrBlocks(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl_fork/pcntl_waitpid/posix_kill unavailable in this environment');
        }

        $runner = new class extends RectorRunner {
            public bool $posixAvailable = true;

            protected function hasPosixKill(): bool
            {
                return $this->posixAvailable;
            }
        };
        $method = new \ReflectionMethod(RectorRunner::class, 'killAndReap');

        // Positive control.
        $killedPid = \pcntl_fork();
        self::assertNotSame(-1, $killedPid, 'pcntl_fork() failed for the positive-control child');
        if ($killedPid === 0) {
            \sleep(30);
            exit(0);
        }
        $runner->posixAvailable = true;
        $method->invoke($runner, $killedPid);
        self::assertFalse(
            @\posix_kill($killedPid, 0),
            'positive control: with posix_kill available, the child must be signalled dead and reaped',
        );

        // The branch under test: posix_kill reported unavailable, pcntl still
        // is. Nothing must be signalled, and the WNOHANG wait must return
        // immediately instead of blocking on a child nothing ever told to stop.
        $wnohangPid = \pcntl_fork();
        self::assertNotSame(-1, $wnohangPid, 'pcntl_fork() failed for the WNOHANG-branch child');
        if ($wnohangPid === 0) {
            \sleep(30);
            exit(0);
        }
        $runner->posixAvailable = false;
        $start = \microtime(true);
        $method->invoke($runner, $wnohangPid);
        $elapsed = \microtime(true) - $start;

        try {
            self::assertLessThan(
                2.0,
                $elapsed,
                "WNOHANG must never block waiting for a process nothing signalled -- took {$elapsed}s",
            );
            self::assertTrue(
                @\posix_kill($wnohangPid, 0),
                'the WNOHANG branch must not signal the process: it must still be alive right after killAndReap() returns',
            );
        } finally {
            // Clean up the still-alive child ourselves; killAndReap()'s own
            // WNOHANG branch deliberately never signals it (that is the bug's
            // own reasoned-correct behaviour, see killAndReap()'s doc comment).
            @\posix_kill($wnohangPid, \SIGKILL);
            $status = 0;
            \pcntl_waitpid($wnohangPid, $status);
        }
    }

    /**
     * #58: PR #57 fixed #32 (a socket-read timeout during a slow-but-successful
     * call must not be mistaken for the worker dying) by retrying forever on
     * stream_get_meta_data()['timed_out'], with no cap of its own -- so a
     * genuinely WEDGED grandchild (not merely slow) blocked the caller forever
     * instead of erroring out the way the pre-#57 code accidentally did via
     * default_socket_timeout. A --call-timeout deadline must put a real upper
     * bound back: the call must fail within roughly its configured timeout
     * (never hang), name the timeout in its error message, and the worker must
     * come out the other side still usable -- not left wedged, not left with an
     * unreaped grandchild the caller can never account for again.
     *
     * The grandchild here never touches a real Rector container: execute() is
     * overridden to sleep() directly, so this pins forkAndExecute()'s OWN
     * deadline handling (the wait loop reading the worker<->grandchild socket)
     * in isolation from Rector's boot cost -- the E2E scenario for #58 is what
     * exercises the real SlowRector-over-MCP path end to end.
     */
    public function testWedgedCallIsKilledAtTheDeadlineAndTheWorkerStaysUsable(): void
    {
        if (!\function_exists('posix_kill') || !\function_exists('pcntl_fork')) {
            self::markTestSkipped('posix_kill/pcntl_fork unavailable in this environment');
        }

        $tmp = sys_get_temp_dir() . '/rector-runner-call-timeout-test-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\nreturn RectorConfig::configure();\n",
        );
        $previousCwd = getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];

        try {
            chdir($tmp);
            $_SERVER['argv'] = ['rector'];

            // 1s deadline: short enough that this test does not itself become
            // the next slow test in the suite. The worker's own boot (a real,
            // zero-rule Rector container) does NOT reliably finish within it
            // (#113), so bootDeadlineNs() below takes the boot out of it.
            // Whether THIS call sleeps travels in $argv, not in object state: the
            // worker forked once, at boot(), and keeps running its OWN copy of
            // $this from that instant on (serveWorker()'s loop) -- a later
            // mutation of $runner in THIS (daemon) process can never reach back
            // into an already-forked child. $argv, by contrast, is decoded fresh
            // by the worker on every request (serveWorker()'s readFrame() loop),
            // so it is the only per-call signal available here.
            $runner = new class(1) extends RectorRunner {
                // #113: a real zero-rule container build takes ~0.9s idle and
                // 1.3-1.6s under CPU load -- over this test's 1s budget, which
                // killed the BOOT and failed the timing bounds below with a
                // message that looked like the outer backstop. The boot is not
                // what this test measures; the call's two kill sites are.
                protected function bootDeadlineNs(): ?int
                {
                    return null;
                }

                protected function execute(array $argv, bool $warmBoot): array
                {
                    if (($argv[1] ?? null) === 'wedge') {
                        // Longer than any plausible deadline-detection latency; the
                        // assertion below is that run() returns in seconds, not that
                        // this sleep ever completes -- SIGKILL cuts it short.
                        sleep(30);
                    }

                    return ['exit_code' => 0, 'output' => '', 'warm_boot' => $warmBoot];
                }
            };

            // #113: boot the worker before the clock starts. The timing bounds
            // below are about the CALL's kill sites; a real boot inside the timed
            // window added 1-5s under load and failed the inner site's < 3s bound.
            self::assertSame(0, $runner->run(['rector'])['exit_code']);
            self::assertTrue($runner->isWarm(), 'the worker must be booted before the timed wedge call');

            $start = microtime(true);
            try {
                $runner->run(['rector', 'wedge']);
                self::fail('expected the wedged call to throw a timeout error');
            } catch (\RuntimeException $e) {
                // #81: assert a message pattern common to BOTH kill sites, not
                // which one fired -- forkAndExecute()'s inner deadline
                // ("... exceeded 1s (--call-timeout); the analysis was
                // killed") normally wins the race, but readExactly()'s outer
                // backstop ("... exceeded its configured --call-timeout
                // waiting on the warm worker") can fire instead under CPU
                // contention that eats RUN_FORKED_DEADLINE_GRACE_SECONDS --
                // see .claude/jit-context/paths/00-manual/deadline-grace-gap.md.
                // Both messages share this substring; only the inner one also
                // has the specific "exceeded 1s" figure, which is not this
                // test's concern (that a call-timeout fired at all, and
                // roughly on time -- checked below -- is).
                self::assertStringContainsString('rector call exceeded', $e->getMessage());
                self::assertStringContainsString('--call-timeout', $e->getMessage());
                $message = $e->getMessage();
            }
            $elapsed = microtime(true) - $start;
            // #81 review: tie the timing bound to WHICH kill site's message
            // came back, rather than one bound loose enough to accept both --
            // a 15s ceiling alone would also pass if forkAndExecute()'s inner
            // deadline check silently stopped firing at all (every call then
            // falling through to the ~6s outer backstop, which still reads as
            // "killed, and message is one of the two valid ones, and under
            // 15s"). The inner site fires at ~1s; the outer backstop only
            // after the extra RUN_FORKED_DEADLINE_GRACE_SECONDS (5s) on top.
            if (str_contains($message, 'exceeded 1s')) {
                self::assertLessThan(
                    3.0,
                    $elapsed,
                    "the inner kill site fired ('{$message}') but took {$elapsed}s -- it should be "
                    . 'close to the 1s deadline, not near the outer backstop\'s ~6s',
                );
            } else {
                self::assertGreaterThanOrEqual(
                    4.5,
                    $elapsed,
                    "the outer backstop fired ('{$message}') after only {$elapsed}s -- it should not "
                    . 'trip before the inner kill site has had its own chance to',
                );
            }
            self::assertLessThan(
                15.0,
                $elapsed,
                "the call must be killed at roughly its 1s deadline, not left to hang -- took {$elapsed}s "
                . '(this is the exact regression #58 reports: PR #57 removed the old upper bound)',
            );

            // #87: which assertion is correct here depends on WHICH kill site fired,
            // not one that holds unconditionally -- see
            // testRunForkedOuterBackstopKillsTheWholeWorkerNotOnlyTheGrandchild above
            // for the deterministic pin of the mechanism. The inner site kills only
            // the forked grandchild (worker survives); the outer/daemon-side backstop
            // also SIGKILLs and forgets the WHOLE worker (RectorRunner.php's
            // runForked() catch block), so isWarm() is false there, and the very next
            // call must self-heal into a brand-new worker rather than reuse this one.
            if (str_contains($message, 'exceeded 1s')) {
                self::assertTrue(
                    $runner->isWarm(),
                    'only the wedged GRANDCHILD should be killed -- the worker itself must survive a single '
                    . 'timed-out call so the very next one does not need a fresh boot',
                );

                // The worker must still be genuinely usable, not merely "isWarm()
                // reports true" -- a real call through it must succeed.
                $result = $runner->run(['rector', 'process']);
                self::assertSame(0, $result['exit_code']);
                self::assertTrue($result['warm_boot'], 'the SAME worker must have served this call, not a fresh boot');
            } else {
                self::assertFalse(
                    $runner->isWarm(),
                    'the outer backstop kills and forgets the WHOLE worker, not only the grandchild -- #87',
                );

                // Self-heal: the next call boots a brand-new worker instead of
                // hanging or repeating the failure forever.
                $result = $runner->run(['rector', 'process']);
                self::assertSame(0, $result['exit_code']);
                self::assertFalse($result['warm_boot'], 'the old worker was killed -- this call must boot a fresh one');
            }
        } finally {
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            unlink($tmp . '/rector.php');
            rmdir($tmp);
        }
    }

    /**
     * #81: pins the OTHER kill site's message deterministically, without
     * relying on CPU contention to make it fire. readExactly()'s outer
     * backstop deadline (RUN_FORKED_DEADLINE_GRACE_SECONDS past the inner
     * one) throws RectorCallTimeoutException with a message that does NOT
     * contain "exceeded 1s" -- only the inner kill site's message has that
     * specific figure. Reaching this via a real wedge + CPU contention is
     * exactly what flakes (the whole point of this issue); reaching it via
     * Reflection on an already-expired deadline is deterministic and
     * exercises the identical production code (src/RectorRunner.php's
     * readExactly()). This is the regression pin for the fix in the two
     * wedge tests above: their assertions must match BOTH this message and
     * the inner one, never just the inner one's numeric figure.
     */
    public function testReadExactlyOuterBackstopMessageSharesPatternButNotFigureWithInnerKillSite(): void
    {
        $runner = new RectorRunner(1);

        // A loopback TCP socket nobody ever writes to: portable across
        // platforms (unlike an AF_UNIX stream_socket_pair, which Windows does
        // not support), and its read reliably times out.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server, 'could not open a loopback TCP server for this test');

        // $server is already a live resource once the line above passes, so
        // everything from here on -- including a failed assertNotFalse($client,
        // ...) -- must close it. A review of this test (#81) found the
        // original version leaked $server's socket when $client failed to open
        // (assertNotFalse() throws past a try/finally that starts after it).
        try {
            $address = stream_socket_get_name($server, false);
            $client = stream_socket_client("tcp://{$address}", $errno, $errstr, 1.0);
            self::assertNotFalse($client, "could not connect to the loopback server: {$errstr}");

            try {
                stream_set_timeout($client, 0, 100_000);

                $method = new \ReflectionMethod(RectorRunner::class, 'readExactly');

                // Deadline already in the past: the very first timed-out read hits it.
                $method->invoke($runner, $client, 4, \hrtime(true) - 1_000_000_000);
                self::fail('expected readExactly() to throw once its deadline had already passed');
            } catch (RectorCallTimeoutException $e) {
                self::assertStringContainsString('rector call exceeded', $e->getMessage());
                self::assertStringContainsString('--call-timeout', $e->getMessage());
                self::assertStringNotContainsString(
                    'exceeded 1s',
                    $e->getMessage(),
                    'this outer/backstop message never carries the inner kill site\'s numeric figure -- '
                    . 'that difference is exactly what #81 flaked on',
                );
            } finally {
                fclose($client);
            }
        } finally {
            fclose($server);
        }
    }

    /**
     * #87: the daemon-side backstop (runForked()'s own deadline, callDeadlineNs()
     * with RUN_FORKED_DEADLINE_GRACE_SECONDS added on top) firing is not merely "a
     * different kill message" from the inner (worker-side) site -- its catch block
     * also SIGKILLs and reaps the WHOLE worker (killAndReap($this->workerPid)) and
     * forgets it (forgetDeadWorker(), which nulls workerPid), not only the forked
     * grandchild the inner site kills. isWarm() therefore goes FALSE once this path
     * fires. #87 reports exactly this: the two wedge tests below used to assert
     * isWarm() TRUE unconditionally after a kill, regardless of which site actually
     * fired, and one real run under CPU contention hit the outer site and flaked.
     *
     * Reached here deterministically -- a real, booted worker, but its daemon-side
     * socket swapped for a loopback nobody ever answers, so the outer backstop is
     * GUARANTEED to fire (not merely likely to win a race against real contention).
     * This costs the same ~6s (1s callTimeoutSeconds + RUN_FORKED_DEADLINE_GRACE_
     * SECONDS) as a real wedge, but never depends on CPU contention to reproduce --
     * the "what would settle it" reflection technique #87 asks for, applied to
     * runForked()'s side effect rather than only readExactly()'s message (already
     * covered by testReadExactlyOuterBackstopMessageSharesPatternButNotFigureWith
     * InnerKillSite above).
     */
    public function testRunForkedOuterBackstopKillsTheWholeWorkerNotOnlyTheGrandchild(): void
    {
        if (!\function_exists('posix_kill') || !\function_exists('pcntl_fork')) {
            self::markTestSkipped('posix_kill/pcntl_fork unavailable in this environment');
        }

        $tmp = sys_get_temp_dir() . '/rector-runner-outer-backstop-test-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\nreturn RectorConfig::configure();\n",
        );
        $previousCwd = getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];
        $originalSocket = null;

        try {
            chdir($tmp);
            $_SERVER['argv'] = ['rector'];

            // #113: the boot is only this test's setup, so keep it out of the 1s
            // budget; the outer backstop's own deadline is untouched.
            $runner = new class(1) extends RectorRunner {
                protected function bootDeadlineNs(): ?int
                {
                    return null;
                }
            };
            // A real boot(): the zero-rule config above makes the analysis itself
            // refuse ("registers no rules") one step later, in execute() --
            // workerPid/workerSocket are already set by the time this throws, same
            // as testWedgedBootIsKilledAtTheDeadline's fastConfig call.
            try {
                $runner->run(['rector', 'process']);
                self::fail('expected the zero-rule config to refuse, same as any other call against it');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('registers no rules', $e->getMessage());
            }
            self::assertTrue($runner->isWarm(), 'a real worker must be booted before this test can swap its socket');

            $workerSocketProperty = new \ReflectionProperty(RectorRunner::class, 'workerSocket');
            $originalSocket = $workerSocketProperty->getValue($runner);

            // A loopback TCP socket nobody ever writes to (same technique as
            // testReadExactlyOuterBackstopMessageSharesPatternButNotFigureWithInner
            // KillSite above): stand-in for the worker<->daemon socket, so
            // runForked()'s own read never gets a reply and its deadline is
            // guaranteed to expire, instead of merely being likely to under real
            // contention.
            $server = stream_socket_server('tcp://127.0.0.1:0');
            self::assertNotFalse($server, 'could not open a loopback TCP server for this test');

            try {
                $address = stream_socket_get_name($server, false);
                $client = stream_socket_client("tcp://{$address}", $errno, $errstr, 1.0);
                self::assertNotFalse($client, "could not connect to the loopback server: {$errstr}");

                $workerSocketProperty->setValue($runner, $client);

                $method = new \ReflectionMethod(RectorRunner::class, 'runForked');

                $start = microtime(true);
                try {
                    $method->invoke($runner, ['rector', 'process'], true, true);
                    self::fail('expected runForked() to throw once its own deadline expired');
                } catch (\RuntimeException $e) {
                    self::assertStringContainsString('rector call exceeded', $e->getMessage());
                    self::assertStringContainsString('--call-timeout', $e->getMessage());
                    self::assertStringNotContainsString(
                        'exceeded 1s',
                        $e->getMessage(),
                        'this must be the outer/daemon-side backstop, not the inner (worker-side) kill site',
                    );
                }
                $elapsed = microtime(true) - $start;
                self::assertGreaterThanOrEqual(
                    4.5,
                    $elapsed,
                    "the outer backstop must wait its own grace period, not trip early -- took only {$elapsed}s",
                );
                self::assertLessThan(15.0, $elapsed, "the outer backstop must still trip, not hang -- took {$elapsed}s");
            } finally {
                fclose($server);
            }

            // The point of this test: NOT merely a different message, but the WHOLE
            // worker being killed and forgotten as a side effect -- exactly what
            // #87's flake exposed the two wedge tests below getting wrong (an
            // unconditional assertTrue()).
            self::assertFalse(
                $runner->isWarm(),
                'the outer backstop kills and forgets the whole worker (killAndReap() + forgetDeadWorker()), '
                . 'not only a forked grandchild -- #87',
            );
        } finally {
            if (\is_resource($originalSocket)) {
                @fclose($originalSocket);
            }
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            unlink($tmp . '/rector.php');
            rmdir($tmp);
        }
    }

    /**
     * #72 correction: a --call-timeout deadline must NEVER kill a dryRun:false
     * (write) call, no matter how long it runs past what would have been the
     * deadline -- the call must complete normally, exactly as it did before
     * #58 introduced the deadline. Same wedge mechanism and fixture shape as
     * testWedgedCallIsKilledAtTheDeadlineAndTheWorkerStaysUsable just above
     * (execute() sleeps directly, isolating this from real Rector boot cost);
     * the only difference is the $dryRun flag passed to run().
     *
     * The positive control lives in the same test, immediately after: the
     * SAME wedge, the SAME 1s deadline, but dryRun:true -- proving the "must
     * not kill" assertion above is not trivially true because nothing here
     * ever fires at all.
     */
    public function testWedgedWriteCallIsNeverKilledButDryRunStillIsAtTheSameDeadline(): void
    {
        if (!\function_exists('posix_kill') || !\function_exists('pcntl_fork')) {
            self::markTestSkipped('posix_kill/pcntl_fork unavailable in this environment');
        }

        $tmp = sys_get_temp_dir() . '/rector-runner-write-no-kill-test-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\nreturn RectorConfig::configure();\n",
        );
        $previousCwd = getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];

        try {
            chdir($tmp);
            $_SERVER['argv'] = ['rector'];

            // 1s deadline, same as the sibling wedge test above; the wedge
            // itself sleeps 3s -- comfortably past the deadline, but short
            // enough that a passing test (dryRun:false must run to
            // completion, unkilled) does not itself become the slowest test
            // in the suite.
            $runner = new class(1) extends RectorRunner {
                // #113: keep the real boot out of the 1s budget, same as the
                // sibling wedge test above.
                protected function bootDeadlineNs(): ?int
                {
                    return null;
                }

                protected function execute(array $argv, bool $warmBoot): array
                {
                    if (($argv[1] ?? null) === 'wedge') {
                        sleep(3);
                    }

                    return ['exit_code' => 0, 'output' => '', 'warm_boot' => $warmBoot];
                }
            };

            // dryRun:false: must run to completion, past its own deadline,
            // never killed.
            $start = microtime(true);
            $result = $runner->run(['rector', 'wedge'], false);
            $elapsed = microtime(true) - $start;
            self::assertSame(0, $result['exit_code'], 'a write call must complete normally, never be killed');
            self::assertGreaterThanOrEqual(
                2.5,
                $elapsed,
                "a dryRun:false call must run to completion past its own 1s deadline (it slept 3s), not be "
                . "killed at the deadline -- took only {$elapsed}s",
            );
            self::assertTrue($runner->isWarm(), 'the worker must still be usable after an unkilled write call');

            // Positive control: the SAME wedge, the SAME deadline, but
            // dryRun:true -- this must still be killed at roughly the 1s
            // deadline, proving the guard above is not vacuously true.
            $start = microtime(true);
            try {
                $runner->run(['rector', 'wedge'], true);
                self::fail('expected the wedged dry-run call to throw a timeout error');
            } catch (\RuntimeException $e) {
                // #81: same shared-pattern reasoning as the sibling wedge test
                // above -- either kill site may have fired under contention.
                self::assertStringContainsString('rector call exceeded', $e->getMessage());
                self::assertStringContainsString('--call-timeout', $e->getMessage());
                $message = $e->getMessage();
            }
            $elapsed = microtime(true) - $start;
            // #81 review: same per-message timing bound as the sibling wedge
            // test above, so a 15s-only ceiling cannot pass a permanently
            // disabled inner kill site (see the comment there for why).
            if (str_contains($message, 'exceeded 1s')) {
                self::assertLessThan(
                    3.0,
                    $elapsed,
                    "the inner kill site fired ('{$message}') but took {$elapsed}s -- it should be "
                    . 'close to the 1s deadline, not near the outer backstop\'s ~6s',
                );
            } else {
                self::assertGreaterThanOrEqual(
                    4.5,
                    $elapsed,
                    "the outer backstop fired ('{$message}') after only {$elapsed}s -- it should not "
                    . 'trip before the inner kill site has had its own chance to',
                );
            }
            self::assertLessThan(
                15.0,
                $elapsed,
                "a dryRun:true call must still be killed at roughly its 1s deadline -- took {$elapsed}s",
            );
            // #87: same branching as the sibling wedge test above -- the outer
            // backstop kills and forgets the WHOLE worker (isWarm() false), not
            // only the grandchild the inner site kills. See
            // testRunForkedOuterBackstopKillsTheWholeWorkerNotOnlyTheGrandchild for
            // the deterministic pin.
            if (str_contains($message, 'exceeded 1s')) {
                self::assertTrue($runner->isWarm(), 'only the wedged grandchild is killed; the worker itself survives');
            } else {
                self::assertFalse(
                    $runner->isWarm(),
                    'the outer backstop kills and forgets the WHOLE worker, not only the grandchild -- #87',
                );
            }

            // (c): no zombie left behind under THIS process after the
            // dryRun:true kill above -- killAndReap()'s own reap must have
            // run. A real call through the CURRENT worker succeeding proves it
            // is not merely "isWarm() says true" but genuinely usable -- the
            // SAME worker when the inner site fired, a freshly self-healed one
            // when the outer backstop killed the old one -- and this process's
            // own descendant list must show no zombie/defunct entry either way.
            $result = $runner->run(['rector', 'process']);
            self::assertSame(0, $result['exit_code']);
            // Rooted at the WORKER's own pid, not this test process's pid:
            // other tests in this same PHPUnit run boot their own workers,
            // which linger as (unrelated, pre-existing, #58 trap.d-documented)
            // zombies of this process once their test ends -- scanning from
            // getmypid() would wrongly attribute those to this assertion.
            // The wedged grandchild killAndReap() just reaped is a child of
            // THIS worker specifically, so rooting here is exactly what #69
            // (recon: real zombie check) was pinning.
            $workerPidProperty = new \ReflectionProperty(RectorRunner::class, 'workerPid');
            $workerPid = $workerPidProperty->getValue($runner);
            self::assertIsInt($workerPid, 'the worker must have a known pid to scope the zombie check to');
            self::assertNoZombieDescendants($workerPid);
        } finally {
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            unlink($tmp . '/rector.php');
            rmdir($tmp);
        }
    }

    /**
     * Self-review finding: the three early-return arms below used to make a
     * green test indistinguishable from "the ps probe was unavailable, so
     * nothing was actually checked" -- markTestSkipped() instead, so an
     * environment missing shell_exec()/ps (disable_functions hardening, a
     * minimal container base image, or Windows) shows up as an honest skip
     * rather than a silent, uninformative pass.
     */
    private static function assertNoZombieDescendants(int $rootPid): void
    {
        if (!\function_exists('shell_exec') || \stripos(\PHP_OS, 'WIN') === 0) {
            self::markTestSkipped('shell_exec()/`ps` unavailable in this environment -- cannot confirm no zombie descendant');
        }
        $ps = @\shell_exec('ps -eo pid,ppid,stat 2>/dev/null');
        if (!\is_string($ps) || $ps === '') {
            self::markTestSkipped('`ps -eo pid,ppid,stat` produced no output in this environment -- cannot confirm no zombie descendant');
        }
        $byPpid = [];
        foreach (\explode("\n", \trim($ps)) as $i => $line) {
            if ($i === 0) {
                continue; // header
            }
            $parts = \preg_split('/\s+/', \trim($line));
            if (!\is_array($parts) || \count($parts) < 3) {
                continue;
            }
            [$pid, $ppid, $stat] = [(int) $parts[0], (int) $parts[1], $parts[2]];
            $byPpid[$ppid][] = [$pid, $stat];
        }
        $zombies = [];
        $frontier = [$rootPid];
        $seen = [];
        while ($frontier !== []) {
            $current = \array_pop($frontier);
            foreach ($byPpid[$current] ?? [] as [$pid, $stat]) {
                if (isset($seen[$pid])) {
                    continue;
                }
                $seen[$pid] = true;
                if (\str_contains($stat, 'Z')) {
                    $zombies[] = "{$pid} (stat={$stat})";
                }
                $frontier[] = $pid;
            }
        }
        self::assertSame([], $zombies, 'no zombie/defunct descendant must remain under pid ' . $rootPid);
    }

    /**
     * #58 follow-up: a worker that wedges DURING its own container build --
     * before it ever answers boot()'s handshake -- used to block the very first
     * run() call forever, with --call-timeout doing nothing (maintainer ruling:
     * this is in scope for #58, the same hang class the caller sees either way).
     * The wedge here is a real rector.php that sleep()s before returning its
     * config -- boot()'s handshake read genuinely has nothing to read until
     * that sleep finishes, so this is not a stand-in: it is the exact
     * mechanism a slow/hanging bootstrap file or a pathological rector.php
     * would trigger in production.
     */
    public function testWedgedBootIsKilledAtTheDeadline(): void
    {
        if (!\function_exists('posix_kill') || !\function_exists('pcntl_fork')) {
            self::markTestSkipped('posix_kill/pcntl_fork unavailable in this environment');
        }

        $tmp = sys_get_temp_dir() . '/rector-runner-boot-timeout-test-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        $wedgedConfig = "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\n"
            . "sleep(30);\n\nreturn RectorConfig::configure();\n";
        $fastConfig = "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\n"
            . "return RectorConfig::configure();\n";
        file_put_contents($tmp . '/rector.php', $wedgedConfig);
        $previousCwd = getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];

        try {
            chdir($tmp);
            $_SERVER['argv'] = ['rector'];

            // $unboundedBoot stays false for the wedged half: that boot's own
            // deadline is what is under test. #113: the fast-config half only
            // needs a boot to succeed, and a real one can itself outrun 1s
            // under load, so it lifts the bound there.
            $runner = new class(1) extends RectorRunner {
                public bool $unboundedBoot = false;

                protected function bootDeadlineNs(): ?int
                {
                    return $this->unboundedBoot ? null : parent::bootDeadlineNs();
                }
            };

            $start = microtime(true);
            try {
                $runner->run(['rector', 'process']);
                self::fail('expected the wedged boot to throw a timeout error');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('--call-timeout', $e->getMessage());
            }
            $elapsed = microtime(true) - $start;
            self::assertLessThan(
                15.0,
                $elapsed,
                "a boot wedged in its own container build must be killed at roughly its 1s deadline, "
                . "not left to hang -- took {$elapsed}s",
            );
            self::assertFalse($runner->isWarm(), 'a boot that never finished must never leave the runner warm');

            // "the next call works once the sleep is removed" -- same $runner
            // instance, same 1s deadline, but nothing left to wedge on this time.
            // A zero-rule RectorConfig still boots a real container successfully
            // (same as testRunThrowsWhenZeroRulesRegistered elsewhere in this
            // file); it only refuses one step later, in execute(), which is not
            // what this test is pinning -- the assertion here is that BOOT no
            // longer wedges, so isWarm() is what matters, not whether the call
            // that follows a successful boot happens to also succeed.
            file_put_contents($tmp . '/rector.php', $fastConfig);
            $runner->unboundedBoot = true;
            try {
                $runner->run(['rector', 'process']);
                self::fail('expected the zero-rule config to refuse, same as any other call against it');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('registers no rules', $e->getMessage());
            }
            self::assertTrue($runner->isWarm(), 'a fresh boot against a config that no longer wedges must succeed');
        } finally {
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            unlink($tmp . '/rector.php');
            rmdir($tmp);
        }
    }

    /**
     * #113: macOS CI saw "rector call exceeded its configured --call-timeout
     * waiting on the warm worker" -- runForked()'s OUTER backstop message --
     * after only ~1.02s, in a test whose outer deadline is 1s + the 5s
     * RUN_FORKED_DEADLINE_GRACE_SECONDS. It was not the outer backstop at all:
     * boot()'s handshake read goes through the same readExactly() and threw the
     * same message on its own, ungraced 1s deadline, because a real zero-rule
     * container build takes ~0.9s idle and 1.3-1.6s under CPU load. Two halves:
     *
     * 1. A boot killed at the deadline must say it was the boot, never borrow
     *    the outer backstop's words -- so "outer backstop at ~1s" is impossible
     *    by construction again, not merely unlikely.
     * 2. bootDeadlineNs() is the seam the wedge tests use to keep a real boot out
     *    of the 1s budget they measure the CALL against. Same slow config, same
     *    1s --call-timeout: the plain runner is killed (the must-fire control
     *    for half 1), the one whose bootDeadlineNs() is unbounded boots fine.
     */
    public function testBootTimeoutReportsItsOwnMessageAndItsDeadlineIsOverridable(): void
    {
        if (!\function_exists('posix_kill') || !\function_exists('pcntl_fork')) {
            self::markTestSkipped('posix_kill/pcntl_fork unavailable in this environment');
        }

        $tmp = sys_get_temp_dir() . '/rector-runner-boot-message-test-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        // 2.5s: past the 1s --call-timeout, well short of the outer backstop's
        // 1s + 5s grace, so the only kill site that can fire in half 1 is boot's.
        file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\n"
            . "usleep(2_500_000);\n\nreturn RectorConfig::configure();\n",
        );
        $previousCwd = getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];

        try {
            chdir($tmp);
            $_SERVER['argv'] = ['rector'];

            $message = '';
            $start = microtime(true);
            try {
                (new RectorRunner(1))->run(['rector', 'process']);
                self::fail('expected the slow boot to be killed at its 1s --call-timeout');
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }
            $elapsed = microtime(true) - $start;
            self::assertStringContainsString('--call-timeout', $message);
            self::assertStringContainsString('booting', $message, "the boot kill site must name itself -- got '{$message}'");
            self::assertStringNotContainsString(
                'waiting on the warm worker',
                $message,
                'a boot timeout must never report itself with runForked()\'s outer-backstop message (#113)',
            );
            self::assertStringNotContainsString(
                'exceeded 1s',
                $message,
                'nor with the figure the wedge tests use to recognise forkAndExecute()\'s inner kill site',
            );
            self::assertLessThan(2.4, $elapsed, "the boot must be killed at its 1s deadline -- took {$elapsed}s");

            $runner = new class(1) extends RectorRunner {
                protected function bootDeadlineNs(): ?int
                {
                    return null;
                }
            };
            try {
                $runner->run(['rector', 'process']);
                self::fail('expected the zero-rule config to refuse once booted');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('registers no rules', $e->getMessage());
            }
            self::assertTrue($runner->isWarm(), 'an unbounded bootDeadlineNs() must let the same slow boot finish');
            $runner->reboot();
        } finally {
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            unlink($tmp . '/rector.php');
            rmdir($tmp);
        }
    }

    /**
     * #58, no-pcntl fallback: runCold()'s own deadline enforcement
     * (proc_get_status()/proc_terminate() polling, added because proc_close()
     * used to block unconditionally) has a DIFFERENT kill mechanism than the two
     * pcntl-path loops (posix_kill()+pcntl_waitpid() there vs. proc_terminate()
     * here) and is otherwise untested. Forces canFork() false so this always
     * exercises the fallback, independent of whether pcntl happens to be
     * available in whatever environment runs this suite -- unlike the pcntl
     * tests above, this one needs no skip guard.
     *
     * This spawns a REAL bin/rector-cold-call.php subprocess and boots a real
     * (tiny) Rector container in it, so it is slower than the other unit tests
     * here; the wedge itself is a rule that sleep()s, the same technique as
     * tests/E2E/scenarios/slow-call-over-socket-timeout.yaml and this file's
     * own wedged-call test above.
     */
    public function testColdCallIsKilledAtItsDeadlineWithoutPcntl(): void
    {
        $tmp = sys_get_temp_dir() . '/rector-runner-cold-timeout-test-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        mkdir($tmp . '/src');
        file_put_contents(
            $tmp . '/src/Foo.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class Foo\n{\n}\n",
        );
        file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "use PhpParser\\Node;\n"
            . "use PhpParser\\Node\\Stmt\\Class_;\n"
            . "use Rector\\Config\\RectorConfig;\n"
            . "use Rector\\Rector\\AbstractRector;\n\n"
            . "final class WedgedColdRector extends AbstractRector\n"
            . "{\n"
            . "    public function getNodeTypes(): array\n"
            . "    {\n"
            . "        return [Class_::class];\n"
            . "    }\n\n"
            . "    public function refactor(Node \$node): ?Node\n"
            . "    {\n"
            . "        sleep(30);\n\n"
            . "        return null;\n"
            . "    }\n"
            . "}\n\n"
            . "return RectorConfig::configure()->withRules([WedgedColdRector::class]);\n",
        );
        $previousCwd = getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];

        try {
            chdir($tmp);
            $_SERVER['argv'] = ['rector'];

            // 5s deadline: generous room over the real container-boot cost this
            // path pays every call (no warm reuse, by construction), while still
            // far under the sleep(30) the rule itself would otherwise block on.
            $runner = new class(5) extends RectorRunner {
                protected function canFork(): bool
                {
                    return false;
                }

                // runCold() is under test: since #108 only the escape hatch reaches it.
                protected function noPcntlMode(): string
                {
                    return self::NO_PCNTL_MODE_COLD;
                }
            };

            $start = microtime(true);
            try {
                $runner->run(['rector', 'process', '--', $tmp . '/src/Foo.php']);
                self::fail('expected the wedged cold call to throw a timeout error');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('--call-timeout', $e->getMessage());
                self::assertStringContainsString('cold rector subprocess', $e->getMessage());
            }
            $elapsed = microtime(true) - $start;
            self::assertLessThan(
                25.0,
                $elapsed,
                "the cold subprocess must be killed at roughly its 5s deadline, not left to hang -- took {$elapsed}s",
            );
        } finally {
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            unlink($tmp . '/rector.php');
            unlink($tmp . '/src/Foo.php');
            rmdir($tmp . '/src');
            rmdir($tmp);
        }
    }

    /**
     * #73: on POSIX PHP < 8.3, proc_close() returns -1 for a process whose exit
     * status was already consumed by an earlier proc_get_status() call -- exactly
     * what runCold()'s deadline-poll loop does (#58) once it observes the child
     * has stopped running. The real exit code was already sitting in
     * $procStatus['exitcode'] at that point; discarding it in favour of
     * proc_close()'s return value turns a real, specific exit code into an
     * always-"-1" diagnostic. Forces canFork() false (same fixture convention as
     * testColdCallIsKilledAtItsDeadlineWithoutPcntl above) and a rule that calls
     * exit(7) directly -- bypassing bin/rector-cold-call.php's own try/catch
     * entirely, so no result file is ever written and the deadline-poll branch is
     * the one that must report the exit code -- with a deadline generous enough
     * that the call finishes well before it fires, so this is the "child exited
     * on its own" path, not the "child was killed at its deadline" path above.
     */
    public function testColdCallReportsRealExitCodeWithoutPcntl(): void
    {
        $tmp = sys_get_temp_dir() . '/rector-runner-cold-exitcode-test-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        mkdir($tmp . '/src');
        file_put_contents(
            $tmp . '/src/Foo.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class Foo\n{\n}\n",
        );
        file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "use PhpParser\Node;\n"
            . "use PhpParser\Node\Stmt\Class_;\n"
            . "use Rector\Config\RectorConfig;\n"
            . "use Rector\Rector\AbstractRector;\n\n"
            . "final class ExitingColdRector extends AbstractRector\n"
            . "{\n"
            . "    public function getNodeTypes(): array\n"
            . "    {\n"
            . "        return [Class_::class];\n"
            . "    }\n\n"
            . "    public function refactor(Node \$node): ?Node\n"
            . "    {\n"
            . "        exit(7);\n"
            . "    }\n"
            . "}\n\n"
            . "return RectorConfig::configure()->withRules([ExitingColdRector::class]);\n",
        );
        $previousCwd = getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];

        try {
            chdir($tmp);
            $_SERVER['argv'] = ['rector'];

            // 30s deadline: far above what this call takes, so the deadline-poll
            // loop's "child stopped running on its own" branch fires, never its
            // "child hit the deadline" branch -- the branch this test is not about.
            $runner = new class(30) extends RectorRunner {
                protected function canFork(): bool
                {
                    return false;
                }

                // runCold() is under test: since #108 only the escape hatch reaches it.
                protected function noPcntlMode(): string
                {
                    return self::NO_PCNTL_MODE_COLD;
                }
            };

            // Not self::fail() inside this try: PHPUnit's AssertionFailedError
            // extends RuntimeException, so a self::fail() call here would itself
            // be swallowed by the catch below instead of failing the test.
            $exceptionMessage = null;
            try {
                // --max-changes forces Rector's own non-parallel run (its
                // default worker-process pool would otherwise catch the
                // exit(7) as a generic "Child process error" and exit 1
                // itself, losing the real code before it ever reaches
                // runCold()).
                $runner->run(['rector', 'process', '--max-changes=1', '--', $tmp . '/src/Foo.php']);
            } catch (\RuntimeException $e) {
                $exceptionMessage = $e->getMessage();
            }
            self::assertNotNull($exceptionMessage, 'expected the exit(7) rule to make the cold call report a failure');
            self::assertStringContainsString(
                'exit 7',
                $exceptionMessage,
                'the real exit code observed by proc_get_status() must be reported, '
                . 'not proc_close()\'s own return value -- got: ' . $exceptionMessage,
            );
        } finally {
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            unlink($tmp . '/rector.php');
            unlink($tmp . '/src/Foo.php');
            rmdir($tmp . '/src');
            rmdir($tmp);
        }
    }

    /**
     * #63: resolveBootstrapFileHashes() fails open (returns []) when
     * SimpleParameterProvider::provideArrayParameter() throws, so a boot with a
     * genuinely broken bootstrap-file resolution looks identical -- from the
     * return value alone -- to a config that registers none. That is deliberate
     * (a broken file-list must never break an otherwise-successful boot), but it
     * must not also be SILENT: the catch branch must write one line to stderr so
     * the failure is visible in server logs instead of vanishing.
     *
     * Exercised directly against the private method via Reflection: forcing
     * SimpleParameterProvider itself to throw would need a real Rector container
     * boot with something upstream deliberately broken, which is what the E2E
     * scenario for #33 already covers for the success path -- this pins the
     * catch branch's own side effect (the stderr line) in isolation, the one
     * thing a green #33 scenario cannot tell apart from "no bootstrap files".
     */
    public function testBootstrapFileHashResolutionFailureIsLoggedNotSilent(): void
    {
        $runner = new RectorRunner();
        $method = new \ReflectionMethod(RectorRunner::class, 'resolveBootstrapFileHashes');

        // provideArrayParameter() only throws when the stored value is not an
        // array (Webmozart\Assert::isArray()) -- an unset key defaults to [] and
        // never throws at all. Forcing the SAME failure a real upstream rename of
        // Option::BOOTSTRAP_FILES or a SimpleParameterProvider internals change
        // would produce (provideArrayParameter() asked for something that is not
        // an array) needs setting the parameter to a non-array value directly;
        // this is the static registry the real boot path populates, reset after.
        \Rector\Configuration\Parameter\SimpleParameterProvider::setParameter('bootstrap_files', 'not-an-array');

        // fwrite(STDERR, ...) writes straight to the real fd, independent of the
        // display_errors ini setting (that one only governs PHP's own
        // notice/warning display) -- a stream filter appended to the STDERR
        // resource captures every byte written to it without touching the real
        // fd or needing a subprocess.
        stream_filter_register('rrt63capture', RectorRunnerTest63CaptureFilter::class);
        RectorRunnerTest63CaptureFilter::$captured = '';
        $filter = stream_filter_append(STDERR, 'rrt63capture', STREAM_FILTER_WRITE);
        self::assertNotFalse($filter);

        try {
            $result = $method->invoke($runner, \time());
        } finally {
            stream_filter_remove($filter);
            \Rector\Configuration\Parameter\SimpleParameterProvider::setParameter('bootstrap_files', []);
        }

        self::assertSame([], $result, 'must still fail OPEN: a resolution failure returns [], never throws');
        self::assertStringContainsString(
            '#63',
            RectorRunnerTest63CaptureFilter::$captured,
            'the catch branch must write a diagnostic line to stderr instead of failing silently: got ' . var_export(RectorRunnerTest63CaptureFilter::$captured, true),
        );
    }

    /**
     * Same stderr-capture technique as testBootstrapFileHashResolutionFailureIsLoggedNotSilent()
     * above, generalised so the #156/#157 tests below do not each need their own
     * named filter class.
     */
    private static function captureStderr(callable $body): string
    {
        if (!\in_array('rrtGenericCapture', \stream_get_filters(), true)) {
            \stream_filter_register('rrtGenericCapture', RectorRunnerTestGenericCaptureFilter::class);
        }
        RectorRunnerTestGenericCaptureFilter::$captured = '';
        $filter = \stream_filter_append(\STDERR, 'rrtGenericCapture', \STREAM_FILTER_WRITE);
        self::assertNotFalse($filter);
        try {
            $body();
        } finally {
            \stream_filter_remove($filter);
        }

        return RectorRunnerTestGenericCaptureFilter::$captured;
    }

    /**
     * #157: getmypid() returning false must not silently disable #127's mid-call
     * orphan-kill detection with no signal. Forcing the real builtin itself to
     * return false is not achievable from a test (a documented-but-essentially-
     * never-seen PHP edge case, per the issue's own text) -- exercised via
     * currentPid(), the same overridable-method test seam canFork()/
     * hasPosixKill() already use, isolating the ONE decision boot() makes with
     * it (daemonPidOrWarn()) through Reflection, independent of a real fork.
     */
    public function testGetmypidFailureIsLoggedNotSilentlyDisablingOrphanDetection(): void
    {
        $runner = new class extends RectorRunner {
            protected function currentPid()
            {
                return false;
            }
        };
        $method = new \ReflectionMethod(RectorRunner::class, 'daemonPidOrWarn');

        $result = null;
        $captured = self::captureStderr(function () use (&$result, $method, $runner): void {
            $result = $method->invoke($runner);
        });

        self::assertNull($result, 'must still fail open: a null daemonPid is the existing, correct fallback -- this only adds a signal');
        self::assertStringContainsString(
            '#157',
            $captured,
            'getmypid() returning false must write a diagnostic to stderr instead of silently disabling orphan-kill detection: got ' . var_export($captured, true),
        );
    }

    /**
     * Positive control for the test above: the ordinary case (getmypid()
     * succeeds) must NOT log anything -- otherwise the assertion above would
     * pass even if every call logged unconditionally.
     */
    public function testGetmypidSuccessIsNotLogged(): void
    {
        $runner = new RectorRunner();
        $method = new \ReflectionMethod(RectorRunner::class, 'daemonPidOrWarn');

        $result = null;
        $captured = self::captureStderr(function () use (&$result, $method, $runner): void {
            $result = $method->invoke($runner);
        });

        self::assertIsInt($result, 'a real, successful getmypid() must still return the pid unchanged');
        self::assertSame('', $captured, 'a real, successful getmypid() must not be logged as a failure');
    }

    /**
     * #156: ini_get_all() itself returning false (could not enumerate
     * directives at all) must not read identically to "genuinely nothing to
     * forward" -- previously collapsed by a `?: []`. No concrete trigger for
     * the real builtin returning false has been constructed in this daemon's
     * own runtime (issue's own stated limit), so this is exercised directly
     * against collectIniOverrideArgsFrom(), the branch #156 extracted for
     * exactly this, passing `false` the same way \ini_get_all(null, true)
     * itself would on failure.
     */
    public function testIniGetAllFailureIsLoggedNotSilentlyTreatedAsNothingToForward(): void
    {
        $method = new \ReflectionMethod(RectorRunner::class, 'collectIniOverrideArgsFrom');

        $result = null;
        $captured = self::captureStderr(function () use (&$result, $method): void {
            $result = $method->invoke(null, false);
        });

        self::assertSame([], $result, 'must still fail open: no ini_get_all() means no -d overrides forwarded, never a thrown error');
        self::assertStringContainsString(
            '#156',
            $captured,
            'ini_get_all() returning false must write a diagnostic to stderr instead of silently reading as "nothing to forward": got ' . var_export($captured, true),
        );
    }

    /**
     * Positive control for the test above: a genuinely-empty result (the
     * ordinary, common case) must NOT trip the new warning.
     */
    public function testIniGetAllGenuineEmptyResultIsNotLogged(): void
    {
        $method = new \ReflectionMethod(RectorRunner::class, 'collectIniOverrideArgsFrom');

        $result = null;
        $captured = self::captureStderr(function () use (&$result, $method): void {
            $result = $method->invoke(null, []);
        });

        self::assertSame([], $result);
        self::assertSame('', $captured, 'a genuinely-empty ini_get_all() result must not be logged as a failure');
    }

    /**
     * Self-review finding on #157: spawnOrphanWatchdog() also reads
     * \getmypid() raw (its own pid, passed as the watchdog's workerPid argv)
     * -- a false there used to become an empty string, then (int) '' === 0,
     * silently tripping the watchdog script's own <= 0 guard and exiting
     * immediately with no signal (the same #134-inert shape #159 fixes one
     * level further in). Fixed by skipping the spawn outright (matching the
     * existing $daemonPid === null "best effort, no watchdog" shape already
     * one branch up) instead of spawning one guaranteed to fail silently.
     */
    public function testSpawnOrphanWatchdogSkipsSpawningAndLogsWhenOwnPidIsFalse(): void
    {
        $runner = new class extends RectorRunner {
            protected function currentPid()
            {
                return false;
            }
        };
        $method = new \ReflectionMethod(RectorRunner::class, 'spawnOrphanWatchdog');

        $result = 'not set';
        $captured = self::captureStderr(function () use (&$result, $method, $runner): void {
            $result = $method->invoke($runner, 12345);
        });

        self::assertNull($result, 'must fail open exactly like the existing $daemonPid === null branch: no watchdog spawned, never a thrown error');
        self::assertStringContainsString(
            '#157',
            $captured,
            'getmypid() returning false here must also be logged, not silently produce a watchdog doomed to exit immediately: got ' . var_export($captured, true),
        );
    }

    /**
     * Positive control for the test above: with a real, working pid, a
     * watchdog must actually be spawned (a resource, not null) and nothing
     * logged.
     */
    public function testSpawnOrphanWatchdogSpawnsNormallyWhenOwnPidIsReal(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open unavailable in this environment');
        }

        $runner = new RectorRunner();
        $method = new \ReflectionMethod(RectorRunner::class, 'spawnOrphanWatchdog');

        $result = 'not set';
        $captured = self::captureStderr(function () use (&$result, $method, $runner): void {
            $result = $method->invoke($runner, \getmypid());
        });

        try {
            self::assertIsResource($result, 'a real, working pid must still spawn a watchdog exactly as before');
            self::assertSame('', $captured, 'a real, successful getmypid() must not be logged as a failure');
        } finally {
            if (\is_resource($result)) {
                @\proc_terminate($result, 9);
                @\proc_close($result);
            }
        }
    }

    /**
     * Second-review-round finding: spawnProcWorker() (the no-pcntl standby
     * worker's own spawn) also went through logGetmypidFailure() in the same
     * commit as spawnOrphanWatchdog()'s fix, but had no test of its own --
     * only spawnOrphanWatchdog()'s branch was covered. This is that missing
     * test: spawnProcWorker() itself only spawns (does not wait for the
     * worker to connect back -- that is awaitProcWorkerReady()'s job), so it
     * is cheap to isolate via Reflection and clean up with
     * discardProcWorker() afterward.
     */
    public function testSpawnProcWorkerLogsWhenOwnPidIsFalse(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open unavailable in this environment');
        }

        $runner = new class extends RectorRunner {
            protected function currentPid()
            {
                return false;
            }
        };
        $method = new \ReflectionMethod(RectorRunner::class, 'spawnProcWorker');
        $discard = new \ReflectionMethod(RectorRunner::class, 'discardProcWorker');

        try {
            $captured = self::captureStderr(function () use ($method, $runner): void {
                $method->invoke($runner);
            });

            self::assertStringContainsString(
                '#157',
                $captured,
                'getmypid() returning false in spawnProcWorker() must also be logged: got ' . var_export($captured, true),
            );
        } finally {
            $discard->invoke($runner, true);
        }
    }

    /**
     * Positive control for the test above: a real, successful getmypid()
     * must not be logged as a failure.
     */
    public function testSpawnProcWorkerDoesNotLogWhenOwnPidIsReal(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open unavailable in this environment');
        }

        $runner = new RectorRunner();
        $method = new \ReflectionMethod(RectorRunner::class, 'spawnProcWorker');
        $discard = new \ReflectionMethod(RectorRunner::class, 'discardProcWorker');

        try {
            $captured = self::captureStderr(function () use ($method, $runner): void {
                $method->invoke($runner);
            });

            self::assertSame('', $captured, 'a real, successful getmypid() must not be logged as a failure');
        } finally {
            $discard->invoke($runner, true);
        }
    }

    /**
     * #74: a non-UTF-8 bootstrap file path (#33) as a $this->bootstrapFileHashes
     * key made the boot handshake's plain json_encode() return false -- cast to
     * '' -- so boot() saw an empty/undecodable handshake and reported a
     * misleading "the warm worker failed to boot (exit status N)" instead of
     * the real cause. Exercised directly against the private
     * encodeHandshakeFrame() helper via Reflection: a real end-to-end repro
     * would need an actual file whose PATH is invalid UTF-8, which POSIX
     * filesystems that reject non-UTF-8 names (APFS, this dev machine) cannot
     * hold at all -- ext4 (this repo's ubuntu-latest CI) permits it, but this
     * unit test pins the exact mechanism without depending on filesystem
     * encoding enforcement either way. The invalid byte sits in an array KEY
     * here, matching bootInPlace()'s own
     * $this->bootstrapFileHashes[$bootstrapFile] shape (the path is the key,
     * not the value).
     */
    public function testHandshakeFrameSubstitutesInvalidUtf8InsteadOfDroppingThePayload(): void
    {
        $runner = new RectorRunner();
        $method = new \ReflectionMethod(RectorRunner::class, 'encodeHandshakeFrame');

        $invalidUtf8Path = "/tmp/bad_\xE9_bootstrap.php";
        $encoded = $method->invoke($runner, [
            'ok' => true,
            'bootstrap_files' => [$invalidUtf8Path => null],
        ]);

        self::assertIsString($encoded);
        self::assertNotSame('', $encoded, 'the whole handshake payload must never be silently dropped');
        $decoded = json_decode($encoded, true);
        self::assertIsArray($decoded, 'the encoded frame must always be valid, decodable JSON: got ' . var_export($encoded, true));
        self::assertTrue(
            $decoded['ok'] ?? false,
            'a non-UTF-8 bootstrap path must not turn a successful boot into a reported failure: got ' . var_export($encoded, true),
        );
    }

    /**
     * #127: a kill -9 of the DAEMON (this instance's own process, from the
     * worker's point of view) while a call is in flight must be noticed by the
     * worker within roughly ORPHAN_POLL_SECONDS, not only once the in-flight
     * grandchild eventually finishes (its own deadline, or -- as reproduced
     * here with $callTimeoutSeconds=0 -- never). The daemon here is a REAL
     * subprocess (not this test process, which cannot safely kill -9 itself):
     * a small driver script boots a worker via boot() (a real pcntl_fork()),
     * reports the worker's own pid, then starts a call whose execute()
     * override sleeps -- simulating an in-flight analysis -- before this test
     * SIGKILLs the driver ("daemon") process and polls how long the reported
     * worker pid keeps answering kill(pid, 0).
     */
    /**
     * @phpstan-impure PHPStan otherwise assumes this is pure and "remembers"
     *   the first return value for the rest of the scope -- but a process can
     *   genuinely die between two calls with the same $pid, which is exactly
     *   what the poll loop below depends on observing.
     */
    private static function isAlive(int $pid): bool
    {
        return posix_kill($pid, 0);
    }

    public function testWarmWorkerExitsPromptlyWhenItsDaemonIsKilledMidCall(): void
    {
        if (!\function_exists('posix_kill') || !\function_exists('pcntl_fork')) {
            self::markTestSkipped('posix_kill/pcntl_fork unavailable in this environment');
        }

        $tmp = sys_get_temp_dir() . '/rector-runner-127-daemon-kill-test-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\nreturn RectorConfig::configure();\n",
        );

        $projectRoot = dirname(__DIR__, 2);
        $driverScript = $tmp . '/driver.php';
        file_put_contents(
            $driverScript,
            "<?php\n"
            . "declare(strict_types=1);\n"
            . 'require ' . var_export($projectRoot . '/vendor/autoload.php', true) . ";\n"
            . 'chdir(' . var_export($tmp, true) . ");\n"
            . "\$_SERVER['argv'] = ['rector'];\n"
            . "\$runner = new class(0) extends \\Dpt\\McpRectorWarm\\RectorRunner {\n"
            . "    protected function execute(array \$argv, bool \$warmBoot): array\n"
            . "    {\n"
            . "        if ((\$argv[1] ?? null) === 'wedge') {\n"
            . "            sleep(10);\n"
            . "        }\n\n"
            . "        return ['exit_code' => 0, 'output' => '', 'warm_boot' => \$warmBoot];\n"
            . "    }\n\n"
            . "    public function bootPublic(): void\n"
            . "    {\n"
            . "        \$this->boot();\n"
            . "    }\n"
            . "};\n"
            . "\$runner->bootPublic();\n"
            . "\$ref = new \\ReflectionProperty(\\Dpt\\McpRectorWarm\\RectorRunner::class, 'workerPid');\n"
            . "\$ref->setAccessible(true);\n"
            . "echo 'WORKER_PID=' . \$ref->getValue(\$runner) . \"\\n\";\n"
            . "fflush(STDOUT);\n"
            . "\$runner->run(['rector', 'wedge'], true);\n",
        );

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([\PHP_BINARY, $driverScript], $descriptors, $pipes);
        self::assertIsResource($proc, 'must be able to spawn the driver ("daemon") subprocess');
        fclose($pipes[0]);

        try {
            // Read the single "WORKER_PID=<n>" line the driver prints right
            // after boot() -- before it starts the 10s-sleeping call.
            $deadline = microtime(true) + 15.0;
            $workerPid = null;
            $buffer = '';
            while ($workerPid === null && microtime(true) < $deadline) {
                $line = fgets($pipes[1]);
                if ($line === false) {
                    break;
                }
                $buffer .= $line;
                if (preg_match('/WORKER_PID=(\d+)/', $line, $m) === 1) {
                    $workerPid = (int) $m[1];
                }
            }
            self::assertNotNull($workerPid, "driver never reported a worker pid; stdout so far: {$buffer}");
            self::assertTrue(
                self::isAlive($workerPid),
                'must fire: the reported worker pid must genuinely be alive right after boot()',
            );

            $status = proc_get_status($proc);
            // @phpstan-ignore staticMethod.alreadyNarrowedType (defensive: proc_get_status()'s documented false-on-failure return is not reflected in the stub's array shape PHPStan infers here)
            self::assertIsArray($status);
            $daemonPid = (int) $status['pid'];

            // Give the driver a brief moment to actually enter the sleep(10)
            // call (send the request frame, worker forks the grandchild)
            // before killing it -- this is what makes it "mid-call", not
            // merely "right after boot".
            usleep(300_000);

            self::assertTrue(posix_kill($daemonPid, \SIGKILL), 'must be able to kill -9 the driver ("daemon")');

            // Positive control for the poll itself: the worker pid must still
            // exist at least once right after the kill -- otherwise a broken
            // poll (or a pid reused instantly) would pass this test for free
            // by finding "no process" from the very first check.
            self::assertTrue(
                self::isAlive($workerPid),
                'must fire: the worker must still exist immediately after the kill -- otherwise the poll below is vacuous',
            );

            $pollDeadline = microtime(true) + 8.0;
            $stillAlive = true;
            while (microtime(true) < $pollDeadline) {
                if (!self::isAlive($workerPid)) {
                    $stillAlive = false;
                    break;
                }
                usleep(100_000);
            }
            $elapsed = 8.0 - max(0.0, $pollDeadline - microtime(true));

            self::assertFalse(
                $stillAlive,
                "the warm worker must exit once its daemon is killed -9 mid-call, within a few seconds -- "
                . 'still alive after 8s (#127)',
            );
            self::assertLessThan(
                5.0,
                $elapsed,
                "the warm worker took {$elapsed}s to exit after its daemon was killed -9 mid-call -- "
                . 'expected roughly ORPHAN_POLL_SECONDS, not tens of seconds (#127)',
            );
        } finally {
            @posix_kill($workerPid ?? 0, \SIGKILL);
            fclose($pipes[1]);
            fclose($pipes[2]);
            @proc_terminate($proc, 9);
            proc_close($proc);
            unlink($driverScript);
            unlink($tmp . '/rector.php');
            rmdir($tmp);
        }
    }

    /**
     * #133: an independent E2E check of the #127 fix found that ANY Throwable
     * escaping serveWorker() (not only bootInPlace()'s, which was already caught)
     * unwinds the forked worker straight back into boot()'s own caller -- a real
     * pcntl_fork() duplicates the WHOLE call stack, including inherited fds, so an
     * uncaught exception there does not crash the worker: it resumes as a second,
     * bogus copy of whatever was running before the fork. The concrete trigger:
     * writeFrame() throwing on an already-dead $socket used to sit outside every
     * try/catch in serveWorker().
     *
     * Reproduced here WITHOUT any daemon-kill timing: the daemon's own end of the
     * socket pair is closed BEFORE the fork even happens, so bootInPlace() succeeds
     * (a real, trivial rector.php) but the very first writeFrame() (the boot
     * handshake) fails immediately with a broken pipe -- "a worker whose daemon is
     * already gone by the time it tries to reply", which is exactly the shape #133
     * found on EVERY write site, not only the one #127 added. The forked child
     * wraps serveWorker() in its OWN try/catch (independent of PHPUnit's own
     * exception handler, which is also copied into the fork and could otherwise
     * mask this) and writes a marker file if the call either throws past
     * serveWorker() or plainly returns -- either one proves the escape, since
     * serveWorker() is documented to always exit() internally.
     */
    public function testServeWorkerNeverUnwindsWhenTheSocketIsAlreadyDead(): void
    {
        // Same three functions canFork() itself requires (RectorRunner.php) --
        // this repo's dedicated no-pcntl CI job disables all three together via
        // disable_functions, but checking all three here too (not only the
        // first two) matches production's own guard exactly, rather than
        // assuming they are always disabled as a set.
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid') || !\function_exists('stream_socket_pair')) {
            self::markTestSkipped('pcntl_fork/pcntl_waitpid/stream_socket_pair unavailable in this environment');
        }

        $tmp = sys_get_temp_dir() . '/rector-runner-133-serveworker-escape-test-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\nreturn RectorConfig::configure();\n",
        );
        $previousCwd = getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];
        $markerFile = $tmp . '/serveworker-escaped.marker';

        try {
            chdir($tmp);
            $_SERVER['argv'] = ['rector'];

            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertIsArray($sockets);
            [$parentSocket, $childSocket] = $sockets;

            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'pcntl_fork() must succeed to run this test');

            if ($pid === 0) {
                // Child ("worker"): close the daemon's own end FIRST -- every
                // writeFrame() this worker attempts from here on fails with a
                // broken pipe, starting with the boot handshake itself.
                fclose($parentSocket);
                // Self-review finding: a socket-pair write only fails once
                // EVERY fd referencing the read side is closed -- this
                // process's own copy, closed just above, AND the parent
                // test process's original, closed a few lines below (after
                // this branch returns control to it). Without this pause the
                // ordering between those two closes is genuinely
                // unguaranteed, though bootInPlace() below (a real container
                // build) already dwarfs it in practice. A short, one-sided
                // wait here removes the race outright rather than relying on
                // that asymmetry.
                usleep(50_000);

                $runner = new RectorRunner(0);
                $method = new \ReflectionMethod(RectorRunner::class, 'serveWorker');
                try {
                    $method->invoke($runner, $childSocket, null);
                    // serveWorker() is documented to always exit() -- reaching
                    // this line at all (a plain return, not even a throw) is
                    // itself the bug.
                    file_put_contents($markerFile, 'returned without exiting');
                } catch (\Throwable $e) {
                    // #133's exact bug: an exception reached past serveWorker().
                    file_put_contents($markerFile, 'escaped as: ' . $e->getMessage());
                }
                // Only reached if serveWorker() failed to exit() on its own --
                // a deliberately different code from serveWorker()'s own exit(1)
                // (below) so the parent can tell which one actually ran.
                exit(50);
            }

            // Parent (this test process, standing in for "the daemon"): close
            // BOTH ends now -- not just $childSocket (which this process never
            // uses anyway), but $parentSocket too. A unix socket pair endpoint is
            // only truly dead once EVERY process holding a copy of that fd has
            // closed it; pcntl_fork() duplicated $parentSocket into the child,
            // and the child already closed its OWN copy, but THIS process (the
            // real owner) still held one open until this line -- without closing
            // it here, the child's writeFrame() call succeeds into a live,
            // unread buffer instead of failing, and the child's subsequent
            // readFrame() then blocks forever waiting for a request nobody will
            // ever send, hanging this whole test (caught by self-review: the
            // first run of this test timed out for exactly this reason).
            fclose($childSocket);
            fclose($parentSocket);
            $status = 0;
            pcntl_waitpid($pid, $status);

            self::assertFileDoesNotExist(
                $markerFile,
                'must fire: serveWorker() must never unwind past its own exit() call, even when every '
                . 'write it attempts fails immediately (#133)',
            );
            self::assertTrue(
                pcntl_wifexited($status),
                'the forked worker must have called exit() cleanly, not crashed or been signalled',
            );
            self::assertSame(
                1,
                pcntl_wexitstatus($status),
                'a write failure on an already-dead socket must still exit(1) from INSIDE serveWorker() '
                . "itself -- exit code 50 would mean the escape's own fallback exit() ran instead (#133)",
            );
        } finally {
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            @unlink($markerFile);
            unlink($tmp . '/rector.php');
            rmdir($tmp);
        }
    }

    /**
     * #184: Rector's ConsoleApplication::doRun() unconditionally calls
     * XdebugHandler::check() before running any command. When Xdebug is loaded,
     * check() restarts the OS process using the CURRENT $_SERVER['argv'] -- which,
     * on the worker/cold-subprocess paths, is the daemon's own original launch argv
     * (no --dry-run), not this call's $argv. That restart runs Rector for real over
     * every configured path. Setting RECTOR_ALLOW_XDEBUG=1 (XdebugHandler's own
     * documented escape hatch, envPrefix 'rector') for the duration of
     * application->run() makes check() skip the restart entirely, regardless of
     * what $_SERVER['argv'] happens to contain.
     *
     * Positive control: the fake application records the env value it observed
     * DURING run(), pinning that the value was actually set before run() was
     * called -- not merely set-then-unset around an empty block.
     */
    public function testExecuteAllowsXdebugDuringApplicationRunToPreventARealRestart(): void
    {
        $runner = new RectorRunner();

        $application = new class () {
            public string|false $observedEnvDuringRun = false;

            public function run(object $input, object $output): int
            {
                $this->observedEnvDuringRun = getenv('RECTOR_ALLOW_XDEBUG');

                return 0;
            }
        };

        $this->setPrivateRunnerState($runner, $application);

        $method = new \ReflectionMethod(RectorRunner::class, 'execute');
        $method->invoke($runner, ['rector', 'process', '--dry-run'], true);

        self::assertSame(
            '1',
            $application->observedEnvDuringRun,
            'RECTOR_ALLOW_XDEBUG must be "1" while application->run() executes, so '
            . "XdebugHandler::check() never restarts the process using stale argv",
        );
    }

    /**
     * #184, restore half: the env var must not leak past the call it was set for
     * -- symmetric with the existing argv[0] save/restore a few lines above it.
     * Must-fire control: start from a concrete sentinel value, not from "unset",
     * so a bug that simply never restores anything is caught, not just a bug that
     * clears an already-empty variable.
     */
    public function testExecuteRestoresThePriorXdebugEnvValueAfterTheCall(): void
    {
        $previous = getenv('RECTOR_ALLOW_XDEBUG');
        putenv('RECTOR_ALLOW_XDEBUG=sentinel-184');

        try {
            $runner = new RectorRunner();
            $application = new class () {
                public function run(object $input, object $output): int
                {
                    return 0;
                }
            };

            $this->setPrivateRunnerState($runner, $application);

            $method = new \ReflectionMethod(RectorRunner::class, 'execute');
            $method->invoke($runner, ['rector', 'process', '--dry-run'], true);

            self::assertSame(
                'sentinel-184',
                getenv('RECTOR_ALLOW_XDEBUG'),
                'execute() must restore whatever RECTOR_ALLOW_XDEBUG held before the call, '
                . 'the same way it restores $_SERVER["argv"][0]',
            );
        } finally {
            if ($previous === false) {
                putenv('RECTOR_ALLOW_XDEBUG');
            } else {
                putenv('RECTOR_ALLOW_XDEBUG=' . $previous);
            }
        }
    }

    /**
     * #184, restore-to-unset half: the auditor's own review of this fix (recorded in
     * the pull request) flagged that the sibling test above only ever primes a
     * concrete sentinel before the call, so it only ever exercises the
     * `putenv('NAME=' . $prior)` branch of the restore -- never the
     * `$origAllowXdebugEnv === false` branch, which is the ordinary real-world case
     * for anyone who has not already worked around #184 by setting the env
     * themselves. This test starts from "definitely unset" (unsetting first, rather
     * than trusting the ambient environment to already be that way) and asserts the
     * env is unset again afterward, so a regression that leaves it as an empty
     * string instead of truly absent (a documented historical quirk of some
     * putenv() implementations) is caught rather than silently accepted.
     */
    public function testExecuteRestoresXdebugEnvToUnsetWhenItWasUnsetBeforeTheCall(): void
    {
        $previous = getenv('RECTOR_ALLOW_XDEBUG');
        putenv('RECTOR_ALLOW_XDEBUG');
        self::assertFalse(
            getenv('RECTOR_ALLOW_XDEBUG'),
            'test precondition: the env must genuinely be unset before the call, not merely absent from this test\'s own knowledge of it',
        );

        try {
            $runner = new RectorRunner();
            $application = new class () {
                public function run(object $input, object $output): int
                {
                    return 0;
                }
            };

            $this->setPrivateRunnerState($runner, $application);

            $method = new \ReflectionMethod(RectorRunner::class, 'execute');
            $method->invoke($runner, ['rector', 'process', '--dry-run'], true);

            self::assertFalse(
                getenv('RECTOR_ALLOW_XDEBUG'),
                'execute() must leave RECTOR_ALLOW_XDEBUG genuinely unset (getenv() === false) '
                . 'after the call when it was unset before -- not merely set to an empty string',
            );
        } finally {
            if ($previous === false) {
                putenv('RECTOR_ALLOW_XDEBUG');
            } else {
                putenv('RECTOR_ALLOW_XDEBUG=' . $previous);
            }
        }
    }

    private function setPrivateRunnerState(RectorRunner $runner, object $application): void
    {
        foreach ([
            'application' => $application,
            'container' => new class () {
                public function get(string $id): object
                {
                    // Deliberately has no areSomeRectorsLoaded() method, so execute()'s
                    // onboarding check (is_object() && method_exists()) is false and the
                    // real container path is never exercised by this test double.
                    return new \stdClass();
                }
            },
            // Rector's own vendor tree is prefix-scoped (a build-time namespace prefix
            // execute() resolves via detectRectorPrefix()/resolvePrefixed(), not present in
            // this test process), so the real Symfony\Component\Console classes are not
            // reachable by their normal FQCN here. These two minimal doubles stand in for
            // ArgvInput/BufferedOutput -- execute() only ever does `new $inputClass($argv)`,
            // `new $outputClass()` and, on the output, `->fetch()`.
            'inputClass' => RectorRunnerTest184FakeInput::class,
            'outputClass' => RectorRunnerTest184FakeOutput::class,
        ] as $prop => $value) {
            $property = new \ReflectionProperty(RectorRunner::class, $prop);
            $property->setValue($runner, $value);
        }
    }

    /**
     * #195: the signal rector/rector's own bin/rector.php AutoloadIncluder uses
     * (`is_dir('vendor/rector/rector')`, relative to getcwd()) before deciding whether to
     * load `vendor/autoload.php` in autoloadRectorInstalledAsGlobalDependency() -- mirrored
     * here so ensureProjectAutoloaded() can skip a foreign project's autoloader the same way
     * a genuine cold `vendor/bin/rector process` run against that project would.
     */
    public function testProjectShipsOwnRectorTrueWhenVendorRectorRectorDirExists(): void
    {
        $dir = sys_get_temp_dir() . '/rector-runner-test-' . uniqid('', true);
        mkdir($dir . '/vendor/rector/rector', 0777, true);
        try {
            $runner = new RectorRunner();
            $method = new \ReflectionMethod(RectorRunner::class, 'projectShipsOwnRector');
            self::assertTrue($method->invoke($runner, $dir));
        } finally {
            self::rmrf($dir);
        }
    }

    public function testProjectShipsOwnRectorFalseWhenNoVendorRectorRectorDir(): void
    {
        $dir = sys_get_temp_dir() . '/rector-runner-test-' . uniqid('', true);
        mkdir($dir . '/vendor', 0777, true);
        try {
            $runner = new RectorRunner();
            $method = new \ReflectionMethod(RectorRunner::class, 'projectShipsOwnRector');
            self::assertFalse($method->invoke($runner, $dir));
        } finally {
            self::rmrf($dir);
        }
    }

    /**
     * #195: warm must not load the analysed project's own vendor/autoload.php when that
     * project ships its own vendor/rector/rector -- see projectShipsOwnRector()'s docblock.
     * Before this guard, ensureProjectAutoloaded() required it unconditionally, so warm could
     * resolve real project classes/types through the project's real Composer autoloader that a
     * matching cold `vendor/bin/rector process` run (which never touches that autoloader in
     * this situation) could not -- the exact mechanism behind #195's "warm infers more types
     * than cold" reports on laravel/framework and symfony/symfony in checkout mode. Confirmed
     * via get_included_files() rather than a defined constant, so re-running this test in the
     * same process never collides with itself.
     */
    public function testEnsureProjectAutoloadedSkipsProjectWithOwnRector(): void
    {
        $dir = sys_get_temp_dir() . '/rector-runner-test-' . uniqid('', true);
        mkdir($dir . '/vendor/rector/rector', 0777, true);
        file_put_contents($dir . '/vendor/autoload.php', "<?php\nreturn true;\n");
        $originalCwd = (string) getcwd();
        chdir($dir);
        try {
            $runner = new RectorRunner();
            $method = new \ReflectionMethod(RectorRunner::class, 'ensureProjectAutoloaded');
            $method->invoke($runner);
            $realPath = realpath($dir . '/vendor/autoload.php');
            self::assertNotFalse($realPath);
            self::assertNotContains(
                $realPath,
                get_included_files(),
                'a project shipping its own Rector must not have its autoloader loaded by warm (#195)',
            );
        } finally {
            chdir($originalCwd);
            self::rmrf($dir);
        }
    }

    /**
     * Control: a project with NO vendor/rector/rector of its own must still get its
     * autoloader loaded -- the #30 behaviour projectShipsOwnRector() must not disturb. Without
     * this case, a projectShipsOwnRector() that always returned true would pass the mismatch
     * test above for the wrong reason.
     */
    public function testEnsureProjectAutoloadedLoadsProjectWithoutOwnRector(): void
    {
        $dir = sys_get_temp_dir() . '/rector-runner-test-' . uniqid('', true);
        mkdir($dir . '/vendor', 0777, true);
        file_put_contents($dir . '/vendor/autoload.php', "<?php\nreturn true;\n");
        $originalCwd = (string) getcwd();
        chdir($dir);
        try {
            $runner = new RectorRunner();
            $method = new \ReflectionMethod(RectorRunner::class, 'ensureProjectAutoloaded');
            $method->invoke($runner);
            $realPath = realpath($dir . '/vendor/autoload.php');
            self::assertNotFalse($realPath);
            self::assertContains($realPath, get_included_files());
        } finally {
            chdir($originalCwd);
            self::rmrf($dir);
        }
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::rmrf($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

/**
 * @internal minimal doubles for RectorRunner::execute()'s inputClass/outputClass, used by
 * the #184 Xdebug-env tests above -- standing in for Symfony's ArgvInput/BufferedOutput,
 * which live under Rector's own build-time namespace prefix and are not reachable by their
 * normal FQCN from a test process.
 */
final readonly class RectorRunnerTest184FakeInput
{
    /** @param list<string> $argv */
    public function __construct(public array $argv)
    {
    }
}

/**
 * @internal see RectorRunnerTest184FakeInput.
 */
final class RectorRunnerTest184FakeOutput
{
    public function fetch(): string
    {
        return '';
    }
}

/**
 * @internal test-only stream filter: captures every byte written to STDERR while
 * appended, without touching the real fd -- see testBootstrapFileHashResolutionFailureIsLoggedNotSilent().
 */
final class RectorRunnerTest63CaptureFilter extends \php_user_filter
{
    public static string $captured = '';

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$captured .= $bucket->data;
            // php_user_filter::filter()'s &$consumed is declared int, but
            // StreamBucket::$datalen is int|float (it can exceed PHP_INT_MAX
            // on a 32-bit build) -- narrow explicitly to satisfy the by-ref type.
            $consumed += (int) $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}

/**
 * @internal test-only stream filter, same mechanism as RectorRunnerTest63CaptureFilter
 * above but registered once and reused -- see captureStderr().
 */
final class RectorRunnerTestGenericCaptureFilter extends \php_user_filter
{
    public static string $captured = '';

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$captured .= $bucket->data;
            // php_user_filter::filter()'s &$consumed is declared int, but
            // StreamBucket::$datalen is int|float (it can exceed PHP_INT_MAX
            // on a 32-bit build) -- narrow explicitly to satisfy the by-ref type.
            $consumed += (int) $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
