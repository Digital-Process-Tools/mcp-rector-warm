<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Support\ServerVersion;
use PHPUnit\Framework\TestCase;

/**
 * #59: the server used to hardcode '0.4.1' in bin/mcp-rector-warm regardless
 * of what actually shipped. These pin the changelog-fallback half of
 * ServerVersion directly -- the composer-installed half depends on how this
 * package itself got installed and is covered end to end by
 * tests/E2E/test_mcp_client.py instead.
 */
final class ServerVersionTest extends TestCase
{
    private string $changelog;

    protected function setUp(): void
    {
        $this->changelog = tempnam(sys_get_temp_dir(), 'server-version-changelog-');
    }

    protected function tearDown(): void
    {
        @unlink($this->changelog);
    }

    public function testReadsTheLatestReleasedHeading(): void
    {
        file_put_contents($this->changelog, <<<MD
            # Changelog

            ## [Unreleased]

            ## [0.5.0] - 2026-09-27

            ### Added

            - something

            ## [0.4.1] - 2026-08-01

            ### Fixed

            - something else
            MD);

        self::assertSame('0.5.0', ServerVersion::versionFromChangelog($this->changelog));
    }

    public function testSkipsAnUnreleasedHeadingWithNoVersionYet(): void
    {
        // Positive control for the assertion above: without a released
        // heading at all, there is nothing to report -- this must not
        // silently match "Unreleased" as if it were a version.
        file_put_contents($this->changelog, "# Changelog\n\n## [Unreleased]\n\n- wip\n");

        self::assertNull(ServerVersion::versionFromChangelog($this->changelog));
    }

    public function testReturnsNullForAMissingFile(): void
    {
        self::assertNull(ServerVersion::versionFromChangelog($this->changelog . '-does-not-exist'));
    }

    /**
     * Composer's real "install this, resolve()'s preferred source" plumbing
     * (fromInstalledVersions()) is not exercised directly here -- it depends
     * on how this package itself got installed (an ambient fact resolve()'s
     * own docblock explains), which a unit test controls only by mocking
     * Composer\InstalledVersions itself. The decision logic it delegates to
     * -- "does this pretty_version string actually look like a released
     * semver" -- is the part worth pinning, and normalizeComposerVersion()
     * is pure and takes the string directly, so these fixtures exercise it
     * deterministically instead of depending on this checkout's ambient
     * Composer state (which the removed predecessor of this test did, and
     * which made it pass or fail for reasons unrelated to the logic under
     * test -- see #59 review).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('composerVersionShapes')]
    public function testNormalizeComposerVersionAcceptsOnlyARealReleasedSemver(?string $pretty, ?string $expected): void
    {
        self::assertSame($expected, ServerVersion::normalizeComposerVersion($pretty));
    }

    /**
     * #77: fromInstalledVersions() called class_exists() with autoloading
     * disabled (the second argument false). Composer\InstalledVersions is a
     * classmap-only class -- vendor/autoload.php never requires it eagerly --
     * so with autoloading off the check always returns false and the
     * Composer-pretty-version branch never runs, in any process, ever.
     *
     * Run in a separate process (a fresh PHP interpreter) so this is a true
     * positive control: without process isolation, an earlier test in the
     * same run could have already triggered the autoload of
     * Composer\InstalledVersions for an unrelated reason, which would make
     * the "not loaded yet" assertion below pass even with the bug present.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testResolveActuallyAutoloadsComposerInstalledVersions(): void
    {
        self::assertFalse(
            class_exists(\Composer\InstalledVersions::class, false),
            'positive control: the class must not already be loaded in this fresh process',
        );

        ServerVersion::resolve();

        self::assertTrue(
            class_exists(\Composer\InstalledVersions::class, false),
            'resolve() must autoload Composer\InstalledVersions itself via its own class_exists() check, not merely observe that something else already loaded it',
        );
    }

    public static function composerVersionShapes(): array
    {
        return [
            'plain release' => ['0.5.0', '0.5.0'],
            'leading v is stripped' => ['v0.5.0', '0.5.0'],
            'pre-release suffix kept' => ['1.0.0-beta.1', '1.0.0-beta.1'],
            'build metadata kept' => ['1.0.0+build5', '1.0.0+build5'],
            'null is rejected' => [null, null],
            'branch pseudo-version is rejected' => ['dev-main', null],
            'unreachable-tag pseudo-version is rejected' => ['9999999-dev', null],
            'a dev-flavoured branch-alias is rejected' => ['0.6.0-dev', null],
            // "dev" only rejects as its own delimited component -- a
            // pre-release/build token that merely contains those letters
            // is a real accepted shape, not a branch-derived pseudo-version.
            'a pre-release token containing "dev" as a substring is accepted' => ['1.0.0-development', '1.0.0-development'],
            'a build token containing "dev" as a substring is accepted' => ['1.0.0+devteam', '1.0.0+devteam'],
            'trailing garbage after a real triple is rejected' => ['0.5.0 (unstable)', null],
            'two components is not a semver' => ['0.5', null],
        ];
    }
}
