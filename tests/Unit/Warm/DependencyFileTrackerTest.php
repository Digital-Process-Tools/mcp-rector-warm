<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Warm;

use Dpt\McpRectorWarm\Warm\DependencyFileTracker;
use PHPUnit\Framework\TestCase;

final class DependencyFileTrackerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/dep-tracker-test-' . \bin2hex(\random_bytes(8));
        \mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        \file_put_contents($path, $contents);
        return $path;
    }

    /**
     * The positive control for every "must not fire" case below: an
     * unmodified tracked file must report no staleness at all, so a
     * checkStale() that always returns null (a broken tracker, not a
     * genuinely clean one) cannot pass silently alongside them.
     */
    public function testUnmodifiedFileIsNeverStale(): void
    {
        $tracker = new DependencyFileTracker();
        $path = $this->write('a.php', '<?php // v1');

        $tracker->record($path, \sha1_file($path));

        self::assertNull($tracker->checkStale());
        self::assertSame(1, $tracker->count());
    }

    /**
     * The must-fire twin of the test above: touching the SAME file's
     * content is exactly the case #185's own must-not-fire test on the
     * issue names ("a file change is never served from a stale session"),
     * and must be caught even though only the content changed, not the
     * length.
     */
    public function testContentChangeSameLengthIsDetectedAsStale(): void
    {
        $tracker = new DependencyFileTracker();
        $path = $this->write('a.php', '<?php // v1');

        // Force the RECORDED mtime a full ten seconds into the past before
        // record() ever sees it (review finding: the previous version of
        // this test forced the mtime back only at the very end, right
        // before checkStale() -- since that final touch() and record()'s
        // own initial timestamp both land within the same fast test's
        // execution, they can end up in the SAME wall-clock second, making
        // the assertion pass via the racy same-second content-hash fallback
        // instead of the stat-signature comparison the test's own name and
        // intent are about; recording the OLD mtime up front instead, then
        // letting the real edit below set a genuinely later mtime, removes
        // that timing dependency entirely).
        \touch($path, \time() - 10);
        \clearstatcache();
        $tracker->record($path, \sha1_file($path));

        \file_put_contents($path, '<?php // v2'); // same length as v1 -- only mtime, not size, differs now
        \clearstatcache();

        self::assertSame($path, $tracker->checkStale());
    }

    /**
     * The mtime-granularity blind spot RectorRunner::configFileChanged()'s
     * own docblock names: an edit inside the same wall-clock second as the
     * one recorded must still be caught by the content-hash fallback, not
     * missed just because the stat signature (mtime has one-second
     * resolution) did not visibly change.
     */
    public function testSameSecondEditIsStillDetectedAsStale(): void
    {
        $tracker = new DependencyFileTracker();
        $path = $this->write('a.php', '<?php // v1');
        $now = \time();
        \touch($path, $now);
        \clearstatcache();
        $tracker->record($path, \sha1_file($path));

        // Same second, same mtime, and -- unlike
        // testContentChangeSameLengthIsDetectedAsStale, which changes mtime
        // and is genuinely pinning the stat-signature branch -- the SAME
        // size too ('v1' -> 'v2', both one character): the stat signature
        // (size, mtime, inode) must come out byte-for-byte identical to
        // what was recorded, so this can only be caught by checkStale()'s
        // separate same-second content-hash fallback, never by the
        // stat-signature comparison above it (review finding: the previous
        // version of this test used a longer replacement string, so a
        // differing SIZE was silently what caught it instead).
        \file_put_contents($path, '<?php // v2');
        \touch($path, $now);
        \clearstatcache();

        self::assertSame($path, $tracker->checkStale());
    }

    /**
     * PR #189 finding B: a same-size edit whose mtime is then put back (touch
     * -r, cp -p, rsync -t --inplace, tar -x) leaves size, mtime and inode
     * equal, and outside the racy window no hash is taken. ctime is the one
     * field no user tool can set back. The file is recorded with an old mtime
     * so the racy same-second path cannot be what catches it.
     */
    public function testASameSizeEditWithItsMtimePutBackIsDetectedAsStale(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            // PHP's stat() on Windows reports the file's creation time as
            // ctime, so a content edit does not move it. The session never
            // runs there (no pcntl), so nothing ships relying on this check.
            self::markTestSkipped('ctime is the creation time on Windows; the session does not run there');
        }

        $tracker = new DependencyFileTracker();
        $path = $this->write('base.php', '<?php function f(): array {}');
        $old = \time() - 100;
        \touch($path, $old);
        \clearstatcache();
        $tracker->record($path, null);
        // Positive control: untouched, it is not stale.
        self::assertNull($tracker->checkStale());

        \usleep(1_100_000); // ctime has one-second resolution through stat()
        \file_put_contents($path, '<?php function f(): float {}');
        \touch($path, $old);
        \clearstatcache();

        self::assertSame($path, $tracker->checkStale());
    }

    /**
     * A file this tracker was never told about changing is not this
     * tracker's problem to report -- checkStale() must stay null for a
     * change to an UNTRACKED file, the must-not-fire half of the pair
     * above.
     */
    public function testChangeToAnUntrackedFileIsNotReported(): void
    {
        $tracker = new DependencyFileTracker();
        $tracked = $this->write('tracked.php', '<?php // tracked');
        $untracked = $this->write('untracked.php', '<?php // untracked');
        $tracker->record($tracked, \sha1_file($tracked));

        \file_put_contents($untracked, '<?php // changed');

        self::assertNull($tracker->checkStale());
    }

    /**
     * record()'s own narrow TOCTOU guard: when the caller's $readSha (hashed
     * at the moment it read the file) no longer matches the file's current
     * bytes, the very first checkStale() must already report it -- the file
     * changed between the read and the record() call itself, and this must
     * not be missed just because the "recorded" signature below was taken
     * from the ALREADY-changed bytes.
     */
    public function testReadShaMismatchAtRecordTimeIsStaleImmediately(): void
    {
        $tracker = new DependencyFileTracker();
        $path = $this->write('a.php', '<?php // v1');
        $readShaFromEarlierInTheCall = \sha1('<?php // stale-read');

        $tracker->record($path, $readShaFromEarlierInTheCall);

        self::assertSame($path, $tracker->checkStale());
    }

    /**
     * A path is recorded once; a second record() call for the same path
     * (a hot file read many times in one analysis) must not re-derive
     * anything -- re-hashing a hot file on every one of its reads would
     * defeat the whole point of tracking it at all.
     */
    public function testRecordingTheSamePathTwiceIsANoOp(): void
    {
        $tracker = new DependencyFileTracker();
        $path = $this->write('a.php', '<?php // v1');
        $tracker->record($path, \sha1_file($path));

        $tracker->record($path, \sha1('<?php // a completely different sha'));

        self::assertSame(1, $tracker->count());
        self::assertNull($tracker->checkStale());
    }

    /**
     * A deleted tracked file (stat() now fails entirely) must be reported
     * stale too -- checkStale() must not read that as "nothing to compare,
     * so nothing changed."
     */
    public function testDeletedTrackedFileIsStale(): void
    {
        $tracker = new DependencyFileTracker();
        $path = $this->write('a.php', '<?php // v1');
        $tracker->record($path, \sha1_file($path));

        \unlink($path);
        \clearstatcache();

        self::assertSame($path, $tracker->checkStale());
    }

    public function testIsTrackedReflectsRecordedPaths(): void
    {
        $tracker = new DependencyFileTracker();
        $path = $this->write('a.php', '<?php // v1');

        self::assertFalse($tracker->isTracked($path));
        $tracker->record($path, \sha1_file($path));
        self::assertTrue($tracker->isTracked($path));
    }

    /**
     * A phar:// entry path is tracked by its archive file, not the entry
     * itself: two different entries reported inside the SAME archive both
     * collapse to one tracked path, and the second record() call is the
     * no-op the archive-level tracking intends, not a duplicate entry --
     * the exact shape PHPStan's own phpstan.phar produces (hundreds of
     * phar:// entry paths, one archive). A real phar is not needed to pin
     * this: the collapsing happens on the path string itself, before
     * anything touches the filesystem.
     */
    public function testPharEntryPathsCollapseToTheArchiveFile(): void
    {
        $archivePath = $this->dir . '/fixture.phar';

        $tracker = new DependencyFileTracker();
        $tracker->record('phar://' . $archivePath . '/src/One.php', null);
        $tracker->record('phar://' . $archivePath . '/src/deep/Two.php', null);

        self::assertSame(1, $tracker->count());
        self::assertTrue($tracker->isTracked($archivePath));
    }

    /**
     * The false-positive twin of the test above (review finding): an
     * ANCESTOR directory whose own name merely contains the substring
     * ".phar" (without being the archive itself) must not be mistaken for
     * the archive boundary. A naive first-occurrence-of-".phar" search
     * collapses "phar:///opt/my.phar-cache/vendor/real.phar/src/One.php" to
     * the nonexistent "/opt/my.phar" -- silently disabling staleness
     * detection for the real archive from then on.
     */
    public function testAnAncestorDirectoryNamedLikeAPharIsNotMistakenForTheArchive(): void
    {
        $archivePath = $this->dir . '/my.phar-cache/vendor/real.phar';

        $tracker = new DependencyFileTracker();
        $tracker->record('phar://' . $archivePath . '/src/One.php', null);

        self::assertTrue($tracker->isTracked($archivePath));
        self::assertFalse($tracker->isTracked($this->dir . '/my.phar'));
    }
}
