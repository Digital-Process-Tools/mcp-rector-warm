<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Warm;

/**
 * Tracks the set of files a long-lived analysis session has read, so the
 * session can be told apart from stale before it is trusted to serve another
 * call (#185). RectorRunner's existing warm path forks a fresh, isolated
 * grandchild for every single call specifically so PHPStan's own class
 * reflection/method caches -- not a ResettableInterface service, and not
 * reset by anything short of a fresh process -- can never see a class edited
 * on disk between calls with its old shape (#8's own bug). Reusing that
 * grandchild's state across MULTIPLE calls (the whole point of #185 -- most
 * of a cold call's cost is PHPStan re-parsing and re-reflecting the same
 * dependency classes every single time) reopens exactly that hole unless
 * something can say, before every call, "nothing this session has already
 * read has changed since it read it." This class is that something; it does
 * not itself decide what counts as read -- callers record every path they
 * touch.
 *
 * Deliberately mirrors RectorRunner::configFileChanged()'s own discipline (a
 * content hash, not mtime+size alone -- see that method's docblock for why)
 * rather than inventing a new one: same repo, same failure mode (a
 * same-second edit, or an editor's atomic rename-back leaving mtime/size
 * unchanged with different bytes), same fix. Unlike configFileChanged() (a
 * fixed, small set of config files re-hashed in full on every single call),
 * this is meant to track a potentially large set of files touched by
 * whole-project analysis, so re-hashing every tracked file in full on every
 * call would itself become the bottleneck this exists to remove. The stat
 * signature (size, mtime, inode) is cheap and checked first; the
 * comparatively expensive content hash is only recomputed when the stat
 * signature is unchanged but was recorded within the same wall-clock second
 * (mtime's own one-second granularity blind spot) -- the same racy-git
 * discipline git itself uses for its index.
 */
final class DependencyFileTracker
{
    /**
     * path => [stat signature (size, mtime, inode) or null, content sha1 or
     * null, recorded-at unix timestamp].
     *
     * @var array<string, array{0: array{int, int, int, int}|null, 1: string|null, 2: int}>
     */
    private array $tracked = [];

    /**
     * A path found stale at record() time already -- see the doc on record()
     * below for the narrow race this closes. checkStale() must report this
     * before it re-derives anything from $tracked, since the file that
     * caused it may already have been overwritten with $tracked's own
     * now-current (but already-stale-relative-to-the-read) signature.
     */
    private ?string $pendingStale = null;

    /**
     * Record that $path was read for analysis, with the content hash already
     * computed at read time if the caller has one to hand (readFile()/
     * parseFile()-style hooks do, having just hashed the bytes they read for
     * their own cache key) -- pass null when only the fact of having read the
     * path is known, not its content at that moment.
     *
     * A path is recorded only once: every subsequent read of an
     * already-tracked path is exactly what checkStale() exists to verify
     * before the next call, not something record() itself re-derives (a
     * hot file can be read thousands of times in one analysis -- re-hashing
     * it on every one of those reads would defeat the whole point).
     *
     * $readSha, when given, is compared against a hash taken of $path RIGHT
     * NOW (not the one that will be stored for future checks) to catch the
     * narrow TOCTOU where $path changed on disk between the caller reading it
     * and this call recording that read -- vanishingly rare, but a silent
     * miss here is exactly the failure mode (a warm answer built from bytes
     * that no longer exist) #185 exists to close, not merely narrow.
     *
     * A phar:// path (PHPStan's own phpstan.phar bundles its Parser and
     * BetterReflection classes this way) is tracked by its archive file
     * rather than by the individual entry inside it: the archive is immutable
     * for the life of one `php` process (a phar cannot be replaced in place
     * without a new Phar object, which nothing here constructs mid-process),
     * so a per-entry stat/hash gains nothing an archive-level one would not
     * already catch, for potentially hundreds of entries per call.
     */
    public function record(string $path, ?string $readSha): void
    {
        $path = $this->archivePathOrSelf($path, $alreadyTracked);
        if ($alreadyTracked) {
            return;
        }
        if (isset($this->tracked[$path])) {
            return;
        }

        $now = $this->hash($path);
        if ($readSha !== null && $now !== $readSha) {
            // ??= rather than =: pendingStale is a single scalar slot, and
            // checkStale()'s own contract is "the FIRST path found to
            // disagree" -- a second, later TOCTOU hit (a different path,
            // another record() call before checkStale() is ever asked)
            // must not silently overwrite and lose the first one (review
            // finding).
            $this->pendingStale ??= $path;
        }

        $this->tracked[$path] = [$this->sig($path), $now, \time()];
    }

