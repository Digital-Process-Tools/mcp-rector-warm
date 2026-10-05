<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

/**
 * #107: given a document and the client's known workspace folders, find the
 * directory a warm RectorTool should be rooted at for it -- the nearest
 * ancestor (walking up from the document) that holds a rector.php or
 * composer.json, never walking above the workspace folder that owns the
 * document. Pure and stateless: MultiRootDiagnosticsSource is the only
 * caller, and it alone decides what "known folders" and "fallback" mean
 * (workspaceFolders from the client, or the server's own boot directory
 * when none were ever sent).
 */
final class WorkspaceRootResolver
{
    private function __construct() {}

    /**
     * @param list<string> $folders known workspace folders, as absolute
     *   filesystem paths -- no particular normalization assumed beyond "no
     *   trailing separator required"
     */
    public static function resolve(string $documentPath, array $folders, string $fallback): string
    {
        $owner = self::normalize(self::ownerFolder($documentPath, $folders) ?? $fallback);
        $dir = self::normalize(self::directoryOf($documentPath));

        while (true) {
            if (self::hasRootMarker($dir) && self::isDescendant($dir, $owner)) {
                return $dir;
            }

            if ($dir === $owner) {
                // #107 self-review (oss:auditor flagged dirname()'s
                // behaviour on a bare Windows drive letter -- dirname('C:')
                // is '.', not 'C:' again, unlike POSIX '/' which IS its own
                // dirname() -- as a possible infinite-relative-walk risk; a
                // second review pass then proved, by construction, that
                // $dir can only ever equal a value this short while it is
                // simultaneously still a descendant of $owner (checked
                // below) if $owner is that same short value too -- so THIS
                // check always fires first, before dirname() could ever be
                // called on a bare drive letter. An earlier version of this
                // fix added an explicit isFilesystemRoot() guard before
                // dirname() anyway; removed after a dedicated test proved
                // it unreachable (passed identically with the guard absent)
                // -- unreachable code with a vacuous test is worse than no
                // code at all, since the test looks like coverage it is
                // not. Left here as the proof instead of as a second,
                // never-executing branch.
                return $owner;
            }

            if (!self::isDescendant($dir, $owner)) {
                // $documentPath was never under $owner to begin with (no
                // matching folder, and the fallback is unrelated) -- stop
                // rather than walk somewhere the owner boundary never
                // authorised. #296: this check (and the identical one now
                // guarding the hasRootMarker() branch above) is what
                // actually refuses the call -- RectorTool's own cwd-based
                // containment check cannot be trusted to catch a wrong
                // value from here, because RootedDiagnosticsSourcePool
                // chdir()s into whatever resolve() returns before that
                // check ever runs, which makes it compare against the very
                // path it was meant to be checking.
                return $owner;
            }

            $parent = \dirname($dir);
            if ($parent === $dir) {
                // Filesystem root reached with no marker found anywhere
                // under $owner.
                return $owner;
            }

            $dir = $parent;
        }
    }

    private static function hasRootMarker(string $dir): bool
    {
        return \is_file($dir . '/rector.php') || \is_file($dir . '/composer.json');
    }

    private static function directoryOf(string $path): string
    {
        return \is_dir($path) ? $path : \dirname($path);
    }

    /** @param list<string> $folders */
    private static function ownerFolder(string $documentPath, array $folders): ?string
    {
        $normalizedDoc = self::normalize($documentPath);
        $best = null;
        $bestLength = -1;

        foreach ($folders as $folder) {
            $normalizedFolder = self::normalize($folder);
            if ($normalizedDoc !== $normalizedFolder && !self::isDescendant($normalizedDoc, $normalizedFolder)) {
                continue;
            }

            if (\strlen($normalizedFolder) > $bestLength) {
                $best = $folder;
                $bestLength = \strlen($normalizedFolder);
            }
        }

        return $best;
    }

    private static function isDescendant(string $path, string $ancestor): bool
    {
        // #298: a plain string-prefix test over normalize()'d paths is
        // fooled by a ".." segment that textually re-creates the
        // ancestor's own prefix (e.g. "$owner/../elsewhere" lexically
        // starts with "$owner/") and by a symlink whose literal path sits
        // under the ancestor while its real target does not -- both let a
        // document outside the owning workspace folder resolve as if it
        // were inside it. Canonicalise both sides first so the comparison
        // is against where the path actually is, not how it is spelled.
        $path = self::canonicalize($path);
        $ancestor = self::canonicalize($ancestor);

        return $path === $ancestor || \str_starts_with($path, $ancestor . '/');
    }

