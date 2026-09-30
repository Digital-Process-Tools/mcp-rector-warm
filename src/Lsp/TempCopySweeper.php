<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

/**
 * #179: removes `.rector-warm-<pid>` directories left behind by a server
 * that died mid-run (kill -9 skips RectorDiagnosticsSource's `finally`).
 * Ownership is decided by an exclusive `flock()` on a `.lock` file inside
 * the directory, held by RectorDiagnosticsSource for the whole life of the
 * write, rather than by pid liveness: a lock a live process holds cannot be
 * taken non-blocking by anyone else, and the OS releases it unconditionally
 * when that process dies on every platform PHP supports (`LockFileEx` does
 * this on Windows too), so there is no `posix_kill`/`tasklist`/`/proc`
 * branch left to answer "dead" wrongly, and no pid-reuse window either.
 *
 * A directory with no `.lock` yet (killed between `mkdir` and lock
 * creation) is judged by mtime instead, within a fixed grace period, so a
 * server that is still in that narrow window between the two calls is not
 * swept out from under itself.
 *
 * There is no startup tree walk (#179 drops `sweepTree()`): only the
 * per-directory sweep next to the file being diagnosed, which is already
 * where a leftover is visible to the user, and needs no depth or directory
 * caps because it only ever looks at one directory's own direct children.
 *
 * Release-audit finding: a candidate's NAME is checked too, not only its
 * lock/mtime -- only `.rector-warm-<digits>` (the exact shape this class
 * ever creates) is ever considered; a directory merely sharing the prefix
 * (a real project directory named e.g. `.rector-warm-cache`) is left alone
 * entirely, the same shape check the old pid-based design's own pidOf()
 * already had. And a candidate's CONTENTS are checked against exactly what
 * is recorded as belonging there before anything is deleted -- see
 * removeKnownContents().
 */
final class TempCopySweeper
{
    /** The lock file RectorDiagnosticsSource holds for the life of a run. */
    public const LOCK_FILE_NAME = '.lock';

    /**
     * A directory with no `.lock` file is stale only once it is older than
     * this: the window between `mkdir()` and the `.lock` file being created
     * is real but short, and a directory swept out mid-window would delete
     * a buffer another server is still writing.
     */
    private const GRACE_PERIOD_SECONDS = 600;

