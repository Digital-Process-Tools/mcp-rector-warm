<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Warm;

use Dpt\McpRectorWarm\Warm\Path;
use PHPUnit\Framework\TestCase;

/**
 * #185: Windows paths (as realpath() and get_included_files() report them there)
 * and '/'-built paths must compare equal after normalisation -- the failure
 * windows-latest showed on PR #189. Runs on every OS: the inputs are strings.
 */
final class PathTest extends TestCase
{
    public function testBackslashesBecomeSlashes(): void
    {
        self::assertSame('C:/Users/x/project/src', Path::normalise('C:\Users\x\project\src'));
        self::assertSame('/already/posix', Path::normalise('/already/posix'));
    }

    public function testAWindowsPathIsUnderItsSlashBuiltParent(): void
    {
        $parent = Path::normalise('C:\tmp\project');
        self::assertTrue(Path::isUnder(Path::normalise('C:\tmp\project\cache'), $parent));
        self::assertTrue(Path::isUnder($parent . '/cache/entry.php', $parent));
        self::assertTrue(Path::isUnder($parent, $parent));
        // Must not fire: a sibling sharing the prefix is not under it.
        self::assertFalse(Path::isUnder(Path::normalise('C:\tmp\project-other\a.php'), $parent));
    }

    public function testRealReturnsAnExistingPathNormalisedAndNullForAMissingOne(): void
    {
        $real = Path::real(__DIR__);
        self::assertNotNull($real);
        self::assertStringNotContainsString('\\', $real);
        self::assertNull(Path::real(__DIR__ . '/does-not-exist'));
    }
}
