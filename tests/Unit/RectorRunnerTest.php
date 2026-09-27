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

            self::assertTrue(
                $runner->isWarm(),
                'boot() built a real container from a config that genuinely loaded; only execute() refused',
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
}