    /**
     * The first tracked path found to disagree with what was recorded for it,
     * or null when every tracked path still matches -- the answer a session
     * child asks before trusting itself with the next call. A non-null
     * result means the session must be discarded and a fresh one forked from
     * the pristine worker instead of reusing this one's caches (#185's own
     * "kill the child and fork a fresh one" step).
     */
    public function checkStale(): ?string
    {
        if ($this->pendingStale !== null) {
            return $this->pendingStale;
        }

        \clearstatcache();
        foreach ($this->tracked as $path => [$signature, $hash, $recordedAt]) {
            $current = $this->sig($path);
            if ($current !== $signature) {
                return $path;
            }
            if ($current !== null && $current[1] >= $recordedAt - 1 && $this->hash($path) !== $hash) {
                return $path;
            }
        }

        return null;
    }

    public function count(): int
    {
        return \count($this->tracked);
    }

    public function isTracked(string $path): bool
    {
        $collapsed = $this->archivePathOrSelf($path, $alreadyTracked);
        return $alreadyTracked || isset($this->tracked[$collapsed]);
    }

    /**
     * @param bool $alreadyTracked set true only when $path collapsed to a
     *   phar archive path already present in $tracked -- the caller's own
     *   short-circuit for that case, since the collapsed path is otherwise
     *   indistinguishable from one this method is about to insert for the
     *   first time.
     */
    private function archivePathOrSelf(string $path, ?bool &$alreadyTracked = null): string
    {
        $alreadyTracked = false;
        if (!\str_starts_with($path, 'phar://')) {
            return $path;
        }

        // Anchored to a trailing '/' or end-of-string, and preferring the
        // RIGHTMOST such boundary (a greedy capture group's own backtracking
        // default): the previous approach (the first '.phar' substring
        // anywhere in the whole URI) collapsed to a nonexistent, truncated
        // path whenever an ANCESTOR directory merely contained the
        // substring '.phar' without being the archive itself -- e.g.
        // "phar:///opt/my.phar-cache/vendor/real.phar/src/One.php" wrongly
        // collapsed to "/opt/my.phar", silently disabling staleness
        // detection for the real archive from then on (review finding).
        // phar:// URIs use '/' throughout regardless of host OS (PHP's own
        // stream-wrapper convention, the same as file://) -- reasoned, not
        // verified on an actual Windows runner.
        if (\preg_match('#^phar://(.+\.phar)(?:/.*)?$#', $path, $matches) !== 1) {
            return $path;
        }

        $archivePath = $matches[1];
        if (isset($this->tracked[$archivePath])) {
            $alreadyTracked = true;
        }

        return $archivePath;
    }

    /**
     * Reasoned, not observed on an actual Windows runner: PHP's stat() does
     * not populate a meaningful inode on Windows (no POSIX inode exists
     * there), so the third element of this signature is a constant on that
     * platform and cannot discriminate a same-size, same-mtime content swap
     * done more than one second after the read there -- only the
     * same-second content-hash fallback in checkStale() would still catch
     * that specific case, and only within its own one-second window (review
     * finding; not designed out here -- the class is not yet wired into any
     * call path, and a Windows-specific discriminator is deferred to
     * whoever does that wiring).
     *
     * PR #189 finding B: ctime is part of the signature. A same-size edit whose
     * mtime is then put back (touch -r, cp -p, rsync -t --inplace, tar -x)
     * leaves size, mtime and inode equal, and past the racy window no hash is
     * taken; no user tool can set ctime back, so such an edit still changes the
     * signature. (On Windows ctime is the creation time, which an edit does
     * not move -- no worse there than without it; the session needs pcntl.)
     *
     * @return array{int, int, int, int}|null
     */
    private function sig(string $path): ?array
    {
        $stat = @\stat($path);
        if ($stat === false) {
            return null;
        }

        return [$stat['size'], $stat['mtime'], $stat['ino'], $stat['ctime']];
    }

    private function hash(string $path): ?string
    {
        $hash = @\sha1_file($path);

        return $hash === false ? null : $hash;
    }
}
