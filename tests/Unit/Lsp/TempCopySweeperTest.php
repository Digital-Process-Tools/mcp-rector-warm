<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\TempCopySweeper;
use PHPUnit\Framework\TestCase;

/**
 * #106 E2E finding: a server killed mid-run (kill -9) cannot run its own
 * `finally`, so its `.rector-warm-<pid>` directory stays. The next server
 * sweeps directories whose pid is no longer alive, and keeps the ones
 * whose pid is (another server working in the same project).
 */
final class TempCopySweeperTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mcp-rector-sweep-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                self::removeLink($path);
            } elseif (is_dir($path)) {
                self::removeTree($path);
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

    /** A pid that belonged to a process which has already exited. */
    private static function deadPid(): int
    {
        $process = proc_open([PHP_BINARY, '-r', ''], [], $pipes);
        self::assertIsResource($process);
        $pid = proc_get_status($process)['pid'];
        proc_close($process);

        return $pid;
    }

    private function plant(string $relativeDir, string $file = 'Clean.php'): string
    {
        $dir = $this->root . '/' . $relativeDir;
        mkdir($dir, 0o700, true);
        file_put_contents($dir . '/' . $file, "<?php\n");

        return $dir;
    }

    public function testADeadServersTempDirectoryIsRemovedAndALiveOnesIsKept(): void
    {
        $dead = $this->plant('src/Deep/Er/.rector-warm-' . self::deadPid());
        // Positive control for "kept": this test process is alive and is
        // not the sweeping server either, like a second editor's server.
        $live = $this->plant('src/.rector-warm-' . getmypid());
        $notOurs = $this->plant('src/.rector-warm-notapid');

        $removed = TempCopySweeper::sweepTree($this->root);

        self::assertDirectoryDoesNotExist($dead);
        // The sweeper joins with DIRECTORY_SEPARATOR, the test with '/':
        // compare separator-neutral (Windows).
        $normalize = static fn (string $path): string => str_replace('\\', '/', $path);
        self::assertSame([$normalize($dead)], array_map($normalize, $removed));
        self::assertDirectoryExists($live);
        self::assertDirectoryExists($notOurs);
        self::assertFileExists($this->root . '/src');
    }

    public function testTheWalkSkipsVendorNodeModulesAndGit(): void
    {
        $pid = self::deadPid();
        $inVendor = $this->plant('vendor/pkg/.rector-warm-' . $pid);
        $inNodeModules = $this->plant('node_modules/x/.rector-warm-' . $pid);
        $inGit = $this->plant('.git/.rector-warm-' . $pid);
        $inSrc = $this->plant('src/.rector-warm-' . $pid);

        TempCopySweeper::sweepTree($this->root);

        self::assertDirectoryExists($inVendor);
        self::assertDirectoryExists($inNodeModules);
        self::assertDirectoryExists($inGit);
        self::assertDirectoryDoesNotExist($inSrc);
    }

    public function testSweepingOneDirectoryRemovesOnlyItsOwnDeadSiblings(): void
    {
        // The per-run sweep: before a buffer run writes its temp copy in a
        // directory, dead siblings there are cleaned up too, so a leftover
        // in vendor/ or deeper than the startup walk still goes away the
        // next time a file next to it is edited.
        $pid = self::deadPid();
        $here = $this->plant('vendor/pkg/.rector-warm-' . $pid);
        $elsewhere = $this->plant('src/.rector-warm-' . $pid);
        $live = $this->plant('vendor/pkg/.rector-warm-' . getmypid());

        TempCopySweeper::sweepDirectory($this->root . '/vendor/pkg');

        self::assertDirectoryDoesNotExist($here);
        self::assertDirectoryExists($elsewhere);
        self::assertDirectoryExists($live);
    }

    /**
     * #142: sweepDirectory() reaches removeIfStale() via
     * glob(..., GLOB_ONLYDIR), which follows a symlink -- unlike
     * sweepTree()'s own direct-child loop, which already skips one. A
     * symlink named like a stale candidate must not have its target's
     * contents deleted, wherever that target is.
     */
    public function testSweepDirectoryDoesNotFollowASymlinkedCandidate(): void
    {
        $externalRoot = sys_get_temp_dir() . '/mcp-rector-sweep-external-' . bin2hex(random_bytes(4));
        mkdir($externalRoot, 0o700, true);
        $externalFile = $externalRoot . '/Outside.txt';
        file_put_contents($externalFile, "not part of the workspace\n");

        $pid = self::deadPid();
        $symlinkPath = $this->root . '/vendor/pkg/.rector-warm-' . $pid;
        mkdir(dirname($symlinkPath), 0o700, true);
        self::assertTrue(symlink($externalRoot, $symlinkPath), 'could not create the test symlink');

        // Positive control, same run: a real stale directory (no symlink
        // involved) is still removed -- proves the guard didn't just start
        // refusing every candidate.
        $realStale = $this->plant('vendor/pkg2/.rector-warm-' . $pid);

        try {
            TempCopySweeper::sweepDirectory($this->root . '/vendor/pkg');
            TempCopySweeper::sweepDirectory($this->root . '/vendor/pkg2');

            self::assertFileExists($externalFile, 'a symlinked candidate must not have its target swept');
            self::assertDirectoryDoesNotExist($realStale, 'a real stale directory (no symlink) must still be removed');
        } finally {
            // Removed as a link, never recursed into (following it a second
            // time would be the exact bug under test), and before its target
            // is removed: on Windows a link to a directory needs rmdir().
            self::removeLink($symlinkPath);
            self::removeTree($externalRoot);
        }
    }

    /**
     * #144: an NTFS junction (`mklink /J`) is a distinct Windows
     * reparse-point type from the symlink #142's guards above were proven
     * against -- `mklink /J` needs no elevated privilege, unlike `mklink
     * /D`. PHP's is_link() is documented reliable for POSIX and Windows
     * symlinks; its behaviour on a junction is the open question this
     * guards. Windows-only: there is no junction concept to create
     * elsewhere.
     */
    public function testSweepDirectoryDoesNotFollowAJunctionedCandidateOnWindows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('NTFS junctions only exist on Windows.');
        }

        $externalRoot = sys_get_temp_dir() . '/mcp-rector-sweep-external-' . bin2hex(random_bytes(4));
        mkdir($externalRoot, 0o700, true);
        $externalFile = $externalRoot . '/Outside.txt';
        file_put_contents($externalFile, "not part of the workspace\n");

        $pid = self::deadPid();
        $junctionPath = $this->root . '/vendor/pkg/.rector-warm-' . $pid;
        mkdir(dirname($junctionPath), 0o700, true);
        self::createJunction($externalRoot, $junctionPath);

        // Positive control, same run: a real stale directory (no junction
        // involved) is still removed -- proves the guard didn't just start
        // refusing every candidate.
        $realStale = $this->plant('vendor/pkg2/.rector-warm-' . $pid);

        try {
            TempCopySweeper::sweepDirectory($this->root . '/vendor/pkg');
            TempCopySweeper::sweepDirectory($this->root . '/vendor/pkg2');

            self::assertFileExists($externalFile, 'a junctioned candidate must not have its target swept');
            self::assertDirectoryDoesNotExist($realStale, 'a real stale directory (no junction) must still be removed');
        } finally {
            // Removed as a link, never recursed into (following it a second
            // time would be the exact bug under test), and before its target
            // is removed: rmdir() removes a junction without touching its
            // target, same as it does for a directory symlink on Windows.
            self::removeLink($junctionPath);
            self::removeTree($externalRoot);
        }
    }

    /**
     * #162: round-3 release-delta audit finding. escapeshellarg() on
     * Windows replaces the characters %, ! and " with spaces (documented
     * php-src behaviour) -- isLinkOrJunction()'s old `fsutil reparsepoint
     * query` call built its command as a shell STRING via escapeshellarg(),
     * so a path containing any of those characters got fsutil asked about a
     * mangled path that does not exist, which exits non-zero and reads as
     * "not a junction". A junction planted at such a path would bypass both
     * sweepTree()'s and removeIfStale()'s guards, reopening #142/#144 for
     * that narrower path shape. Windows-only: there is no junction concept
     * to create elsewhere, and escapeshellarg()'s character-mangling here is
     * Windows-specific too.
     */
    public function testIsLinkOrJunctionDetectsAJunctionUnderAPathContainingAnExclamationMark(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('NTFS junctions, and escapeshellarg()\'s %/!/" mangling, are Windows-specific.');
        }

        $externalRoot = sys_get_temp_dir() . '/mcp-rector-sweep-external-' . bin2hex(random_bytes(4));
        mkdir($externalRoot, 0o700, true);
        $externalFile = $externalRoot . '/Outside.txt';
        file_put_contents($externalFile, "not part of the workspace\\n");

        // The `!` is the point of this test: escapeshellarg() mangles it.
        $parentWithBang = sys_get_temp_dir() . '/mcp-rector-sweep-bang-!-' . bin2hex(random_bytes(4));
        mkdir($parentWithBang, 0o700, true);
        $junctionPath = $parentWithBang . '/.rector-warm-' . self::deadPid();

        try {
            self::createJunction($externalRoot, $junctionPath);

            self::assertTrue(
                TempCopySweeper::isLinkOrJunction($junctionPath),
                'a junction under a path containing "!" must still be detected as a junction',
            );
        } finally {
            self::removeLink($junctionPath);
            self::removeTree($externalRoot);
            self::removeTree($parentWithBang);
        }
    }

    /**
     * `mklink /J` needs no elevated privilege, unlike `mklink /D`. PHP's
     * symlink() cannot create a junction, link() creates a hardlink (a
     * different reparse type again), so this shells out to the `mklink`
     * builtin -- which only exists inside cmd.exe, it is not a standalone
     * executable.
     *
     * That rules out the ARRAY form of proc_open(): `cmd /c` needs the
     * builtin's whole invocation as ONE command-line string, but the array
     * form quotes each element ('cmd', '/c', 'mklink', '/J', $link, $target)
     * as its own separate token, so cmd.exe receives '"mklink"' as a quoted
     * token and tries to run it as a standalone program -- "'\"mklink\"' is
     * not recognized as an internal or external command" (observed on CI).
     *
     * It also rules out escapeshellarg(): on Windows it replaces %, ! and "
     * with spaces (the exact #162 mangling bug), which would corrupt the
     * `!`-containing path the sibling test below exists to exercise.
     *
     * So this passes a plain STRING to proc_open() -- like the pre-#162
     * exec()-based version, PHP routes a string command through cmd.exe
     * automatically on Windows -- with paths wrapped in literal double
     * quotes (no other escaping) rather than escapeshellarg(). Temp
     * directory names here never contain a literal `"`, so this is safe
     * without needing cmd's own quoting rules.
     */
    private static function createJunction(string $target, string $link): void
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $command = 'mklink /J "' . $link . '" "' . $target . '"';
        $process = proc_open($command, $descriptors, $pipes);
        self::assertIsResource($process, 'could not start mklink');

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, 'could not create the test junction: ' . $output);
    }
}
