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
                // authorised. RectorTool's own containment check is what
                // actually refuses the call; this just never looks further
                // than it has to.
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
        return $path === $ancestor || \str_starts_with($path, $ancestor . '/');
    }

    private static function normalize(string $path): string
    {
        $path = \str_replace('\\', '/', $path);

        return $path === '/' ? $path : \rtrim($path, '/');
    }
}
