<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorTool;
use Dpt\McpRectorWarm\RunnerInterface;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;

/**
 * Recovery regression: when a warm run throws an internal scope/reflection
 * corruption error ("Call to a member function toMutatingScope() on null" —
 * stale PHPStan state across edits, not a real finding), RectorTool::process
 * must reboot the runner and retry once on a fresh container instead of
 * surfacing a false rector.error to the caller.
 */
final class RectorToolRecoveryTest extends TestCase
{
    private string $cwdBackup;
    private string $workDir;

    protected function setUp(): void
    {
        $this->cwdBackup = getcwd() ?: '/';
        $this->workDir = sys_get_temp_dir() . '/mcp-rector-recover-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o700, true);
        chdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwdBackup);
        @unlink($this->workDir . '/inside.php');
        @rmdir($this->workDir);
    }

    private function insideFile(): string
    {
        $inside = $this->workDir . '/inside.php';
        file_put_contents($inside, "<?php\n\nclass Inside\n{\n}\n");
        return $inside;
    }

    public function testRecoversFromWarmScopeCorruption(): void
    {
        $fake = new class implements RunnerInterface {
            public int $runs = 0;
            public int $reboots = 0;

            public function run(array $argv, bool $dryRun = true, bool $noSession = false): array
            {
                ++$this->runs;
                if ($this->runs === 1) {
                    throw new \Error('Call to a member function toMutatingScope() on null');
                }
                return ['exit_code' => 0, 'output' => '{"totals":{"errors":0}}', 'warm_boot' => false];
            }

            public function isWarm(): bool
            {
                return true;
            }

            public function reboot(): void
            {
                ++$this->reboots;
            }

            public function getCallTimeoutSeconds(): int
            {
                return 0;
            }
        };

        $tool = RectorTool::withRunner($fake);
        $result = $tool->process($this->insideFile(), true);

        self::assertIsArray($result);
        self::assertSame(0, $result['exit_code'], 'recovered run should succeed');
        self::assertSame(2, $fake->runs, 'should retry exactly once');
        self::assertSame(1, $fake->reboots, 'should reboot before retry');
        self::assertArrayNotHasKey('error', $result, 'no false error surfaced after recovery');
    }

    public function testDoesNotRetryUnrelatedError(): void
    {
        $fake = new class implements RunnerInterface {
            public int $runs = 0;
            public int $reboots = 0;

            public function run(array $argv, bool $dryRun = true, bool $noSession = false): array
            {
                ++$this->runs;
                throw new \RuntimeException('disk full');
            }

            public function isWarm(): bool
            {
                return true;
            }

            public function reboot(): void
            {
                ++$this->reboots;
            }

            public function getCallTimeoutSeconds(): int
            {
                return 0;
            }
        };

        $tool = RectorTool::withRunner($fake);
        $result = $tool->process($this->insideFile(), true);

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
        $details = $result->structuredContent ?? [];

        self::assertSame(-1, $details['exit_code']);
        self::assertSame(1, $fake->runs, 'unrelated error must not be retried');
        self::assertSame(0, $fake->reboots);
        self::assertSame('disk full', $details['error'] ?? null);
    }

    public function testSurfacesErrorWhenRetryAlsoFails(): void
    {
        $fake = new class implements RunnerInterface {
            public int $runs = 0;
            public int $reboots = 0;

            public function run(array $argv, bool $dryRun = true, bool $noSession = false): array
            {
                ++$this->runs;
                throw new \Error('Call to a member function toMutatingScope() on null');
            }

            public function isWarm(): bool
            {
                return true;
            }

            public function reboot(): void
            {
                ++$this->reboots;
            }

            public function getCallTimeoutSeconds(): int
            {
                return 0;
            }
        };

        $tool = RectorTool::withRunner($fake);
        $result = $tool->process($this->insideFile(), true);

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
        $details = $result->structuredContent ?? [];

        self::assertSame(-1, $details['exit_code']);
        self::assertSame(2, $fake->runs, 'recoverable error retried once then gives up');
        self::assertSame(1, $fake->reboots);
        self::assertStringContainsString('toMutatingScope', $details['error'] ?? '');
    }
}
