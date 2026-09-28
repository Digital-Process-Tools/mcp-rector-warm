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
        if (!class_exists(\Composer\InstalledVersions::class)) {
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

        return self::normalizeComposerVersion($pretty);
    }

    /**
     * The "does this actually look like a released semantic version" gate,
     * extracted so it can be unit-tested directly against fixture strings
     * rather than only through whatever Composer\InstalledVersions reports
     * in the process actually running the test (that ambient value depends
     * on how *this* checkout was obtained, not on anything the test
     * controls).
     *
     * Anchored at both ends and requires the whole string to be a bare
     * x.y.z (with an optional leading "v" and an optional semver
     * pre-release/build suffix like "-beta.1" or "+build5") -- not merely
     * to *start* with three numeric components. A branch-derived pseudo
     * version such as "dev-main" or "9999999-dev" is rejected outright by
     * the anchored digit match; a hypothetical branch-alias shaped like
     * "0.6.0-dev" would otherwise pass a three-numbers-then-anything check,
     * so any pre-release/build suffix containing "dev" is rejected too.
     */
    public static function normalizeComposerVersion(?string $pretty): ?string
    {
        if ($pretty === null) {
            return null;
        }

        if (preg_match('/^v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.]+)?)$/', $pretty, $matches) !== 1) {
            return null;
        }

        // Reject only when "dev" is a component of its own (delimited by the
        // string's start, a dot, a hyphen or a plus on both sides) rather
        // than any substring -- a real pre-release/build token that merely
        // contains those four letters, e.g. "1.0.0-development" or
        // "1.0.0+devteam", is not a branch-derived pseudo-version and must
        // not be rejected alongside "0.6.0-dev".
        if (preg_match('/(?:^|[.\-+])dev(?:[.\-+]|$)/i', $matches[1]) === 1) {
            return null;
        }

        return $matches[1];
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
