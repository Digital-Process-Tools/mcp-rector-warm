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

    public function testResolveFallsBackToTheChangelogWhenGivenAnExplicitPath(): void
    {
        file_put_contents($this->changelog, "# Changelog\n\n## [1.2.3] - 2026-01-01\n");

        $resolved = ServerVersion::resolve($this->changelog);

        // Whatever Composer\InstalledVersions reports for this package in
        // the process actually running the test suite (typically a
        // "dev-*" branch pseudo-version for this checkout) is rejected by
        // ServerVersion's own semver check, so resolve() falls through to
        // the changelog path given here.
        self::assertSame('1.2.3', $resolved);
    }
}
