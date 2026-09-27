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
 * spelled out `/Users/floriandavid/Documents/mcp-rector-warm/.supertool.json`
 * verbatim.
 */
final class TrapDNoHostPathsTest extends TestCase
{
    private const HOST_PATH_PATTERN = '/\/(Users|home)\/[A-Za-z0-9_.-]+\//';

    public function testTrapDFragmentsContainNoAbsoluteHomePaths(): void
    {
        $offenders = [];

        foreach ($this->trapDFragmentFiles() as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match(self::HOST_PATH_PATTERN, $contents, $match) === 1) {
                $offenders[] = sprintf('%s (matched "%s")', $file, $match[0]);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "trap.d/ fragments must not embed an absolute host home-directory path -- "
                . "found: " . implode(', ', $offenders)
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
        $offendingLine = 'found `/Users/floriandavid/Documents/mcp-rector-warm/.supertool.json`';

        self::assertSame(1, preg_match(self::HOST_PATH_PATTERN, $offendingLine));
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
