<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

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
            protected function runCold(array $argv): array
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
            protected function runForked(array $argv, bool $warmBoot): array
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
                self::assertStringContainsString('exceeded 1s', $e->getMessage());
                self::assertStringContainsString('--call-timeout', $e->getMessage());
            }
            $elapsed = microtime(true) - $start;
            self::assertLessThan(
                15.0,
                $elapsed,
                "the call must be killed at roughly its 1s deadline, not left to hang -- took {$elapsed}s "
                . '(this is the exact regression #58 reports: PR #57 removed the old upper bound)',
            );

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
        } finally {
            chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            unlink($tmp . '/rector.php');
            rmdir($tmp);
        }
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
