<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorCallTimeoutException;
use Dpt\McpRectorWarm\RectorRunner;
use PHPUnit\Framework\TestCase;

final class RectorRunnerTest extends TestCase
{
    /**
     * Calls $attempt up to $attempts times total, sleeping between attempts
     * (never after the last one), stopping as soon as one call returns true.
     * The sleep starts at $delayMicroseconds and grows by $backoffMultiplier
     * after each attempt, capped at $maxDelayMicroseconds (a multiplier of
     * 1.0, the default, keeps the delay fixed -- unchanged behaviour for any
     * caller that does not ask for growth). $attempt receives whether this
     * is its final call so it can behave differently there (e.g. stop
     * suppressing a real error) -- this is generic on purpose so it can be
     * pinned by a unit test (testRetryUntilTrue* below) independently of
     * rmdir()/the filesystem. $sleep defaults to usleep() but can be
     * substituted by a test to observe the delay sequence without actually
     * waiting it out.
     */
    private static function retryUntilTrue(
        callable $attempt,
        int $attempts,
        int $delayMicroseconds,
        float $backoffMultiplier = 1.0,
        int $maxDelayMicroseconds = PHP_INT_MAX,
        ?callable $sleep = null,
    ): void {
        $sleep ??= 'usleep';
        $delay = $delayMicroseconds;
        for ($i = 1; $i <= $attempts; $i++) {
            if ($attempt($i === $attempts)) {
                return;
            }
            if ($i < $attempts) {
                $sleep($delay);
                $delay = min((int) round($delay * $backoffMultiplier), $maxDelayMicroseconds);
            }
        }
    }

    /**
     * rmdir() with a retry-with-exponential-backoff, for cleaning up a temp
     * directory that was the cwd of a subprocess this test just killed.
     * TerminateProcess() on Windows can leave the OS holding the killed
     * process's handle on its own working directory for a window after
     * proc_terminate()/proc_close() return (see RectorRunner::runCold(),
     * which does not itself wait for handle release), so rmdir() can
     * transiently fail there with "Resource temporarily unavailable". POSIX
     * releases the handle synchronously with the kill, so on POSIX this
     * always succeeds on the first attempt -- the retry is unconditional
     * rather than PHP_OS_FAMILY-gated because it costs nothing there.
     *
     * Budget: 12 attempts, starting at 150ms and multiplying by 1.5 each
     * time up to a 1s cap -- roughly 8s of total backoff (150 + 225 + 337 +
     * 506 + 759 + 1000*6 ms), reached only if every attempt but the last
     * fails. This was already widened once (from an original ~900ms: 10
     * fixed 100ms attempts) and still did not close the gap on Windows CI --
     * see trap.d/96.windows-rmdir-race-fixed.md. #112 (folded in from #114,
     * closed as a duplicate) confirmed why: the real cause is a leaked
     * grandchild php process that proc_terminate() never reaps on Windows
     * (it only kills the cmd.exe wrapper proc_open() spawns), so no retry
     * budget can outlast a handle held by a still-live process. Widening
     * this further is a settled dead end, not something to keep tuning --
     * #112 tracks the real fix (killing the actual grandchild, asserting
     * its PID is gone); this stays as a best-effort cleanup attempt only.
     *
     * Every attempt, including the last, suppresses rmdir()'s own warning
     * (@rmdir()) -- unlike the previous version of this function, which let
     * the final attempt's warning surface uncontrolled and trip
     * phpunit.xml's failOnWarning="true". The return value tells the caller
     * whether the directory is actually gone once the full budget is spent,
     * so a genuine leak (the #112 case) can be reported as an explicit,
     * named PHPUnit skip instead of an uncontrolled warning -- see
     * testColdCallIsKilledAtItsDeadlineWithoutPcntl()'s finally block.
     */
    private static function rmdirWithRetry(
        string $dir,
        int $attempts = 12,
        int $initialDelayMicroseconds = 150_000,
        float $backoffMultiplier = 1.5,
        int $maxDelayMicroseconds = 1_000_000,
    ): bool {
        self::retryUntilTrue(
            static fn (bool $isFinalAttempt): bool => @rmdir($dir),
            $attempts,
            $initialDelayMicroseconds,
            $backoffMultiplier,
            $maxDelayMicroseconds,
        );

        return !is_dir($dir);
    }

    /**
     * Pins retryUntilTrue()'s stop-as-soon-as-success behaviour, the half of
     * rmdirWithRetry() a real filesystem cannot exercise from this suite (the
     * race it retries around is Windows-only and does not reproduce on
     * POSIX, see trap.d/96.windows-rmdir-race-fixed.md) -- without this, a
     * previous version's off-by-one (an extra, undocumented 11th call on the
     * final attempt, with the two back-to-back and no backoff between them)
     * shipped silently: rmdirWithRetry()'s own docblock and this commit's
     * message both claimed "10 attempts" while the code made 11.
     */
    public function testRetryUntilTrueStopsAsSoonAsAttemptSucceeds(): void
    {
        $calls = 0;
        self::retryUntilTrue(
            function () use (&$calls): bool {
                $calls++;

                return $calls >= 3;
            },
            10,
            0,
        );
        self::assertSame(3, $calls, 'must stop as soon as an attempt succeeds, not keep going to the attempt cap');
    }