    /**
     * The per-run sweep: only $directory's own `.rector-warm-<pid>`
     * children. Called before a buffer run writes its own temp copy, so a
     * leftover next to the file being diagnosed is cleaned up before the
     * new copy is created.
     */
    public static function sweepDirectory(string $directory): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . RectorDiagnosticsSource::TEMP_DIRECTORY_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $path) {
            self::removeIfStale($path);
        }
    }

    private static function removeIfStale(string $path): bool
    {
        // #142/#144: glob(..., GLOB_ONLYDIR) above follows a symlink or an
        // NTFS junction -- refusing here, before anything else is checked,
        // keeps a candidate that is either from ever being deleted through,
        // wherever it points. isLinkOrJunction() widens is_link() to also
        // catch a junction, which is_link() does not reliably detect
        // (#144). #145: this is a check-then-act race like the ones this
        // whole class already carried under the pid design -- unchanged by
        // this redesign, and tracked there for re-evaluation.
        if (self::isLinkOrJunction($path)) {
            return false;
        }

        // Release-audit finding: glob(..., '.rector-warm-*') above matches
        // any name with the prefix, not only one this class ever created --
        // a real user directory named e.g. `.rector-warm-cache` matches the
        // glob too. The old pid-based design's own pidOf() only ever acted
        // on a name whose suffix was entirely digits; that same shape check
        // is restored here, independent of pid liveness, as the one thing
        // that tells "ours" apart from "merely named like ours".
        if (!self::isCandidateName(basename($path))) {
            return false;
        }

        $lockPath = $path . DIRECTORY_SEPARATOR . self::LOCK_FILE_NAME;

        if (is_file($lockPath)) {
            if (!self::lockIsFree($lockPath)) {
                // Someone else holds it: a live server working in the same
                // directory, including this very call's own owner, since a
                // process always holds its own lock non-blockingly-
                // unacquirable to anyone but itself.
                return false;
            }
        } elseif (self::isWithinGracePeriod($path)) {
            // No `.lock` yet: either a server that has not reached the
            // point of creating one, or one that never will (killed before
            // it could). Within the grace period, treated as the former.
            return false;
        }

        return self::removeKnownContents($path, $lockPath);
    }

    /** `.rector-warm-<pid>` exactly -- a digit suffix, nothing else. */
    private static function isCandidateName(string $name): bool
    {
        $prefix = RectorDiagnosticsSource::TEMP_DIRECTORY_PREFIX;
        if (!str_starts_with($name, $prefix)) {
            return false;
        }

        $suffix = substr($name, strlen($prefix));

        return $suffix !== '' && ctype_digit($suffix);
    }

    /**
     * true = another process holds the lock, false = free to take (and
     * released again immediately -- this call only judges staleness, it
     * does not claim ownership).
     */
    private static function lockIsFree(string $lockPath): bool
    {
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            // Cannot even open it -- fail closed, same direction every
            // other "cannot tell" branch in this class takes.
            return false;
        }

        try {
            $acquired = flock($handle, LOCK_EX | LOCK_NB);
            if ($acquired) {
                flock($handle, LOCK_UN);
            }

            return $acquired;
        } finally {
            fclose($handle);
        }
    }

    private static function isWithinGracePeriod(string $path): bool
    {
        $mtime = @filemtime($path);
        if ($mtime === false) {
            // Cannot tell how old it is -- kept, same fail-closed direction
            // as lockIsFree() above and the isAlive() branches this design
            // replaces used to take for an unanswerable case.
            return true;
        }

        return (time() - $mtime) < self::GRACE_PERIOD_SECONDS;
    }

    /**
     * The directory's full listing is checked FIRST, against exactly what
     * it is expected to hold, before anything is deleted:
     *
     * - A `.lock` file present: RectorDiagnosticsSource writes the buffer
     *   copy's own basename into it at creation time (see there), so the
     *   one other name this directory is allowed to hold is read from
     *   `.lock` itself rather than assumed. Anything else present at all --
     *   an extra file, a subdirectory, or the recorded copy missing while
     *   something else sits there instead -- and the whole directory is
     *   left untouched, never partially emptied.
     * - No `.lock` file: production only ever creates one immediately
     *   after `mkdir()`, before writing anything else into the directory,
     *   so the only shape reaching here with no `.lock` at all is one
     *   killed in that narrow window -- genuinely empty. Anything present
     *   with no `.lock` is not a shape production ever leaves and is
     *   refused rather than guessed at.
     */
    private static function removeKnownContents(string $path, string $lockPath): bool
    {
        $entries = @scandir($path);
        if ($entries === false) {
            return false;
        }
        $names = array_values(array_diff($entries, ['.', '..']));

        if (!is_file($lockPath)) {
            return $names === [] && @rmdir($path);
        }

        $recordedBasename = @file_get_contents($lockPath);
        if ($recordedBasename === false || $recordedBasename === '') {
            return false;
        }

        $expected = [self::LOCK_FILE_NAME, $recordedBasename];
        sort($names);
        sort($expected);
        if ($names !== $expected) {
            return false;
        }

        @unlink($path . DIRECTORY_SEPARATOR . $recordedBasename);
        @unlink($lockPath);

        return @rmdir($path);
    }

    /**
     * #188: reclaims a server's OWN `.rector-warm-<pid>` directory -- the
     * caller already knows $path matches its own deterministic name,
     * nothing else was ever going to create one under this process's pid
     * -- when a lock-acquisition race (or any other imperfect cleanup) has
     * left it holding nothing but an empty, unheld `.lock`. Unlike
     * removeIfStale()/removeKnownContents() above, this never judges
     * mtime or a recorded basename: an entirely bare `.lock`, with no
     * companion buffer file, is a shape production only ever leaves via
     * that race (the basename is written right after the lock is
     * acquired, never before), so the one check that matters is whether
     * this process itself can take the lock right now.
     *
     * Refuses (returns false, changing nothing) for: a symlinked or
     * junctioned $path; anything other than exactly one `.lock` file
     * inside it; and a `.lock` another process still holds -- the
     * must-not-fire case a live server's own in-progress run must never
     * be swept out from under it.
     */
    public static function reclaimStaleOwnLock(string $path): bool
    {
        if (self::isLinkOrJunction($path)) {
            return false;
        }

        $entries = @scandir($path);
        if ($entries === false) {
            return false;
        }
        if (array_values(array_diff($entries, ['.', '..'])) !== [self::LOCK_FILE_NAME]) {
            return false;
        }

        $lockPath = $path . DIRECTORY_SEPARATOR . self::LOCK_FILE_NAME;
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        // Re-checked immediately before deleting anything, the same
        // defensive shape removeIfStale()/the LSP's own `finally` block
        // use elsewhere in this feature: the lock was free a moment ago,
        // but nothing prevents a symlink swap between that check and this
        // unlink() if $path is ever reachable by another writer.
        if (self::isLinkOrJunction($path)) {
            return false;
        }

        @unlink($lockPath);

        return @rmdir($path);
    }

    /**
     * #144: is_link() is documented reliable for a POSIX symlink and a
     * Windows symlink, but NOT for an NTFS junction (`mklink /J`, which --
     * unlike `mklink /D` -- needs no elevated privilege) -- a distinct
     * Windows reparse-point type, confirmed on this repo's own CI to slip
     * past is_link() undetected (#144). `fsutil reparsepoint query` exits 0
     * for ANY reparse point (a junction or a symlink) and non-zero for an
     * ordinary directory or a path that does not exist -- exactly the
     * is_link() semantics this needs, widened to the type is_link() misses.
     * A no-op everywhere but Windows, where is_link() alone is already
     * proven reliable (#142).
     */
    public static function isLinkOrJunction(string $path): bool
    {
        if (is_link($path)) {
            return true;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        return self::isReparsePoint($path);
    }

    /**
     * #162: this used to build `fsutil reparsepoint query <path>` as a
     * shell STRING via escapeshellarg(). On Windows, escapeshellarg()
     * replaces the characters %, ! and " with spaces (documented php-src
     * behaviour) -- for a path containing any of them, fsutil was being
     * asked about a mangled path that does not exist, exited non-zero, and
     * was read as "not a junction", reopening #142/#144 for that narrower
     * path shape. proc_open() with the command given as an ARRAY (not a
     * string) bypasses shell-string quoting entirely -- PHP builds the
     * Windows command line itself, from the argv values as given, with no
     * shell involved to mis-escape them.
     */
    private static function isReparsePoint(string $path): bool
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(['fsutil', 'reparsepoint', 'query', $path], $descriptors, $pipes);

        // #161/#162 self-review: the exec()-based code this replaced left
        // $exitCode at its pre-set 0 (== "is a junction") whenever exec()
        // itself failed to spawn -- an accidental but real fail-CLOSED
        // direction: unqueryable was treated as "yes, refuse it". Returning
        // false here (unqueryable == "not a junction") would flip that,
        // letting removeIfStale() proceed to unlink()/rmdir() through a
        // path it was never actually able to rule out as a junction -- the
        // same #142/#144 class this guard exists to close, reopened
        // whenever `proc_open` itself cannot start (disabled, sandboxed,
        // out of resources), independent of the path it was asked about.
        if (!is_resource($process)) {
            return true;
        }

        // Drained so the child cannot block on a full pipe before
        // proc_close() waits for it; the output itself is not needed.
        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }

        return proc_close($process) === 0;
    }
}