    private static function canonicalize(string $path): string
    {
        // realpath() resolves "..", ".", and symlinks against the real
        // filesystem -- but it returns false for a path that does not
        // (yet) exist on disk: a directory this walk-up loop is still
        // about to create, a synthetic path a unit test builds without
        // ever touching the filesystem, or a Windows-style drive-letter
        // path exercised on a non-Windows runner that can never resolve.
        $resolved = \realpath($path);
        if ($resolved !== false) {
            return self::normalize($resolved);
        }

        // #298 follow-up (review finding): falling straight to a purely
        // lexical collapse of the WHOLE path the moment realpath() fails
        // on it mixed two different canonicalisation strategies across
        // the two sides of isDescendant()'s comparison whenever only one
        // side happened to exist on disk yet -- the workspace root
        // (which almost always exists, and is routinely itself behind a
        // symlink: macOS resolves sys_get_temp_dir() under /tmp, itself
        // a symlink to /private/tmp) got realpath()'d through the
        // symlink, while a brand-new/unsaved document's own directory
        // (which often does not exist yet) did not -- so the two no
        // longer shared a prefix even though one was genuinely inside
        // the other, and resolve() silently fell back to the wrong, too
        // shallow root. Resolving the longest EXISTING ancestor through
        // realpath() first, and only running the lexical collapse over
        // the non-existent tail, keeps both sides on the same symlink
        // resolution the moment any shared ancestor exists on disk.
        return self::collapseDotSegments(self::resolveExistingAncestor(self::normalize($path)));
    }

    private static function resolveExistingAncestor(string $normalized): string
    {
        $isAbsolute = \str_starts_with($normalized, '/');
        $segments = \explode('/', $normalized);
        $keep = \count($segments);
        $floor = $isAbsolute ? 1 : 0;

        while ($keep > $floor) {
            $keep--;
            $prefix = $isAbsolute && $keep === 1 ? '/' : \implode('/', \array_slice($segments, 0, $keep));

            if ($prefix === '') {
                continue;
            }

            $resolved = \realpath($prefix);
            if ($resolved === false) {
                continue;
            }

            $tail = \implode('/', \array_slice($segments, $keep));

            return $tail === '' ? self::normalize($resolved) : self::normalize($resolved) . '/' . $tail;
        }

        // No ancestor of this path exists on disk at all (every segment
        // was synthetic, or the path is a Windows-style drive-letter
        // path on a non-Windows runner, which can never resolve) --
        // nothing left to canonicalise against the filesystem.
        return $normalized;
    }

    private static function collapseDotSegments(string $path): string
    {
        // A Windows-style drive-letter path ("C:/...", "C:") has no
        // leading "/", so it would otherwise be treated as relative and
        // its drive segment popped by a leading ".." like any ordinary
        // path component. Split it off first and protect it exactly the
        // way a POSIX leading "/" is already protected below, then
        // re-attach it to the result.
        $drive = '';
        $rest = $path;
        if (\preg_match('/^([A-Za-z]:)(\/.*)?$/', $path, $m) === 1) {
            $drive = $m[1];
            $rest = $m[2] ?? '';
            $rest = $rest === '' ? '/' : $rest;
        }

        $isAbsolute = $drive !== '' || \str_starts_with($rest, '/');
        $stack = [];

        foreach (\explode('/', $rest) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($stack !== [] && \end($stack) !== '..') {
                    \array_pop($stack);
                } elseif (!$isAbsolute) {
                    $stack[] = '..';
                }
                // ".." above an absolute root (POSIX "/" or a drive
                // letter) has nowhere left to go -- drop it rather than
                // let the stack go negative or pop the drive itself.
                continue;
            }

            $stack[] = $segment;
        }

        $result = ($isAbsolute ? '/' : '') . \implode('/', $stack);
        $result = $result === '' ? '/' : $result;

        if ($drive === '') {
            return $result;
        }

        return $result === '/' ? $drive : $drive . $result;
    }

    private static function normalize(string $path): string
    {
        $path = \str_replace('\\', '/', $path);

        return $path === '/' ? $path : \rtrim($path, '/');
    }
}
