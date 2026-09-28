<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\RectorDiagnosticsSource;
use Dpt\McpRectorWarm\RectorTool;
use Dpt\McpRectorWarm\RunnerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Self-review finding on #53: extractReport() used to json_decode() the
 * WHOLE remainder of $output from the first '{', which fails (silently --
 * indistinguishable from "0 changes") the moment anything follows the
 * closing '}'. These pin the fixed, brace-matching behaviour.
 */
final class RectorDiagnosticsSourceTest extends TestCase
{
    private string $cwdBackup;
    private string $workDir;

    protected function setUp(): void
    {
        $this->cwdBackup = getcwd() ?: '/';
        $this->workDir = sys_get_temp_dir() . '/mcp-rector-lsp-diag-' . bin2hex(random_bytes(4));
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

    private function fakeSource(string $output): RectorDiagnosticsSource
    {
        $runner = new class ($output) implements RunnerInterface {
            public function __construct(private readonly string $output)
            {
            }

            public function run(array $argv, bool $dryRun = true): array
            {
                return ['exit_code' => 0, 'output' => $this->output, 'warm_boot' => false];
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
        };

        return new RectorDiagnosticsSource(RectorTool::withRunner($runner));
    }

    public function testExtractsAReportWithNothingElseAroundIt(): void
    {
        $result = $this->fakeSource('{"totals":{"changed_files":0,"errors":0}}')
            ->diagnose($this->workDir . '/Sample.php');

        self::assertSame([], $result['fixes']);
    }

    public function testExtractsAReportWithLeadingNoiseBeforeIt(): void
    {
        // Mirrors tests/E2E/mcp_harness.py's rector_report(): Rector's own
        // no-config warning (or similar) can print before the JSON.
        $output = "Note: no rector.php found, using defaults\n"
            . '{"totals":{"changed_files":0,"errors":0}}';

        $result = $this->fakeSource($output)->diagnose($this->workDir . '/Sample.php');

        self::assertSame([], $result['fixes']);
    }

    public function testExtractsAReportWithTrailingNoiseAfterIt(): void
    {
        // The fixed case: a byte (here, a PHP deprecation notice a future
        // Rector/PHP could print) AFTER the closing '}' used to make the
        // whole-remainder json_decode() fail, silently, every time.
        $output = '{"totals":{"changed_files":1,"errors":0},"file_diffs":['
            . '{"file":"Sample.php","diff":"--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old\n+new\n",'
            . '"applied_rectors":["SomeRector"],"changes":[]}'
            . '],"changed_files":["Sample.php"]}'
            . "\nDeprecated: something (Sample.php on line 3)\n";

        $result = $this->fakeSource($output)->diagnose($this->workDir . '/Sample.php');

        self::assertCount(1, $result['fixes']);
        self::assertSame(['SomeRector'], $result['fixes'][0]['rectors']);
    }

    public function testABraceInsideAQuotedDiffStringIsNotMistakenForStructure(): void
    {
        // The diff text itself can legitimately contain '{' / '}' (PHP code).
        // A naive brace COUNTER with no string-awareness would close early.
        $output = '{"totals":{"changed_files":1,"errors":0},"file_diffs":['
            . '{"file":"Sample.php","diff":"--- Original\n+++ New\n@@ -1,1 +1,3 @@\n-old\n+if (true) {\n+}\n",'
            . '"applied_rectors":["SomeRector"],"changes":[]}'
            . '],"changed_files":["Sample.php"]}';

        $result = $this->fakeSource($output)->diagnose($this->workDir . '/Sample.php');

        self::assertCount(1, $result['fixes']);
    }

    public function testNoParsableJsonAtAllProducesNoFixesRatherThanAnError(): void
    {
        // Negative control: genuinely unparsable output must degrade to "no
        // diagnostics", not throw and take the whole LSP loop down with it.
        $result = $this->fakeSource('not json at all')->diagnose($this->workDir . '/Sample.php');

        self::assertSame([], $result['fixes']);
    }
}
