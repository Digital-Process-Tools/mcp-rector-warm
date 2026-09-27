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
}
