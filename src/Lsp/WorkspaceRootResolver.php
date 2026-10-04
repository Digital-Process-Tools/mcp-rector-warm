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
            if (self::hasRootMarker($dir)) {
                return $dir;
            }

            if ($dir === $owner) {
                return $owner;
            }

            if (!self::isDescendant($dir, $owner)) {
                // $documentPath was never under $owner to begin with (no
                // matching folder, and the fallback is unrelated) -- stop
                // rather than walk somewhere the owner boundary never
                // authorised. RectorTool's own containment check is what
                // actually refuses the call; this just never looks further
                // than it has to.
                return $owner;
            }

            if (self::isFilesystemRoot($dir)) {
                // #107 self-review finding (oss:auditor pass): dirname() on
                // a bare Windows drive letter ('C:', after normalize() has
                // stripped its trailing slash) is not documented to be a
                // fixed point the way POSIX '/' is -- reasoned, not
                // observed on a real Windows runner. In every scenario
                // actually reachable through this loop the $dir === $owner
                // check two lines above already intercepts a drive root
                // before dirname() is ever called on it (confirmed by
                // construction: dir can only equal a bare drive root once
                // it has walked down to be no deeper than $owner, and
                // isDescendant() above means $owner is always something
                // dir passes through exactly on the way). Checked
                // explicitly anyway, before dirname(), so the termination
                // condition holds without depending on what dirname() does
                // with an input this class controls the production of.
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

    /** A POSIX root, or a bare Windows drive letter root ('C:', 'D:', ...) after normalize() has stripped any trailing slash. */
    private static function isFilesystemRoot(string $dir): bool
    {
        return $dir === '/' || \preg_match('#^[A-Za-z]:$#', $dir) === 1;
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
        return $path === $ancestor || \str_starts_with($path, $ancestor . '/');
    }

    private static function normalize(string $path): string
    {
        $path = \str_replace('\\', '/', $path);

        return $path === '/' ? $path : \rtrim($path, '/');
    }
}
