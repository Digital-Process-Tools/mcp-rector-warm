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
            is_dir($path) ? self::removeTree($path) : unlink($path);
        }
        rmdir($dir);
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
            // Removed as a link, never recursed into: the teardown helper
            // below is not symlink-safe, and following this link a second
            // time would be the exact bug under test.
            @unlink($symlinkPath);
            self::removeTree($externalRoot);
        }
    }
}
