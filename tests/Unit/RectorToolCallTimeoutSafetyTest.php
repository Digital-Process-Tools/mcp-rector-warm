<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorTool;
use Dpt\McpRectorWarm\RunnerInterface;
use PHPUnit\Framework\TestCase;

/**
 * #72 correction: an earlier version of this fix refused every `dryRun: false`
 * `rector_process` call outright whenever a `--call-timeout` deadline was
 * active -- a breaking change for every caller running under the (now
 * default) 600s timeout. The corrected contract instead: RectorTool::process
 * must NEVER refuse a dryRun:false call, and must pass the real $dryRun flag
 * straight through to the runner (never inferred from $argv) so the runner
 * can gate its own deadline enforcement on it -- proven at the RectorRunner
 * level (never killed while writing) by RectorRunnerTest, and end-to-end by
 * the E2E scenarios this bug broke (apply-then-recheck, apply-twice-
 * idempotent, bom-crlf-spaces).
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
            /** @var list<bool> */
            public array $lastDryRunSeen = [];

            public function __construct(private readonly int $callTimeoutSeconds)
            {
            }

            public function run(array $argv, bool $dryRun = true, bool $noSession = false): array
            {
                ++$this->runs;
                $this->lastDryRunSeen[] = $dryRun;

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
     * The bug this pins: a dryRun:false call must run to completion while a
     * --call-timeout deadline is active, exactly as it did before #58 -- it
     * must never be refused outright (the earlier, now-removed #72 fix) and
     * must never be killed (RectorRunnerTest pins the "never killed" half at
     * the RectorRunner level, and the E2E suite pins it end-to-end).
     */
    public function testAllowsNonDryRunCallWhileTimeoutIsActive(): void
    {
        $fake = $this->fakeRunner(600);

        $tool = RectorTool::withRunner($fake);
        $result = $tool->process($this->insideFile(), false);

        self::assertSame(1, $fake->runs, 'the runner must be invoked -- a write call is never refused (#72 correction)');
        self::assertSame(0, $result['exit_code'] ?? null);
    }

    /**
     * The flag must be threaded through explicitly, not inferred indirectly
     * (e.g. by grepping $argv for '--dry-run') -- the runner sees the SAME
     * boolean the caller passed, for both a write and a dry-run call.
     */
    public function testPassesTheRealDryRunFlagThroughToTheRunner(): void
    {
        $fake = $this->fakeRunner(600);
        $tool = RectorTool::withRunner($fake);

        $tool->process($this->insideFile(), false);
        $tool->process($this->insideFile(), true);

        self::assertSame([false, true], $fake->lastDryRunSeen);
    }

    /** A non-dry-run call must also work when the deadline is disabled
     *  (0 = unlimited, --call-timeout=0) -- unaffected either way. */
    public function testAllowsNonDryRunCallWhenTimeoutIsDisabled(): void
    {
        $fake = $this->fakeRunner(0);

        $tool = RectorTool::withRunner($fake);
        $result = $tool->process($this->insideFile(), false);

        self::assertSame(1, $fake->runs, 'the runner must be invoked once the deadline is disabled');
        self::assertSame(0, $result['exit_code'] ?? null);
    }

    /** A dry-run call must stay allowed while a call-timeout deadline is
     *  active -- the existing --call-timeout enforcement for analysis-only
     *  calls is unchanged by this correction. */
    public function testAllowsDryRunCallWhileTimeoutIsActive(): void
    {
        $fake = $this->fakeRunner(600);

        $tool = RectorTool::withRunner($fake);
        $result = $tool->process($this->insideFile(), true);

        self::assertSame(1, $fake->runs, 'a dry-run call must still be allowed to run');
        self::assertSame(0, $result['exit_code'] ?? null);
    }
}
