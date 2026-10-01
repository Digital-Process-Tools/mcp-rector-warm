<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\RectorDiagnosticsSource;
use Dpt\McpRectorWarm\RectorTool;
use Dpt\McpRectorWarm\RunnerInterface;
use PHPUnit\Framework\TestCase;

/**
 * #223: the existing `RunnerInterface` doubles across the suite (e.g. this
 * file's own sibling RectorDiagnosticsSourceTest::fakeSource(), line 46)
 * declare and IGNORE $noSession -- nothing in the suite ever asserted the
 * VALUE actually reaching RectorRunner::run() for the write-bound recompute
 * paths (#216's codeAction/fixWorkspace safety property #220 then turned on
 * by default) versus a plain diagnostics read.
 *
 * These route the REAL RectorDiagnosticsSource -> REAL RectorTool into a
 * runner double that RECORDS the $noSession it was given for each call,
 * pinning: diagnoseForEdit() (the codeAction recompute) and diagnoseWorkspace()
 * (fixWorkspace) always force true; diagnose() (plain diagnostics) never does.
 */
final class RectorDiagnosticsSourceNoSessionWiringTest extends TestCase
{
    private string $cwdBackup;
    private string $workDir;

    protected function setUp(): void
    {
        $this->cwdBackup = getcwd() ?: '/';
        $this->workDir = sys_get_temp_dir() . '/mcp-rector-lsp-nosession-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o700, true);
        chdir($this->workDir);
        file_put_contents($this->workDir . '/Sample.php', "<?php\n\nclass Sample\n{\n}\n");
    }

    protected function tearDown(): void
    {
        chdir($this->cwdBackup);
        @unlink($this->workDir . '/Sample.php');
        @rmdir($this->workDir);
    }

    private function sourceWithSpy(): RectorDiagnosticsSource
    {
        return new RectorDiagnosticsSource(RectorTool::withRunner(new NoSessionSpyRunner()));
    }

    private function spyOf(RectorDiagnosticsSource $source): NoSessionSpyRunner
    {
        $toolProperty = new \ReflectionProperty(RectorDiagnosticsSource::class, 'tool');
        /** @var RectorTool $tool */
        $tool = $toolProperty->getValue($source);
        $runnerProperty = new \ReflectionProperty(RectorTool::class, 'runner');
        /** @var NoSessionSpyRunner $runner */
        $runner = $runnerProperty->getValue($tool);

        return $runner;
    }

    public function testCodeActionRecomputeForcesNoSessionTrue(): void
    {
        $source = $this->sourceWithSpy();
        $source->diagnoseForEdit($this->workDir . '/Sample.php');

        self::assertSame([true], $this->spyOf($source)->recorded);
    }

    public function testFixWorkspaceForcesNoSessionTrue(): void
    {
        $source = $this->sourceWithSpy();
        $source->diagnoseWorkspace($this->workDir);

        self::assertSame([true], $this->spyOf($source)->recorded);
    }

    /**
     * Must-fire positive control (CLAUDE.md: a must-not-fire assertion needs
     * a must-fire sibling): plain diagnostics, with nothing about to turn the
     * result into a WorkspaceEdit, keeps using the session -- noSession must
     * come back false here, not merely "not asserted".
     */
    public function testPlainDiagnosticsReadDoesNotForceNoSession(): void
    {
        $source = $this->sourceWithSpy();
        $source->diagnose($this->workDir . '/Sample.php');

        self::assertSame([false], $this->spyOf($source)->recorded);
    }
}

/**
 * #223 test seam: a RunnerInterface double that records the $noSession value
 * of every run() call, instead of declaring-and-ignoring it like every other
 * double in the suite (the pattern the issue names as the coverage gap).
 */
final class NoSessionSpyRunner implements RunnerInterface
{
    /** @var list<bool> */
    public array $recorded = [];

    public function run(array $argv, bool $dryRun = true, bool $noSession = false): array
    {
        $this->recorded[] = $noSession;

        return ['exit_code' => 0, 'output' => '{"totals":{"changed_files":0,"errors":0}}', 'warm_boot' => false];
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
        return 0;
    }
}
