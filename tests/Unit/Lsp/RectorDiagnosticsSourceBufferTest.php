<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\RectorDiagnosticsSource;
use Dpt\McpRectorWarm\RectorTool;
use Dpt\McpRectorWarm\RunnerInterface;
use PHPUnit\Framework\TestCase;

/**
 * #106: an unsaved buffer is diagnosed by writing it to a temp file inside
 * the project, next to the original, running Rector on that, and deleting it
 * again -- on success, on a Rector error, and on a runner that throws.
 */
final class RectorDiagnosticsSourceBufferTest extends TestCase
{
    private string $cwdBackup;
    private string $workDir;
    private string $original;

    protected function setUp(): void
    {
        $this->cwdBackup = getcwd() ?: '/';
        $this->workDir = sys_get_temp_dir() . '/mcp-rector-lsp-buffer-' . bin2hex(random_bytes(4));
        mkdir($this->workDir . '/src', 0o700, true);
        chdir($this->workDir);
        $this->original = $this->workDir . '/src/Sample.php';
        file_put_contents($this->original, "<?php\n\nclass Sample\n{\n}\n");
    }

    protected function tearDown(): void
    {
        chdir($this->cwdBackup);
        $this->removeTree($this->workDir);
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                self::removeLink($path);
            } elseif (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Removes a symlink itself, never its target. On Windows a link to a
     * directory is a directory entry: unlink() refuses it ("Is a
     * directory") and rmdir() is what removes the link -- still without
     * touching the target. rmdir() is tried first there because a link
     * whose target is already gone no longer answers is_dir(). Elsewhere
     * unlink() removes the link.
     */
    private static function removeLink(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows' && @rmdir($path)) {
            return;
        }
        unlink($path);
    }

    /**
     * Every entry under the project, relative, so a leftover temp file or
     * temp directory shows up by name in the failure message.
     *
     * @return list<string>
     */
    private function projectEntries(): array
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $file) {
            $entries[] = str_replace('\\', '/', substr((string) $file, strlen($this->workDir) + 1));
        }
        sort($entries);

