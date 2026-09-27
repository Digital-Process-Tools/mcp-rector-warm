<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Support;

/**
 * Resolves the server version reported in MCP's `initialize` response and
 * anywhere else this package needs to name its own version, instead of a
 * hardcoded literal drifting from what actually shipped (#59).
 *
 * Two sources, in order:
 *
 *   1. Composer's own record of what got installed
 *      (`Composer\InstalledVersions::getPrettyVersion()`). This is exact for
 *      a tagged release install (composer global require, or a project
 *      dependency pinned to a tag) but for a plain git checkout with no
 *      reachable tag on HEAD, Composer reports a branch-derived pseudo
 *      version such as `dev-main`, which is not a version anyone should see
 *      in a bug report -- so that shape is rejected rather than returned.
 *
 *   2. The latest released heading in CHANGELOG.md (`## [x.y.z]`), skipping
 *      `## [Unreleased]`. This is what a git checkout of `main` between
 *      releases actually is: whatever last shipped.
 */
final class ServerVersion
{
    private const PACKAGE = 'dpt/mcp-rector-warm';
    private const FALLBACK = '0.0.0-unknown';

    public static function resolve(?string $changelogPath = null): string
    {
        $fromComposer = self::fromInstalledVersions();
        if ($fromComposer !== null) {
            return $fromComposer;
        }

        $path = $changelogPath ?? dirname(__DIR__, 2) . '/CHANGELOG.md';
        $fromChangelog = self::versionFromChangelog($path);
        if ($fromChangelog !== null) {
            return $fromChangelog;
        }

        return self::FALLBACK;
    }

    private static function fromInstalledVersions(): ?string
    {
        if (!class_exists(\Composer\InstalledVersions::class, false)) {
            return null;
        }

        try {
            if (!\Composer\InstalledVersions::isInstalled(self::PACKAGE)) {
                return null;
            }
            $pretty = \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (\Throwable) {
            return null;
        }

        if ($pretty === null) {
            return null;
        }

        // Only trust a shape that actually looks like a released semantic
        // version. A dev checkout with no reachable tag reports something
        // like "dev-main" or "9999999-dev", which fails this check and
        // falls through to the changelog instead.
        if (preg_match('/^v?\d+\.\d+\.\d+/', $pretty) !== 1) {
            return null;
        }

        return ltrim($pretty, 'v');
    }

    /**
     * Extracted so it can be unit-tested directly against a fixture file,
     * independent of whatever Composer\InstalledVersions reports in the
     * process actually running the test.
     */
    public static function versionFromChangelog(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        if (preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $contents, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
