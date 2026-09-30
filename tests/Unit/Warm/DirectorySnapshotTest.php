<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Warm;

use Dpt\McpRectorWarm\Warm\DirectorySnapshot;
use Dpt\McpRectorWarm\Warm\Path;
use PHPUnit\Framework\TestCase;

/**
 * #185: the session child must notice a PHP file that appears, disappears or
 * moves anywhere a class lookup could have probed -- a lookup that found nothing
 * is memoised by PHPStan (and by most autoloaders), so a file created after it
 * would otherwise never be seen. Every must-fire case here is paired with a
 * must-not-fire one, and testUnchangedTreeReportsNoChange is the positive
 * control that the snapshot is actually looking at the tree.
 */
final class DirectorySnapshotTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/dir-snapshot-test-' . \bin2hex(\random_bytes(8));
        \mkdir($this->root . '/src/Deep', 0o777, true);
        // The snapshot reports real paths (/var is /private/var on macOS) with
        // '/' separators, also on Windows: build expectations the same way.
        $this->root = (string) Path::real($this->root);
        \file_put_contents($this->root . '/src/A.php', '<?php class A {}');
        \file_put_contents($this->root . '/src/Deep/B.php', '<?php class B {}');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);

            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        @\rmdir($path);
    }

    /** @param list<string> $excluded */
    private function snapshot(array $excluded = [], array $fullRoots = []): DirectorySnapshot
    {
        $snapshot = new DirectorySnapshot([$this->root], $fullRoots, $excluded, ['php']);
        $snapshot->refresh();

        return $snapshot;
    }

    /** Directory mtimes have one-second granularity on some filesystems. */
    private function tick(): void
    {
        \clearstatcache();
        \usleep(1_100_000);
    }

    public function testUnchangedTreeReportsNoChange(): void
    {
        $snapshot = $this->snapshot();

        self::assertNull($snapshot->firstChange());
        self::assertSame(3, $snapshot->count(), 'root, src and src/Deep are watched');
    }

    public function testANewPhpFileInAWatchedDirectoryIsAChange(): void
    {
        $snapshot = $this->snapshot();
        $this->tick();
        \file_put_contents($this->root . '/src/Deep/C.php', '<?php class C {}');

        self::assertSame($this->root . '/src/Deep', $snapshot->firstChange());
    }

    public function testAChangeInTheSameSecondAsTheSnapshotIsStillSeen(): void
    {
        // A directory mtime has one-second resolution through stat(): a file
        // created in the same second the snapshot was taken leaves the mtime
        // unchanged. Such a "racily clean" directory must be re-listed.
        $snapshot = $this->snapshot();
        \file_put_contents($this->root . '/src/Deep/Same.php', '<?php class Same {}');

        self::assertSame($this->root . '/src/Deep', $snapshot->firstChange());
    }

    public function testADeletedPhpFileIsAChange(): void
    {
        $snapshot = $this->snapshot();
        $this->tick();
        \unlink($this->root . '/src/A.php');

        self::assertSame($this->root . '/src', $snapshot->firstChange());
    }

    public function testARenamedPhpFileIsAChange(): void
    {
        $snapshot = $this->snapshot();
        $this->tick();
        \rename($this->root . '/src/A.php', $this->root . '/src/Renamed.php');

        self::assertSame($this->root . '/src', $snapshot->firstChange());
    }

    public function testANewSubdirectoryIsAChange(): void
    {
        $snapshot = $this->snapshot();
        $this->tick();
        \mkdir($this->root . '/src/New');

        self::assertSame($this->root . '/src', $snapshot->firstChange());
    }

    public function testAnInPlaceEditIsNotAChange(): void
    {
        // Content is DependencyFileTracker's job; this only watches names.
        $snapshot = $this->snapshot();
        $this->tick();
        \file_put_contents($this->root . '/src/A.php', '<?php class A { const X = 1; }');

        self::assertNull($snapshot->firstChange());
    }

    public function testAnAtomicSaveUnderTheSameNameIsNotAChange(): void
    {
        // Editors write a temp file and rename it over the original: the
        // directory's mtime moves, its listing does not.
        $snapshot = $this->snapshot();
        $this->tick();
        \file_put_contents($this->root . '/src/A.php.tmp', '<?php class A { const Y = 2; }');
        \rename($this->root . '/src/A.php.tmp', $this->root . '/src/A.php');

        self::assertNull($snapshot->firstChange());
        // ...and the moved mtime is remembered, so the next check stays quiet.
        self::assertNull($snapshot->firstChange());
    }

    public function testHiddenAndNonPhpEntriesAreNotAChangeUnderAGeneralRoot(): void
    {
        $snapshot = $this->snapshot();
        $this->tick();
        \file_put_contents($this->root . '/src/.A.php.swp', 'swap');
        \file_put_contents($this->root . '/src/README.md', 'docs');
        \mkdir($this->root . '/src/.rector-warm-1');

        self::assertNull($snapshot->firstChange());
    }

    public function testHiddenEntriesAreAChangeUnderAFullRoot(): void
    {
        // A scanned root (withAutoloadPaths, scanDirectories) is walked the way
        // PHPStan's own directory scan walks it, hidden entries included.
        $snapshot = $this->snapshot([], [$this->root . '/src']);
        $this->tick();
        \mkdir($this->root . '/src/.hidden');
        \file_put_contents($this->root . '/src/.hidden/D.php', '<?php class D {}');

        self::assertSame($this->root . '/src', $snapshot->firstChange());
    }

    public function testAnyNewFileIsAChangeUnderAWatchRoot(): void
    {
        // MCP_RECTOR_WARM_SESSION_WATCH: a custom rule may read JSON, templates,
        // anything -- every name counts there, whatever its extension.
        \mkdir($this->root . '/config');
        $snapshot = new DirectorySnapshot([$this->root], [], [], ['php'], 100_000, [$this->root . '/config']);
        $snapshot->refresh();
        $this->tick();
        \file_put_contents($this->root . '/src/notes.md', 'not a PHP source');
        self::assertNull($snapshot->firstChange(), 'outside the watch root a .md file does not count');

        \file_put_contents($this->root . '/config/events.json', '{}');

        self::assertSame($this->root . '/config', $snapshot->firstChange());
    }

    public function testAnExcludedDirectoryIsNotWatched(): void
    {
        \mkdir($this->root . '/cache');
        $snapshot = $this->snapshot([$this->root . '/cache']);
        $this->tick();
        \file_put_contents($this->root . '/cache/entry.php', '<?php return [];');

        self::assertNull($snapshot->firstChange());
        self::assertSame(3, $snapshot->count());
    }

    public function testRefreshStartsWatchingADirectoryCreatedSince(): void
    {
        $snapshot = $this->snapshot();
        $this->tick();
        \mkdir($this->root . '/src/New');
        self::assertNotNull($snapshot->firstChange());

        $snapshot->refresh();
        self::assertNull($snapshot->firstChange(), 'a refreshed snapshot starts clean');
        $this->tick();
        \file_put_contents($this->root . '/src/New/E.php', '<?php class E {}');

        self::assertSame($this->root . '/src/New', $snapshot->firstChange());
    }

    public function testAVanishedDirectoryIsAChange(): void
    {
        $snapshot = $this->snapshot();
        $this->remove($this->root . '/src/Deep');

        self::assertNotNull($snapshot->firstChange());
    }

    public function testMoreDirectoriesThanTheCapRefusesToSnapshot(): void
    {
        $snapshot = new DirectorySnapshot([$this->root], [], [], ['php'], 2);

        $this->expectException(\RuntimeException::class);
        $snapshot->refresh();
    }
}
