<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Warm;

/**
 * #185: the one place session paths are normalised, so every comparison
 * (snapshot roots and exclusions, autoload and cache directories, a resolved
 * file against the analysed one) compares like with like. On Windows
 * realpath() and get_included_files() answer with '\' while paths built here
 * use '/'; PHP accepts '/' there, so '/' is the form everything is stored and
 * compared in. The session itself only runs with pcntl, but these classes must
 * still be correct wherever they run.
 */
final class Path
{
    public static function normalise(string $path): string
    {
        return \str_replace('\\', '/', $path);
    }

    /** realpath(), normalised, without a trailing '/' (except the root); null when missing. */
    public static function real(string $path): ?string
    {
        $real = \realpath($path);
        if ($real === false) {
            return null;
        }
        $real = self::normalise($real);

        return $real === '/' ? $real : (\rtrim($real, '/') ?: '/');
    }

    /** $path equals $parent or lies under it; both already normalised. */
    public static function isUnder(string $path, string $parent): bool
    {
        return $path === $parent || \str_starts_with($path, \rtrim($parent, '/') . '/');
    }
}
