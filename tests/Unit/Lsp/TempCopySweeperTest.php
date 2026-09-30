<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\TempCopySweeper;
use PHPUnit\Framework\TestCase;

/**
 * #179: a server killed mid-run (kill -9) cannot run its own `finally`, so
 * its `.rector-warm-<pid>` directory stays. Ownership is now decided by an
 * exclusive `flock()` on a `.lock` file inside the directory: a directory
 * whose lock a live process still holds is kept; one whose lock can be
 * taken non-blocking (the owner is gone, and released it -- by the OS, on
 * every platform, including a kill -9) is removed. A directory with no
 * `.lock` yet is judged by mtime instead, inside a fixed grace period.
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

    private function plant(string $relativeDir, string $file = 'Clean.php'): string
    {
        $dir = $this->root . '/' . $relativeDir;
        mkdir($dir, 0o700, true);
        file_put_contents($dir . '/' . $file, "<?php\n");

        return $dir;
    }

    /**
     * Plants a `.rector-warm-<pid>`-shaped directory with a `.lock` file
     * inside, held or free as asked. A held lock never releases on its
     * own: the caller gets back the handle and must fclose() it (which
     * also releases the lock) once the test is done asserting against it.
     *
     * @return resource|null the open handle if $held, else null
     */
    private function plantLocked(string $relativeDir, bool $held): mixed
    {
        $dir = $this->plant($relativeDir);
        $lockPath = $dir . '/' . TempCopySweeper::LOCK_FILE_NAME;
        $handle = fopen($lockPath, 'c');
        self::assertIsResource($handle, 'could not open the test lock file');

        if ($held) {
            self::assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'could not hold the test lock');

            return $handle;
        }

        fclose($handle);

        return null;
    }

    /** Backdates a directory's mtime past the no-`.lock` grace period. */
    private static function backdate(string $path): void
    {
        self::assertTrue(touch($path, time() - 700), 'could not backdate the test directory');
    }

    public function testADirectoryWhoseLockIsHeldByALiveProcessIsKept(): void
    {
        // Must-fire control, same run: a directory whose lock is free (the
        // owner released it, as any process does on exit, including a
        // kill -9) is removed -- proves the guard didn't just start
        // keeping every candidate.
        $free = $this->plant('src/.rector-warm-1');
        $freeHandle = fopen($free . '/' . TempCopySweeper::LOCK_FILE_NAME, 'c');
        self::assertIsResource($freeHandle);
        fclose($freeHandle);

        $held = $this->plantLocked('src/.rector-warm-2', held: true);

        try {
            TempCopySweeper::sweepDirectory($this->root . '/src');

            self::assertDirectoryDoesNotExist($free);
            self::assertDirectoryExists($this->root . '/src/.rector-warm-2', 'a directory whose lock is held by a live process must be kept');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
    }

    /**
     * Pairs with the test above: after the "live" process is killed (its
     * handle closed, which releases the flock the same way the OS releases
     * it on kill -9), the next sweep removes what it left behind.
     */
    public function testADirectoryIsRemovedOnceTheProcessHoldingItsLockIsGone(): void
    {
        $handle = $this->plantLocked('src/.rector-warm-3', held: true);
        $dir = $this->root . '/src/.rector-warm-3';

        self::assertDirectoryExists($dir, 'control: still kept while the lock is held');

        // Simulates the process dying: the OS releases every lock it held,
        // including on a kill -9, without this test calling flock(LOCK_UN)
        // itself.
        fclose($handle);

        TempCopySweeper::sweepDirectory($this->root . '/src');

        self::assertDirectoryDoesNotExist($dir);
    }

    public function testADirectoryWithNoLockYetIsKeptWithinTheGracePeriodAndRemovedAfterIt(): void
    {
        $freshNoLock = $this->plant('src/.rector-warm-4');
        $staleNoLock = $this->plant('src/.rector-warm-5');
        self::backdate($staleNoLock);

        TempCopySweeper::sweepDirectory($this->root . '/src');

        self::assertDirectoryExists($freshNoLock, 'a directory with no .lock yet, inside the grace period, must be kept');
        self::assertDirectoryDoesNotExist($staleNoLock, 'a directory with no .lock, past the grace period, must be removed');
    }

    /**
     * The per-run sweep only ever looks at $directory's own direct
     * children, never anything deeper or beside it -- there is no startup
     * tree walk any more (#179 drops sweepTree() entirely).
     */
    public function testSweepingOneDirectoryRemovesOnlyItsOwnStaleSiblings(): void
    {
        $here = $this->plant('vendor/pkg/.rector-warm-6');
        self::backdate($here);
        $elsewhere = $this->plant('src/.rector-warm-7');
        self::backdate($elsewhere);
        $held = $this->plantLocked('vendor/pkg/.rector-warm-8', held: true);

        try {
            TempCopySweeper::sweepDirectory($this->root . '/vendor/pkg');

            self::assertDirectoryDoesNotExist($here);
            self::assertDirectoryExists($elsewhere, 'a stale directory outside the swept directory must be left for its own sweep');
            self::assertDirectoryExists($this->root . '/vendor/pkg/.rector-warm-8');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
    }

    /**
     * #142: sweepDirectory() reaches removeIfStale() via
     * glob(..., GLOB_ONLYDIR), which follows a symlink. A symlink named
     * like a stale candidate must not have its target's contents deleted,
     * wherever that target is.
     */
    public function testSweepDirectoryDoesNotFollowASymlinkedCandidate(): void
    {
        $externalRoot = sys_get_temp_dir() . '/mcp-rector-sweep-external-' . bin2hex(random_bytes(4));
        mkdir($externalRoot, 0o700, true);
        $externalFile = $externalRoot . '/Outside.txt';
        file_put_contents($externalFile, "not part of the workspace\n");

        $symlinkPath = $this->root . '/vendor/pkg/.rector-warm-9';
        mkdir(dirname($symlinkPath), 0o700, true);
        self::assertTrue(symlink($externalRoot, $symlinkPath), 'could not create the test symlink');

        // Positive control, same run: a real stale directory (no symlink
        // involved) is still removed -- proves the guard didn't just start
        // refusing every candidate.
        $realStale = $this->plant('vendor/pkg2/.rector-warm-9');
        self::backdate($realStale);

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

        $junctionPath = $this->root . '/vendor/pkg/.rector-warm-10';
        mkdir(dirname($junctionPath), 0o700, true);
        self::createJunction($externalRoot, $junctionPath);

        // Positive control, same run: a real stale directory (no junction
        // involved) is still removed -- proves the guard didn't just start
        // refusing every candidate.
        $realStale = $this->plant('vendor/pkg2/.rector-warm-10');
        self::backdate($realStale);

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
     * "not a junction". A junction planted at such a path would bypass
     * removeIfStale()'s guard, reopening #142/#144 for that narrower path
     * shape. Windows-only: there is no junction concept to create
     * elsewhere, and escapeshellarg()'s character-mangling here is
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
        file_put_contents($externalFile, "not part of the workspace\n");

        // The `!` is the point of this test: escapeshellarg() mangles it.
        $parentWithBang = sys_get_temp_dir() . '/mcp-rector-sweep-bang-!-' . bin2hex(random_bytes(4));
        mkdir($parentWithBang, 0o700, true);
        $junctionPath = $parentWithBang . '/.rector-warm-11';

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
