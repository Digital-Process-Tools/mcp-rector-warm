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
     * Control-flow pin for the no-pcntl fallback (claude-supertool#8): no CI
     * leg in this repo runs without pcntl (single ubuntu-latest job), so this
     * exercises the branch mechanically by forcing canFork() to report
     * unavailable. A warm call must then reboot + reboot the container and
     * report warm_boot=false -- never silently reuse the old container or
     * silently take the fork path.
     */
    public function testRunWithoutForkSupportRebootsAndReportsColdOnWarmCalls(): void
    {
        $runner = new class extends RectorRunner {
            /** @var list<string> */
            public array $log = [];
            private bool $booted = false;

            protected function canFork(): bool
            {
                return false;
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

        $second = $runner->run(['rector']);
        self::assertFalse(
            $second['warm_boot'],
            'without pcntl, a warm call must fully reboot rather than silently report warm reuse',
        );
        self::assertSame(['boot', 'execute:cold', 'reboot', 'boot', 'execute:cold'], $runner->log);
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
}
