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

        return self::removeKnownContents($path);
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
     * Removes every plain-file entry (the lock file, and the copy of one
     * buffer, named after its original -- but not restricted to those two
     * names specifically, only to "is a file"), then a non-recursive
     * rmdir(). A subdirectory -- the one shape this is not expected to
     * hold -- is left in place, and the rmdir() then fails harmlessly,
     * leaving the whole directory untouched rather than partially
     * emptied.
     */
    private static function removeKnownContents(string $path): bool
    {
        $entries = @scandir($path);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $path . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($entryPath)) {
                // An unexpected subdirectory (or anything not a plain
                // file): refuse the whole directory rather than delete
                // around it.
                return false;
            }

            @unlink($entryPath);
        }

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
