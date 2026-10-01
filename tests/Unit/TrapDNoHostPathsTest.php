<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * trap.d/ ships in the dist archive of every tagged release (no
 * .gitattributes export-ignore excludes it), so a fragment that embeds the
 * maintainer's absolute home directory -- `/Users/<name>/...` or
 * `/home/<name>/...` -- discloses the maintainer's local username and
 * directory layout to anyone who downloads the public tag. Fragments must
 * use a repo-relative form (e.g. `<repo>/...`) instead.
 *
 * Regression for #44: trap.d/22.supertool-worktree-config-boundary.md line 6
 * spelled out the maintainer's absolute local home path verbatim (a
 * `/Users/<name>/...` path). It is deliberately not reproduced here: this
 * test file itself ships in the same dist archive as trap.d/, so quoting
 * the real offending path in a fixture would reintroduce the exact
 * disclosure this guard exists to catch.
 */
final class TrapDNoHostPathsTest extends TestCase
{
    // No trailing "/" required after the username segment -- a path that ends
    // the string/sentence right after the username (`` `/Users/name` ``, or
    // "cd /Users/name.") must still be caught, not just one followed by a
    // further path component.
    private const HOST_PATH_PATTERN = '/\/(Users|home)\/[A-Za-z0-9_.-]+/';

    public function testTrapDFragmentsContainNoAbsoluteHomePaths(): void
    {
        $files = $this->trapDFragmentFiles();

        // Without this, a moved/renamed trap.d/ or a glob() that silently
        // returns nothing would leave $offenders == [] for the wrong reason
        // -- "nothing scanned" and "scanned everything, found nothing" must
        // not read as the same pass.
        self::assertNotEmpty($files, 'expected to find at least one trap.d/*.md fragment to scan');

        $offenders = [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match(self::HOST_PATH_PATTERN, $contents, $match) === 1) {
                $offenders[] = sprintf('%s (matched "%s")', $file, $match[0]);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "trap.d/ fragments must not embed an absolute host home-directory path -- "
                . "found: " . implode(', ', $offenders),
        );
    }

    /**
     * Positive control: the detection pattern above must actually be capable
     * of firing. Without this, testTrapDFragmentsContainNoAbsoluteHomePaths
     * would pass identically whether it correctly found zero offenders or
     * whether its regex was silently broken and could never match anything.
     */
    public function testDetectionPatternMatchesAKnownOffendingPath(): void
    {
        // Synthetic username -- deliberately not the maintainer's real one, so
        // this fixture cannot itself become an instance of the leak it tests
        // for once this file ships in a dist archive.
        $offendingLine = 'found `/Users/exampleuser/Documents/mcp-rector-warm/.supertool.json`';

        self::assertSame(1, preg_match(self::HOST_PATH_PATTERN, $offendingLine));
    }

    /**
     * Positive control for the no-trailing-slash case: a home path that ends
     * the sentence right after the username, with no further path component,
     * must still be caught -- not just one followed by another "/segment".
     */
    public function testDetectionPatternMatchesAPathWithNoTrailingSegment(): void
    {
        self::assertSame(1, preg_match(self::HOST_PATH_PATTERN, 'run from `/Users/exampleuser`.'));
        self::assertSame(1, preg_match(self::HOST_PATH_PATTERN, 'cd /home/exampleuser'));
    }

    /**
     * @return list<string>
     */
    private function trapDFragmentFiles(): array
    {
        $dir = dirname(__DIR__, 2) . '/trap.d';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.md') ?: [];
        sort($files);

        return $files;
    }
}