    /**
     * Pins the terminal-failure shape: exactly $attempts calls total (not
     * $attempts + 1), and only the last of them is marked final -- the
     * signal rmdirWithRetry() uses to switch from a suppressed @rmdir() to a
     * real one that is allowed to surface its own error. Uses delay=0 and
     * its own attempt count (4), independently of whatever attempts/delay
     * rmdirWithRetry() itself defaults to -- this pins retryUntilTrue()'s
     * generic call-count/final-flag mechanics, not rmdirWithRetry()'s
     * concrete budget (see testRmdirWithRetryDefaultBudgetTotalsAroundEightSeconds
     * below for that).
     */
    public function testRetryUntilTrueMakesExactlyAttemptsCallsAndMarksOnlyTheLastFinal(): void
    {
        $isFinalPerCall = [];
        self::retryUntilTrue(
            function (bool $isFinalAttempt) use (&$isFinalPerCall): bool {
                $isFinalPerCall[] = $isFinalAttempt;

                return false;
            },
            4,
            0,
        );
        self::assertSame([false, false, false, true], $isFinalPerCall);
    }

    /**
     * Pins retryUntilTrue()'s exponential-backoff mechanics directly, via an
     * injected $sleep spy instead of a real usleep() -- runs instantly and
     * asserts the actual microsecond delay sequence: growing by
     * $backoffMultiplier after each attempt, capped at
     * $maxDelayMicroseconds. This is the half of the mechanics the two
     * delay=0 tests above cannot see (they pin call count/final-flag shape
     * only, decoupled from any real delay).
     */
    public function testRetryUntilTrueBackoffGrowsThenCaps(): void
    {
        $delays = [];
        self::retryUntilTrue(
            static fn (bool $isFinalAttempt): bool => false,
            5,
            10,
            2.0,
            35,
            static function (int $delayMicroseconds) use (&$delays): void {
                $delays[] = $delayMicroseconds;
            },
        );
        self::assertSame([10, 20, 35, 35], $delays, 'must double each gap until the cap, then hold at the cap');
    }

