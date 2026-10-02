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
            public function __construct(private readonly string $output) {}

            public function run(array $argv, bool $dryRun = true, bool $noSession = false): array
            {
                return ['exit_code' => 0, 'output' => $this->output, 'warm_boot' => false];
            }

            public function isWarm(): bool
            {
                return false;
            }

            public function reboot(): void {}

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
        // Negative control for #90's must-fire test below: a clean report
        // must not surface a spurious error either.
        self::assertSame([], ($result['errors'] ?? []));
    }

    /**
     * #213: a `file_diffs` entry whose fields have the wrong type used to
     * reach RectorDiffParser::buildFixes() as-is -- a TypeError for a
     * non-string `diff`, which ended the LSP loop. It is now reported as an
     * error diagnostic. The positive control is
     * testExtractsAReportWithTrailingNoiseAfterIt (a well-formed entry still
     * yields its fix).
     */
    public function testAMalformedFileDiffsEntryBecomesAnErrorInsteadOfACrash(): void
    {
        $output = '{"totals":{"changed_files":1,"errors":0},"file_diffs":[{"file":"Sample.php","diff":42,"applied_rectors":[],"changes":[]}]}';

        $result = $this->fakeSource($output)->diagnose($this->workDir . '/Sample.php');

        self::assertSame([], $result['fixes']);
        self::assertSame(
            [['message' => 'rector_process produced a malformed report: "diff" is int, expected a string', 'line' => 0]],
            $result['errors'] ?? null,
        );
    }

    public function testAMalformedChangesLineBecomesAnErrorInsteadOfAMisattributedFix(): void
    {
        $output = '{"totals":{"changed_files":1,"errors":0},"file_diffs":[{"file":"Sample.php",'
            . '"diff":"--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old\n+new\n",'
            . '"applied_rectors":["RectorA"],"changes":[{"rector":"RectorA","line":"1"}]}]}';

        $result = $this->fakeSource($output)->diagnose($this->workDir . '/Sample.php');

        self::assertSame([], $result['fixes']);
        self::assertSame(
            [['message' => 'rector_process produced a malformed report: "changes[].line" is string, expected an int', 'line' => 0]],
            $result['errors'] ?? null,
        );
    }

    public function testDiagnoseWorkspaceReportsAMalformedEntryAndKeepsTheWellFormedOne(): void
    {
        $output = '{"totals":{"changed_files":2,"errors":0},"file_diffs":['
            . '{"file":"A.php","diff":"--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old\n+new\n",'
            . '"applied_rectors":["RectorA"],"changes":[]},'
            . '{"file":"B.php","diff":"--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old\n+new\n",'
            . '"applied_rectors":"RectorB","changes":[]}'
            . ']}';

        $result = $this->fakeSource($output)->diagnoseWorkspace($this->workDir);

        self::assertSame([$this->workDir . DIRECTORY_SEPARATOR . 'A.php'], array_keys($result['files']));
        self::assertSame([[
            'message' => 'rector_process produced a malformed report: "applied_rectors" is string, expected a list',
            'line' => 0,
            'file' => $this->workDir . DIRECTORY_SEPARATOR . 'B.php',
        ]], $result['errors']);
    }

    public function testASyntaxErrorProducesAnErrorDiagnosticInsteadOfSilentlyClearing(): void
    {
        // #90 must-fire: paired with testExtractsAReportWithTrailingNoiseAfterIt
        // below (the existing positive control for a fixable file). A report
        // whose `errors` is non-empty and whose `file_diffs` is empty used to
        // come back looking identical to "nothing to report" -- a file that
        // goes from fixable to a syntax error dropped to zero diagnostics,
        // reading as clean in the editor.
        $output = '{"totals":{"changed_files":0,"errors":1},'
            . '"errors":[{"message":"Syntax error, unexpected token","line":7}],'
            . '"file_diffs":[]}';

        $result = $this->fakeSource($output)->diagnose($this->workDir . '/Sample.php');

        self::assertSame([], $result['fixes']);
        self::assertSame([['message' => 'Syntax error, unexpected token', 'line' => 7]], ($result['errors'] ?? []));
    }

    public function testAPathOutsideTheWorkingDirectoryBecomesAnErrorRatherThanSilence(): void
    {
        // #90: the SecurityError/CallToolResult refusal path used to log to
        // stderr (where an editor never looks) and come back with an empty
        // `fixes` list and nothing else -- indistinguishable from "clean".
        $outside = sys_get_temp_dir() . '/mcp-rector-lsp-outside-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($outside, "<?php\n");

        try {
            $result = $this->fakeSource('{"totals":{"changed_files":0,"errors":0}}')->diagnose($outside);

            self::assertSame([], $result['fixes']);
            self::assertCount(1, ($result['errors'] ?? []));
            self::assertSame(0, $result['errors'][0]['line']);
            self::assertStringContainsString('outside the configured working directory', $result['errors'][0]['message']);
        } finally {
            @unlink($outside);
        }
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

    public function testNoParsableJsonAtAllBecomesAnErrorRatherThanSilentlyClean(): void
    {
        // Self-review finding on #90 (independent auditor pass): genuinely
        // unparsable output -- truncated, corrupted, no `{"totals":...}`
        // anywhere -- used to degrade all the way to "no diagnostics",
        // identical to a genuinely clean file. It must never throw (the
        // negative control this test used to be named for still holds --
        // no exception takes the LSP loop down), but it must surface as an
        // error rather than silence.
        $result = $this->fakeSource('not json at all')->diagnose($this->workDir . '/Sample.php');

        self::assertSame([], $result['fixes']);
        self::assertNotSame([], ($result['errors'] ?? []));
    }

    /**
     * #102: diagnoseWorkspace() is the multi-file sibling of diagnose() --
     * every `file_diffs` entry becomes a `files` entry, not just the first
     * (diagnose()'s own $fileDiffs[0] narrowing).
     */
    public function testDiagnoseWorkspaceReturnsFixesForEveryChangedFile(): void
    {
        $output = '{"totals":{"changed_files":2,"errors":0},"file_diffs":['
            . '{"file":"A.php","diff":"--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old\n+new\n",'
            . '"applied_rectors":["RectorA"],"changes":[]},'
            . '{"file":"sub/B.php","diff":"--- Original\n+++ New\n@@ -2,1 +2,1 @@\n-old2\n+new2\n",'
            . '"applied_rectors":["RectorB"],"changes":[]}'
            . ']}';

        $result = $this->fakeSource($output)->diagnoseWorkspace($this->workDir);

        self::assertSame([], $result['errors']);
        self::assertCount(2, $result['files']);
        // #102: a relative `file_diffs[].file` (Rector's own shape when run
        // against a directory) is resolved against $rootPath -- the
        // WorkspaceDiagnosticsSource contract promises absolute keys.
        self::assertArrayHasKey($this->workDir . DIRECTORY_SEPARATOR . 'A.php', $result['files']);
        self::assertArrayHasKey($this->workDir . DIRECTORY_SEPARATOR . 'sub/B.php', $result['files']);
        self::assertSame(['RectorA'], $result['files'][$this->workDir . DIRECTORY_SEPARATOR . 'A.php'][0]['rectors']);
    }

    /**
     * Portable pin for the json_encode() fix above: forces a literal
     * backslash into the fixture path regardless of what this host's own
     * sys_get_temp_dir() happens to return, so the "raw concatenation into
     * a JSON string literal produces invalid JSON" class of bug is
     * reproducible on any platform, not only observable on Windows CI.
     */
    public function testDiagnoseWorkspaceParsesAFileDiffsEntryContainingABackslash(): void
    {
        $absolute = $this->workDir . '/forced\Windows\Style\Sample.php';
        $output = '{"totals":{"changed_files":1,"errors":0},"file_diffs":['
            . '{"file":' . json_encode($absolute) . ',"diff":"--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old\n+new\n",'
            . '"applied_rectors":["SomeRector"],"changes":[]}'
            . ']}';

        $result = $this->fakeSource($output)->diagnoseWorkspace($this->workDir);

        self::assertSame([], $result['errors']);
        self::assertArrayHasKey($absolute, $result['files']);
    }

    public function testDiagnoseWorkspaceKeepsAnAlreadyAbsoluteFilePathAsIs(): void
    {
        // CI fix (PR #137, windows-latest legs): $absolute is built from
        // sys_get_temp_dir(), which returns a backslash path on Windows --
        // raw string concatenation into a JSON literal (this test's own
        // first version) embeds those backslashes UNESCAPED, producing
        // invalid JSON ("Invalid \escape") that extractReport() cannot
        // parse. json_encode() escapes it correctly, the same way Rector's
        // real `--output-format=json` output would.
        $absolute = $this->workDir . '/Sample.php';
        $output = '{"totals":{"changed_files":1,"errors":0},"file_diffs":['
            . '{"file":' . json_encode($absolute) . ',"diff":"--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old\n+new\n",'
            . '"applied_rectors":["SomeRector"],"changes":[]}'
            . ']}';

        $result = $this->fakeSource($output)->diagnoseWorkspace($this->workDir);

        self::assertArrayHasKey($absolute, $result['files']);
    }

    /**
     * Negative control for the two tests above: a report with no file_diffs
     * at all (nothing to fix) returns an empty `files` map, not an error --
     * mirrors diagnose()'s own "empty fixes is not itself a failure" shape.
     */
    public function testDiagnoseWorkspaceWithNothingToFixReturnsEmptyFiles(): void
    {
        $result = $this->fakeSource('{"totals":{"changed_files":0,"errors":0},"file_diffs":[]}')
            ->diagnoseWorkspace($this->workDir);

        self::assertSame([], $result['files']);
        self::assertSame([], $result['errors']);
    }

    /**
     * #141: `diagnoseWorkspace()`'s own errors need to say WHICH file
     * failed -- a bare message alone cannot disambiguate when several
     * files are in play. Rector's own multi-file report names the file
     * per error entry; buildErrors() now preserves it rather than
     * dropping it on the floor.
     */
    public function testDiagnoseWorkspacePreservesTheFileNameOnAPerFileError(): void
    {
        $output = '{"totals":{"changed_files":0,"errors":1},'
            . '"errors":[{"message":"Syntax error, unexpected token","file":"Bad.php","line":7}],'
            . '"file_diffs":[]}';

        $result = $this->fakeSource($output)->diagnoseWorkspace($this->workDir);

        self::assertSame(
            [['message' => 'Syntax error, unexpected token', 'line' => 7, 'file' => 'Bad.php']],
            $result['errors'],
        );
    }

    /**
     * Negative control for the test above: an error entry with no `file`
     * key at all (Rector's single-file-style shape) must not gain one --
     * `buildErrors()` must not invent a value where the raw data had none.
     */
    public function testDiagnoseWorkspaceLeavesFileAbsentWhenTheRawEntryDidNotNameOne(): void
    {
        $output = '{"totals":{"changed_files":0,"errors":1},'
            . '"errors":[{"message":"Syntax error, unexpected token","line":7}],'
            . '"file_diffs":[]}';

        $result = $this->fakeSource($output)->diagnoseWorkspace($this->workDir);

        self::assertSame(
            [['message' => 'Syntax error, unexpected token', 'line' => 7]],
            $result['errors'],
        );
        self::assertArrayNotHasKey('file', $result['errors'][0]);
    }

    /**
     * #90-equivalent for the workspace path: a refused call (out-of-root,
     * SecurityError) must surface as an `errors` entry, not silently look
     * like "nothing to fix".
     */
    public function testDiagnoseWorkspaceRefusalBecomesAnErrorRatherThanSilence(): void
    {
        $outside = sys_get_temp_dir() . '/mcp-rector-lsp-workspace-outside-' . bin2hex(random_bytes(4));
        mkdir($outside, 0o700, true);

        try {
            $result = $this->fakeSource('{"totals":{"changed_files":0,"errors":0}}')->diagnoseWorkspace($outside);

            self::assertSame([], $result['files']);
            self::assertCount(1, $result['errors']);
            self::assertStringContainsString('outside the configured working directory', $result['errors'][0]['message']);
        } finally {
            @rmdir($outside);
        }
    }

    /**
     * #263: interpretWorkspace()'s own "no parseable report" branch was
     * untested on its own -- diagnose()'s equivalent is pinned by
     * testNoParsableJsonAtAllBecomesAnErrorRatherThanSilentlyClean above,
     * but nothing called diagnoseWorkspace() with unparseable output.
     */
    public function testDiagnoseWorkspaceWithNoParsableReportBecomesAnErrorRatherThanSilence(): void
    {
        $result = $this->fakeSource('not json at all')->diagnoseWorkspace($this->workDir);

        self::assertSame([], $result['files']);
        self::assertNotSame([], $result['errors']);
        self::assertSame(
            'rector_process succeeded but produced no parseable report.',
            $result['errors'][0]['message'],
        );
    }

    /**
     * #263: interpretWorkspace()'s file_diffs loop skips any entry that is
     * not itself an array, and any entry whose `file` is missing, non-
     * string, or empty -- rather than letting either crash or silently
     * corrupt the keyed `files` map. One valid entry alongside each invalid
     * shape proves the valid one still survives.
     */
    public function testDiagnoseWorkspaceSkipsUnusableFileDiffsEntriesButKeepsAUsableOne(): void
    {
        // Each invalid-shape entry below also carries a real diff and a
        // rector name of its own -- if the guard that is meant to skip it
        // were missing, it would produce a non-empty `fixes` array and
        // either surface as an extra `files` entry or crash resolving an
        // absolute path from a non-string/empty `file`, rather than
        // silently matching the expected outcome by coincidence (an empty
        // diff would mask the guard's absence either way).
        $output = '{"totals":{"changed_files":1,"errors":0},"file_diffs":['
            . '"not an array",'
            . '{"diff":"--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old\n+new\n",'
            . '"applied_rectors":["NoFileKeyRector"],"changes":[]},'
            . '{"file":"","diff":"--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old\n+new\n",'
            . '"applied_rectors":["EmptyFileRector"],"changes":[]},'
            . '{"file":"A.php","diff":"--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old\n+new\n",'
            . '"applied_rectors":["SomeRector"],"changes":[]}'
            . ']}';

        $result = $this->fakeSource($output)->diagnoseWorkspace($this->workDir);

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['files']);
        self::assertArrayHasKey($this->workDir . DIRECTORY_SEPARATOR . 'A.php', $result['files']);
        self::assertSame(['SomeRector'], $result['files'][$this->workDir . DIRECTORY_SEPARATOR . 'A.php'][0]['rectors']);
    }

    /**
     * #263: buildErrors() treats a non-array `errors` value the same as it
     * already treats an absent one -- no errors, rather than a TypeError
     * from iterating a string.
     */
    public function testDiagnoseWorkspaceTreatsANonArrayErrorsValueAsNoErrors(): void
    {
        $result = $this->fakeSource('{"totals":{"changed_files":0,"errors":0},"errors":"not an array","file_diffs":[]}')
            ->diagnoseWorkspace($this->workDir);

        self::assertSame([], $result['errors']);
    }

    /**
     * #263: buildErrors() also tolerates a bare string error entry, not
     * just the documented {"message":...,"line":...} shape.
     */
    public function testDiagnoseWorkspaceAcceptsABareStringErrorEntry(): void
    {
        $output = '{"totals":{"changed_files":0,"errors":1},'
            . '"errors":["a plain string error"],'
            . '"file_diffs":[]}';

        $result = $this->fakeSource($output)->diagnoseWorkspace($this->workDir);

        self::assertSame([['message' => 'a plain string error', 'line' => 0]], $result['errors']);
    }

    /**
     * #263 negative control: an error entry that is neither an array nor a
     * string is skipped outright, alongside a usable entry that must still
     * survive.
     */
    public function testDiagnoseWorkspaceSkipsAnErrorEntryThatIsNeitherArrayNorString(): void
    {
        $output = '{"totals":{"changed_files":0,"errors":2},'
            . '"errors":[null,"a plain string error"],'
            . '"file_diffs":[]}';

        $result = $this->fakeSource($output)->diagnoseWorkspace($this->workDir);

        self::assertSame([['message' => 'a plain string error', 'line' => 0]], $result['errors']);
    }

    /**
     * #263: extractReport()'s scan `continue`s past a `{` that never closes
     * rather than stopping there -- an unbalanced opening brace ahead of the
     * real report must not hide it. Confirmed by direct simulation: the
     * first `{` (right after "broken") never reaches depth 0 before the
     * string ends; the scan resumes at the next `{` and decodes the real,
     * separate report that follows.
     */
    public function testExtractReportSkipsAnUnclosedBraceThatPrecedesTheRealReport(): void
    {
        $result = $this->fakeSource('{broken{"totals":{"changed_files":0,"errors":0}}')
            ->diagnose($this->workDir . '/Sample.php');

        self::assertSame([], $result['fixes']);
        self::assertSame([], ($result['errors'] ?? []));
    }

    /**
     * #263: matchingBraceEnd() falls through its loop to a final `return
     * null` when $output ends before the brace it started at ever closes --
     * distinct from "no `{` at all"
     * (testNoParsableJsonAtAllBecomesAnErrorRatherThanSilentlyClean above),
     * which never calls matchingBraceEnd() to begin with.
     */
    public function testTruncatedJsonWithAnUnclosedBraceStillBecomesAnErrorRatherThanSilence(): void
    {
        $result = $this->fakeSource('{"totals": truncated without closing')
            ->diagnose($this->workDir . '/Sample.php');

        self::assertSame([], $result['fixes']);
        self::assertNotSame([], ($result['errors'] ?? []));
    }

    /**
     * CI fix (PR #137, windows-latest legs): isAbsolutePath()'s character
     * class only ever matched a drive letter followed by a forward slash
     * (`C:/...`), never the real Windows form (`C:\...`) -- a real Windows
     * `file_diffs[].file` entry that was already absolute got treated as
     * relative and re-prefixed with $rootPath, producing a doubled/mangled
     * path. Pure string manipulation (no OS API call, no realpath()), so
     * this pins both separator forms deterministically on any host --
     * unlike the platform-forced tests elsewhere in this suite
     * (RectorToolContainmentTest, isWithinRoot's $caseInsensitive param),
     * this needs no explicit force parameter to exercise the Windows
     * branch portably.
     *
     * @dataProvider absoluteAndRelativePaths
     */
    public function testIsAbsolutePathRecognisesBothWindowsAndPosixAbsoluteForms(string $path, bool $expected): void
    {
        $method = new \ReflectionMethod(RectorDiagnosticsSource::class, 'isAbsolutePath');

        self::assertSame($expected, $method->invoke(null, $path));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function absoluteAndRelativePaths(): iterable
    {
        yield 'windows backslash absolute' => ['C:\Users\me\A.php', true];
        yield 'windows forward-slash absolute' => ['C:/Users/me/A.php', true];
        yield 'posix absolute' => ['/home/user/A.php', true];
        yield 'relative, no drive letter' => ['src/A.php', false];
        yield 'relative with backslash separators' => ['src\A.php', false];
    }
}
