<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorTool;
use Dpt\McpRectorWarm\RunnerInterface;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;

/**
 * #72: a `--call-timeout` deadline kills the analysis process unconditionally
 * (SIGKILL / signal 9), which can land mid-write on a file Rector is
 * currently rewriting (truncate-then-write), leaving it truncated with no
 * copy of the original anywhere. RectorTool::process must refuse a
 * `dryRun: false` call while a call-timeout deadline is active, BEFORE ever
 * invoking the runner -- a dry-run call never writes, so it stays safe to
 * kill regardless of the timeout, and a call made with the timeout disabled
 * (0 = unlimited) is unaffected either way.
 */
final class RectorToolCallTimeoutSafetyTest extends TestCase
{
    private string $cwdBackup;
    private string $workDir;

    protected function setUp(): void
    {
        $this->cwdBackup = getcwd() ?: '/';
        $this->workDir = sys_get_temp_dir() . '/mcp-rector-timeout-safety-' . bin2hex(random_bytes(4));
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

    private function fakeRunner(int $callTimeoutSeconds): object
    {
        return new class ($callTimeoutSeconds) implements RunnerInterface {
            public int $runs = 0;

            public function __construct(private int $callTimeoutSeconds)
            {
            }

            public function run(array $argv): array
            {
                ++$this->runs;

                return ['exit_code' => 0, 'output' => '{"totals":{"errors":0}}', 'warm_boot' => false];
            }

            public function isWarm(): bool
            {
                return false;
            }

            public function reboot(): void
            {
            }

            public function getCallTimeoutSeconds(): int
            {
                return $this->callTimeoutSeconds;
            }
        };
    }

    /**
     * The bar this test must clear: it must actually exercise the refusal
     * path (positive control lives in testAllowsNonDryRunCallWhenTimeoutIsDisabled
     * below), not merely fail to see a run because nothing was ever attempted.
     */
    public function testRefusesNonDryRunCallWhileTimeoutIsActive(): void
    {
        $fake = $this->fakeRunner(600);

        $tool = RectorTool::withRunner($fake);
        $result = $tool->process($this->insideFile(), false);

        self::assertSame(0, $fake->runs, 'the runner must never be invoked when the call is refused');
        self::assertInstanceOf(CallToolResult::class, $result, 'a refusal must surface as an MCP tool error');
        self::assertTrue($result->isError);
        $details = $result->structuredContent ?? [];
        self::assertSame(-1, $details['exit_code'] ?? null);
        self::assertStringContainsString('--call-timeout', $details['error'] ?? '');
        self::assertStringContainsString('dryRun', $details['error'] ?? '');
    }

    /** Positive control: the same non-dry-run call must actually run when the
     *  deadline is disabled (0 = unlimited, --call-timeout=0) -- proving the
     *  refusal above is keyed to the active timeout, not to dryRun:false alone. */
    public function testAllowsNonDryRunCallWhenTimeoutIsDisabled(): void
    {
        $fake = $this->fakeRunner(0);

        $tool = RectorTool::withRunner($fake);
        $result = $tool->process($this->insideFile(), false);

        self::assertSame(1, $fake->runs, 'the runner must be invoked once the deadline is disabled');
        self::assertSame(0, $result['exit_code'] ?? null);
    }

    /** A dry-run call never writes, so it must stay unaffected by the guard
     *  even while a call-timeout deadline is active. */
    public function testAllowsDryRunCallWhileTimeoutIsActive(): void
    {
        $fake = $this->fakeRunner(600);

        $tool = RectorTool::withRunner($fake);
        $result = $tool->process($this->insideFile(), true);

        self::assertSame(1, $fake->runs, 'a dry-run call must still be allowed to run');
        self::assertSame(0, $result['exit_code'] ?? null);
    }
}