    /**
     * Pins rmdirWithRetry()'s own concrete default budget by reading its
     * actual default parameter values via reflection -- not by re-typing
     * them as separate literals, which would silently stop pinning
     * anything the moment rmdirWithRetry()'s own defaults changed without
     * this test being touched (caught in self-review: an earlier version
     * of this test hardcoded 12/150_000/1.5/1_000_000 itself, so it could
     * never fail if those defaults drifted). Feeds the reflected defaults
     * into retryUntilTrue() with the same $sleep-spy technique as
     * testRetryUntilTrueBackoffGrowsThenCaps() above and asserts the
     * resulting ~8s budget. Runs instantly (no real sleeping) despite the
     * real-world duration it represents.
     */
    public function testRmdirWithRetryDefaultBudgetTotalsAroundEightSeconds(): void
    {
        $defaults = [];
        foreach ((new \ReflectionMethod(self::class, 'rmdirWithRetry'))->getParameters() as $parameter) {
            if ($parameter->getName() !== 'dir') {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }
        self::assertSame(
            ['attempts', 'initialDelayMicroseconds', 'backoffMultiplier', 'maxDelayMicroseconds'],
            array_keys($defaults),
            'rmdirWithRetry() must keep this exact parameter shape for the reflection below to read the right defaults',
        );

        $delays = [];
        self::retryUntilTrue(
            static fn (bool $isFinalAttempt): bool => false,
            $defaults['attempts'],
            $defaults['initialDelayMicroseconds'],
            $defaults['backoffMultiplier'],
            $defaults['maxDelayMicroseconds'],
            static function (int $delayMicroseconds) use (&$delays): void {
                $delays[] = $delayMicroseconds;
            },
        );
        self::assertCount(
            $defaults['attempts'] - 1,
            $delays,
            'must sleep once between each attempt, never after the last',
        );
        self::assertSame(
            $defaults['maxDelayMicroseconds'],
            $delays[array_key_last($delays)],
            'must have reached the cap well before the last attempt',
        );
        self::assertGreaterThan(7_000_000, array_sum($delays), 'total budget must give real headroom over the original ~900ms');
        self::assertLessThan(10_000_000, array_sum($delays), 'total budget must stay inside the requested 5-10s range');
    }

    /**
     * Pins the genuine-failure path of rmdirWithRetry() itself: when the
     * directory never becomes removable (here, kept permanently non-empty
     * rather than relying on the real, Windows-only, unreproducible-on-this
     * suite #112 handle-leak race), the retry budget must exhaust and the
     * method must report failure (false) with the directory left in place,
     * rather than throwing, warning, or silently reporting success. This is
     * the exact signal testColdCallIsKilledAtItsDeadlineWithoutPcntl()'s
     * finally block reads to decide whether to call markTestSkipped() --
     * see #96/#112. Uses attempts=2, delay=0 so this runs instantly despite
     * exercising the full retry loop.
     */
    public function testRmdirWithRetryReturnsFalseWhenDirectoryNeverBecomesRemovable(): void
    {
        $dir = sys_get_temp_dir() . '/rector-runner-rmdir-retry-unit-test-' . bin2hex(random_bytes(8));
        mkdir($dir);
        file_put_contents($dir . '/blocker.txt', 'keeps this directory permanently non-empty');

        try {
            $removed = (new \ReflectionMethod(self::class, 'rmdirWithRetry'))
                ->invoke(null, $dir, 2, 0);

            self::assertFalse(
                $removed,
                'rmdirWithRetry() must report failure (false) when the directory never becomes removable',
            );
            self::assertDirectoryExists(
                $dir,
                'the directory must still exist -- rmdirWithRetry() must not report failure while having actually removed it, nor succeed while leaving it behind',
            );
        } finally {
            unlink($dir . '/blocker.txt');
            rmdir($dir);
        }
    }

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
     * isWarm() afterwards depends on whether THIS environment can fork (#31): with
     * pcntl, boot() still builds a real container in a worker before execute()
     * refuses it, so isWarm() is true; without pcntl, run() never boots or forks
     * anything any more -- every call, including this one, goes through the
     * disposable-subprocess fallback (runCold()) -- so isWarm() stays false, exactly
     * as testRunWithoutForkSupportAlwaysRunsColdAndNeverBootsInPlace pins for the
     * stubbed case. Asserting a hardcoded `true` here would fail on this repo's own
     * `no-pcntl` CI job (#31 follow-up: caught by actually running this test with
     * pcntl disabled, not by reading the assertion).
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
                $canFork,
                $runner->isWarm(),
                $canFork
                    ? 'boot() built a real container from a config that genuinely loaded; only execute() refused'
                    : 'without pcntl every call is a disposable cold subprocess (#31); nothing is ever warm',
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
            $pidProperty->setAccessible(true);
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
        $method->setAccessible(true);

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

            // 1s deadline: long enough that the worker's own boot (a real,
            // zero-rule Rector container) reliably finishes well before it, short
            // enough that this test does not itself become the next slow test in
            // the suite.
            // Whether THIS call sleeps travels in $argv, not in object state: the
            // worker forked once, at boot(), and keeps running its OWN copy of
            // $this from that instant on (serveWorker()'s loop) -- a later
            // mutation of $runner in THIS (daemon) process can never reach back
            // into an already-forked child. $argv, by contrast, is decoded fresh
            // by the worker on every request (serveWorker()'s readFrame() loop),
            // so it is the only per-call signal available here.
            $runner = new class(1) extends RectorRunner {
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
                $method->setAccessible(true);

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
                if (isset($client) && $client !== false) {
                    fclose($client);
                }
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

            $runner = new RectorRunner(1);
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
            $workerSocketProperty->setAccessible(true);
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
                $method->setAccessible(true);

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
            $workerPidProperty->setAccessible(true);
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

            $runner = new RectorRunner(1);

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
            // Windows can briefly hold the just-killed cold subprocess's handle
            // on $tmp (its working directory at the moment TerminateProcess()
            // hit it -- runCold()'s deadline-poll branch does not wait for the
            // OS to release file handles after proc_terminate()/proc_close(),
            // see RectorRunner::runCold()), so rmdir($tmp) can fail with
            // "Resource temporarily unavailable" for a short window after the
            // kill. POSIX releases the handle synchronously with the kill, so
            // this retries there too for symmetry but always succeeds first
            // try. See #96/#97/#104 (--display-warnings) for how this was
            // first made visible in CI, and #112 for the confirmed root cause
            // (a leaked grandchild php process on Windows) and why widening
            // the retry budget further is a dead end rather than a fix.
            $srcRemoved = self::rmdirWithRetry($tmp . '/src');
            $tmpRemoved = self::rmdirWithRetry($tmp);
            if (!$srcRemoved || !$tmpRemoved) {
                // The retry budget is exhausted and the directory is still
                // there -- this is the #112 leak, not a transient race (a
                // transient race is exactly what the retry above already
                // absorbs). Skip explicitly, naming the cause, instead of
                // letting rmdir()'s own warning surface uncontrolled:
                // phpunit.xml sets failOnWarning="true", so an unsuppressed
                // warning here would fail this leg even though every
                // assertion above already passed. This must only fire on
                // the genuine leftover-directory case above, never
                // unconditionally -- on every platform/run where the leak
                // does not occur (most non-Windows runs, and some Windows
                // runs), both rmdirWithRetry() calls return true and this
                // branch is never reached.
                self::markTestSkipped(
                    'cold-call temp dir cleanup blocked after the full retry budget -- likely the '
                    . 'leaked orphan php process tracked in #112 (proc_terminate() on Windows only '
                    . 'kills the cmd.exe wrapper, not the actual grandchild php process, so it can '
                    . 'still hold ' . $tmp . ' open). The assertions above already passed; only '
                    . 'this teardown cleanup step failed.',
                );
            }
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
        $method->setAccessible(true);

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
            $result = $method->invoke($runner);
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
        $method->setAccessible(true);

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
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