        return $entries;
    }

    /**
     * @param \Closure(string $path): string $behaviour gets the path Rector
     *   was asked to process and returns Rector's raw output (or throws)
     */
    private function source(\Closure $behaviour): RectorDiagnosticsSource
    {
        $runner = new class ($behaviour) implements RunnerInterface {
            public function __construct(private readonly \Closure $behaviour)
            {
            }

            public function run(array $argv, bool $dryRun = true): array
            {
                return ['exit_code' => 0, 'output' => ($this->behaviour)(end($argv), $argv), 'warm_boot' => false];
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

    public function testTheBufferIsProcessedFromATempFileBesideTheOriginalThenRemoved(): void
    {
        $seen = null;
        $buffer = "<?php\n\nclass Sample\n{\n    // unsaved\n}\n";
        $output = '{"totals":{"changed_files":1,"errors":0},"file_diffs":['
            . '{"file":"x","diff":"--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old\n+new\n",'
            . '"applied_rectors":["SomeRector"],"changes":[]}]}';

        $result = $this->source(function (string $path) use (&$seen, $output): string {
            $seen = ['path' => $path, 'exists' => is_file($path), 'content' => (string) @file_get_contents($path)];

            return $output;
        })->diagnoseBuffer($this->original, $buffer);

        self::assertNotNull($seen);
        self::assertNotSame($this->original, $seen['path']);
        self::assertTrue($seen['exists']);
        self::assertSame($buffer, $seen['content']);
        // Same basename, one hidden directory below the original's own
        // directory: path-glob skips like `*/Sample.php` or `*Test.php` and
        // directory skips keep matching.
        self::assertSame('Sample.php', basename($seen['path']));
        self::assertSame(dirname($this->original), dirname($seen['path'], 2));
        self::assertStringStartsWith('.rector-warm-', basename(dirname($seen['path'])));

        self::assertCount(1, $result['fixes']);
        self::assertSame(['SomeRector'], $result['fixes'][0]['rectors']);
        self::assertSame(['src', 'src/Sample.php'], $this->projectEntries());
        self::assertSame("<?php\n\nclass Sample\n{\n}\n", file_get_contents($this->original));
    }

    public function testTheRunIsToldWhichOriginalPathTheTempCopyStandsFor(): void
    {
        // #106 E2E finding: Rector's skip matching compares the processed
        // path, so an exact-path, relative-path, parent-dir glob or
        // rule-scoped skip of the original never matched the temp copy. The
        // worker applies the ORIGINAL path's skips to the copy, and needs the
        // original path for that: passed as an option before `--`, with the
        // temp copy still the one path Rector processes.
        $argvSeen = null;
        $this->source(function (string $path, array $argv) use (&$argvSeen): string {
            $argvSeen = $argv;

            return '{"totals":{"changed_files":0,"errors":0}}';
        })->diagnoseBuffer($this->original, "<?php\n");

        self::assertNotNull($argvSeen);
        $separator = array_search('--', $argvSeen, true);
        self::assertIsInt($separator);
        self::assertContains('--rector-warm-skip-as=' . $this->original, array_slice($argvSeen, 0, $separator));
        self::assertCount(1, array_slice($argvSeen, $separator + 1));
        self::assertStringContainsString('.rector-warm-', end($argvSeen));
    }

    public function testAPlainProcessCallCarriesNoSkipAsOption(): void
    {
        // Negative control: the disk path (and the MCP tool) never passes it.
        $argvSeen = null;
        $this->source(function (string $path, array $argv) use (&$argvSeen): string {
            $argvSeen = $argv;

            return '{"totals":{"changed_files":0,"errors":0}}';
        })->diagnose($this->original);

        self::assertNotNull($argvSeen);
        self::assertSame([], array_values(array_filter($argvSeen, static fn (string $a): bool => str_starts_with($a, '--rector-warm-skip-as'))));
    }

    public function testABufferRunCleansUpADeadServersLeftoverBesideIt(): void
    {
        $process = proc_open([PHP_BINARY, '-r', ''], [], $pipes);
        $deadPid = proc_get_status($process)['pid'];
        proc_close($process);
        $leftover = $this->workDir . '/src/.rector-warm-' . $deadPid;
        mkdir($leftover);
        file_put_contents($leftover . '/Sample.php', "<?php\n");

        $this->source(fn (): string => '{"totals":{"changed_files":0,"errors":0}}')
            ->diagnoseBuffer($this->original, "<?php\n");

        self::assertSame(['src', 'src/Sample.php'], $this->projectEntries());
    }

    public function testASyntaxErrorBufferReportsTheErrorAgainstTheOriginalAndLeavesNothingBehind(): void
    {
        $result = $this->source(function (string $path): string {
            return json_encode([
                'totals' => ['changed_files' => 0, 'errors' => 1],
                'errors' => [['message' => 'Syntax error in ' . $path . ', unexpected EOF', 'file' => $path, 'line' => 3]],
                'file_diffs' => [],
            ], JSON_UNESCAPED_SLASHES);
        })->diagnoseBuffer($this->original, "<?php\n\nclass Sample {\n");

        self::assertSame([], $result['fixes']);
        self::assertCount(1, $result['errors']);
        self::assertSame(3, $result['errors'][0]['line']);
        self::assertStringNotContainsString('.rector-warm-', $result['errors'][0]['message']);
        self::assertStringContainsString('Sample.php', $result['errors'][0]['message']);
        self::assertSame(['src', 'src/Sample.php'], $this->projectEntries());
    }

    public function testARunnerThatThrowsStillLeavesNothingBehind(): void
    {
        $result = $this->source(function (string $path): string {
            throw new \RuntimeException('worker died on ' . $path);
        })->diagnoseBuffer($this->original, "<?php\n");

        self::assertSame([], $result['fixes']);
        self::assertNotSame([], $result['errors']);
        self::assertStringNotContainsString('.rector-warm-', $result['errors'][0]['message']);
        self::assertSame(['src', 'src/Sample.php'], $this->projectEntries());
    }

    public function testABufferForAFileOutsideTheProjectIsRefusedWithoutWritingAnything(): void
    {
        $outsideDir = sys_get_temp_dir() . '/mcp-rector-lsp-outside-' . bin2hex(random_bytes(4));
        mkdir($outsideDir);

        try {
            $called = false;
            $result = $this->source(function () use (&$called): string {
                $called = true;

                return '{"totals":{"changed_files":0,"errors":0}}';
            })->diagnoseBuffer($outsideDir . '/Evil.php', "<?php\n");

            self::assertFalse($called);
            self::assertStringContainsString('outside the configured working directory', $result['errors'][0]['message']);
            self::assertSame(['.', '..'], scandir($outsideDir));
        } finally {
            @rmdir($outsideDir);
        }
    }

    /**
     * #142: a symlink planted at the deterministic `.rector-warm-<pid>` name
     * beside the original must not have anything written through it, and --
     * the gap a first attempt at this fix left open -- must not have
     * anything unlinked through it in the `finally` block either, whether or
     * not a same-named file already sits at the symlink's target.
     */
    public function testABufferForASymlinkedTempDirectoryIsRefusedAndNothingOutsideIsTouched(): void
    {
        $externalDir = sys_get_temp_dir() . '/mcp-rector-lsp-external-' . bin2hex(random_bytes(4));
        mkdir($externalDir, 0o700, true);
        $externalFile = $externalDir . '/Sample.php';
        file_put_contents($externalFile, "<?php\n\nclass NotYours\n{\n}\n");

        $symlinkPath = $this->workDir . '/src/.rector-warm-' . getmypid();

        try {
            self::assertTrue(symlink($externalDir, $symlinkPath), 'could not create the test symlink');

            $called = false;
            $result = $this->source(function () use (&$called): string {
                $called = true;

                return '{"totals":{"changed_files":0,"errors":0}}';
            })->diagnoseBuffer($this->original, "<?php\n\nclass Sample\n{\n    // unsaved\n}\n");

            self::assertFalse($called, 'a symlinked temp directory must be refused before Rector is asked to run');
            self::assertStringContainsString('symlinked or junctioned temp directory', $result['errors'][0]['message']);
            self::assertSame("<?php\n\nclass NotYours\n{\n}\n", file_get_contents($externalFile), 'the finally block must not unlink through the symlink');
            self::assertFileExists($externalFile);
            // The symlink itself is refused, not removed -- it is left in
            // place next to the original, same as any other candidate this
            // code chooses not to touch. The original file is untouched,
            // which is the thing this test guards.
            self::assertSame("<?php\n\nclass Sample\n{\n}\n", file_get_contents($this->original));
        } finally {
            // Removed as a link, never recursed into (following it a second
            // time would be the exact bug under test), and before its target
            // is removed: on Windows a link to a directory needs rmdir().
            self::removeLink($symlinkPath);
            $this->removeTree($externalDir);
        }
    }

    /**
     * #149: round-2 release-delta audit finding. #142/#144's guards above
     * only ever checked `$tempDirectory` -- a REAL, non-symlinked
     * `.rector-warm-<pid>` directory (planted ahead of time, named after
     * this server's own live pid so TempCopySweeper's startup sweep skips
     * it -- it explicitly never sweeps its own pid) passes both of those
     * checks, and the leaf `$tempPath` inside it was never checked at all.
     * A symlink planted there, named after the buffer's own basename, must
     * not be written through, and -- the same finally-block gap #142/#144
     * closed for `$tempDirectory` -- must not be unlinked through either.
     *
     * #165: since a pre-existing `.rector-warm-<pid>` directory is now
     * refused outright (see testAPreExistingTempDirectoryIsRefusedRatherThanReused
     * above), this attack is caught one step earlier than it used to be --
     * at the directory-level refusal rather than at the leaf-level
     * isLinkOrJunction($tempPath) check this test originally targeted. The
     * refusal, and everything it protects (the write never happens, the
     * symlink is never followed, nothing outside is touched), still holds;
     * only the specific error message differs.
     */
    public function testABufferForASymlinkedTempFileInARealTempDirectoryIsRefusedAndNothingOutsideIsTouched(): void
    {
        $externalFile = sys_get_temp_dir() . '/mcp-rector-lsp-external-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($externalFile, "<?php\n\nclass NotYours\n{\n}\n");

        $realTempDirectory = $this->workDir . '/src/.rector-warm-' . getmypid();
        mkdir($realTempDirectory, 0o700, true);
        $symlinkPath = $realTempDirectory . '/Sample.php';

        try {
            self::assertTrue(symlink($externalFile, $symlinkPath), 'could not create the test symlink');

            $called = false;
            $result = $this->source(function () use (&$called): string {
                $called = true;

                return '{"totals":{"changed_files":0,"errors":0}}';
            })->diagnoseBuffer($this->original, "<?php\n\nclass Sample\n{\n    // unsaved\n}\n");

            self::assertFalse($called, 'a symlinked temp file inside a pre-existing temp directory must be refused before Rector is asked to run');
            self::assertStringContainsString('pre-existing temp directory', $result['errors'][0]['message']);
            self::assertSame("<?php\n\nclass NotYours\n{\n}\n", file_get_contents($externalFile), 'the write must not follow the symlink');
            self::assertFileExists($externalFile);
            // The symlink itself is refused, not removed -- the finally
            // block's unlink() must not follow it either. The original file
            // is untouched, which is the thing this test guards.
            self::assertSame("<?php\n\nclass Sample\n{\n}\n", file_get_contents($this->original));
        } finally {
            self::removeLink($symlinkPath);
            @rmdir($realTempDirectory);
            @unlink($externalFile);
        }
    }

    /**
     * #161: round-3 release-delta audit finding. isLinkOrJunction() (used
     * by the guard above) only widens is_link() with a Windows junction
     * check -- neither detects a HARD LINK, a second directory entry
     * pointing at the same inode as a file elsewhere. is_link() is
     * correctly false for a hard link (it genuinely is not a symlink), so
     * it slips past the exact guard that refuses a symlink above. Same
     * premise as that test: a real, non-symlinked `.rector-warm-<pid>`
     * directory, planted ahead of time and named after this server's own
     * live pid so the startup sweep skips it, with a hard link at the leaf
     * instead of a symlink.
     */
    public function testABufferForAHardLinkedTempFileInARealTempDirectoryIsRefusedAndNothingOutsideIsTouched(): void
    {
        $externalFile = sys_get_temp_dir() . '/mcp-rector-lsp-external-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($externalFile, "<?php\n\nclass NotYours\n{\n}\n");

        $realTempDirectory = $this->workDir . '/src/.rector-warm-' . getmypid();
        mkdir($realTempDirectory, 0o700, true);
        $hardLinkPath = $realTempDirectory . '/Sample.php';

        try {
            self::assertTrue(link($externalFile, $hardLinkPath), 'could not create the test hard link');

            $called = false;
            $result = $this->source(function () use (&$called): string {
                $called = true;

                return '{"totals":{"changed_files":0,"errors":0}}';
            })->diagnoseBuffer($this->original, "<?php\n\nclass Sample\n{\n    // unsaved\n}\n");

            // #165: caught one step earlier than it used to be, by the same
            // directory-level refusal as the symlinked-leaf test above --
            // see that test's docblock. What matters still holds: the write
            // never happens and the hard link is never followed.
            self::assertFalse($called, 'a hard-linked temp file inside a pre-existing temp directory must be refused before Rector is asked to run');
            self::assertStringContainsString('pre-existing temp directory', $result['errors'][0]['message']);
            self::assertSame("<?php\n\nclass NotYours\n{\n}\n", file_get_contents($externalFile), 'the write must not follow the hard link');
            self::assertFileExists($externalFile);
            self::assertSame("<?php\n\nclass Sample\n{\n}\n", file_get_contents($this->original));
        } finally {
            @unlink($hardLinkPath);
            @rmdir($realTempDirectory);
            @unlink($externalFile);
        }
    }

    /**
     * #165: round-4 release-delta audit finding, replacing the round-3 fix
     * this test used to guard. #161's chmod(0700) reapply only succeeds
     * when the CALLING PROCESS OWNS the target -- for a directory an
     * attacker plants ahead of time under this guessable
     * `.rector-warm-<pid>` name (matching this server's own live pid, so
     * the startup sweep skips it), chmod() on a directory owned by a
     * DIFFERENT user silently fails and the guard was a no-op for exactly
     * the case it named. A single-user test process cannot reproduce a
     * genuinely different owner, so this asserts the stronger property the
     * fix actually gives: diagnoseBuffer() refuses ANY pre-existing
     * `.rector-warm-<pid>` directory outright, rather than reusing it and
     * trying to narrow its mode afterwards -- which subsumes the
     * different-owner case without needing multi-user test infrastructure.
     * The buffer must not be written into the pre-existing directory at
     * all, and the pre-existing directory (not the fix's to remove) is left
     * exactly as planted.
     */
    public function testAPreExistingTempDirectoryIsRefusedRatherThanReused(): void
    {
        $realTempDirectory = $this->workDir . '/src/.rector-warm-' . getmypid();
        mkdir($realTempDirectory, 0o777, true);

        try {
            $called = false;
            $result = $this->source(function () use (&$called): string {
                $called = true;

                return '{"totals":{"changed_files":0,"errors":0}}';
            })->diagnoseBuffer($this->original, "<?php\n\nclass Sample\n{\n    // unsaved\n}\n");

            self::assertFalse($called, 'a pre-existing temp directory must be refused before Rector is asked to run');
            self::assertStringContainsString('pre-existing temp directory', $result['errors'][0]['message']);
            self::assertSame("<?php\n\nclass Sample\n{\n}\n", file_get_contents($this->original), 'the original file must be untouched');
            // The `finally` block below removes $tempDirectory on every
            // exit from `try`, including this refusal's early return, so
            // the pre-existing directory is typically gone by now (it was
            // planted empty here) -- is_dir() is checked first so a
            // directory already removed is not itself an assertion
            // failure. Either way, nothing of the buffer's ever landed in
            // it, which is the property this test guards.
            self::assertTrue(
                !is_dir($realTempDirectory) || array_diff(scandir($realTempDirectory), ['.', '..']) === [],
                'the refusal must not write anything into the pre-existing directory',
            );
        } finally {
            @rmdir($realTempDirectory);
        }
    }

    /**
     * Positive control for the guard above: a genuinely real temp file
     * inside a genuinely real temp directory -- no symlink anywhere -- must
     * still be written, processed and cleaned up normally, including across
     * two diagnoseBuffer() calls for the same buffer in a row (the
     * server-lifetime reuse pattern the #149 finding's own repeat-call
     * concern was raised against: `$tempDirectory` is deterministic per
     * (pid, source directory), so a second call on the same buffer targets
     * the very same `$tempPath` the first call already used and cleaned up).
     */
    public function testTwoConsecutiveBufferRunsForTheSameFileBothSucceed(): void
    {
        foreach (['first pass', 'second pass'] as $marker) {
            $seenContent = null;
            $buffer = "<?php\n\nclass Sample\n{\n    // {$marker}\n}\n";

            $result = $this->source(function (string $path) use (&$seenContent): string {
                $seenContent = (string) @file_get_contents($path);

                return '{"totals":{"changed_files":0,"errors":0}}';
            })->diagnoseBuffer($this->original, $buffer);

            self::assertSame($buffer, $seenContent, $marker . ': the temp file must carry this pass\'s own content');
            self::assertSame([], $result['errors'], $marker . ': must not fail');
            self::assertSame(['src', 'src/Sample.php'], $this->projectEntries(), $marker . ': nothing left behind');
        }
    }

    /**
     * #144: an NTFS junction (`mklink /J`) is a distinct Windows
     * reparse-point type from the symlink #142's guard above was proven
     * against -- `mklink /J` needs no elevated privilege, unlike `mklink
     * /D`. PHP's is_link() is documented reliable for POSIX and Windows
     * symlinks; its behaviour on a junction is the open question this
     * guards, at both is_link() call sites in diagnoseBuffer() (the
     * pre-write refusal and the finally block's re-check). Windows-only:
     * there is no junction concept to create elsewhere.
     */
    public function testABufferForAJunctionedTempDirectoryIsRefusedOnWindows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('NTFS junctions only exist on Windows.');
        }

        $externalDir = sys_get_temp_dir() . '/mcp-rector-lsp-external-' . bin2hex(random_bytes(4));
        mkdir($externalDir, 0o700, true);
        $externalFile = $externalDir . '/Sample.php';
        file_put_contents($externalFile, "<?php\n\nclass NotYours\n{\n}\n");

        $junctionPath = $this->workDir . '/src/.rector-warm-' . getmypid();

        try {
            self::createJunction($externalDir, $junctionPath);

            $called = false;
            $result = $this->source(function () use (&$called): string {
                $called = true;

                return '{"totals":{"changed_files":0,"errors":0}}';
            })->diagnoseBuffer($this->original, "<?php\n\nclass Sample\n{\n    // unsaved\n}\n");

            self::assertFalse($called, 'a junctioned temp directory must be refused before Rector is asked to run');
            self::assertStringContainsString('symlinked or junctioned temp directory', $result['errors'][0]['message']);
            self::assertSame("<?php\n\nclass NotYours\n{\n}\n", file_get_contents($externalFile), 'the finally block must not unlink through the junction');
            self::assertFileExists($externalFile);
            self::assertSame("<?php\n\nclass Sample\n{\n}\n", file_get_contents($this->original));
        } finally {
            // Removed as a link, never recursed into (following it a second
            // time would be the exact bug under test), and before its target
            // is removed: rmdir() removes a junction without touching its
            // target, same as it does for a directory symlink on Windows.
            self::removeLink($junctionPath);
            $this->removeTree($externalDir);
        }
    }

    /**
     * `mklink /J` needs no elevated privilege, unlike `mklink /D`. Uses
     * cmd.exe's mklink directly -- PHP's symlink() cannot create a
     * junction, and link() creates a hardlink, a different reparse type
     * again.
     */
    private static function createJunction(string $target, string $link): void
    {
        $output = [];
        $exitCode = 0;
        exec(sprintf('mklink /J %s %s 2>&1', escapeshellarg($link), escapeshellarg($target)), $output, $exitCode);
        self::assertSame(0, $exitCode, 'could not create the test junction: ' . implode("\n", $output));
    }
}
