<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Support\WorkingDirectoryArgument;
use PHPUnit\Framework\TestCase;

/**
 * #245: bin/rector-warm-lsp and bin/mcp-rector-warm each parsed their own
 * --working-dir=... flag inline, as top-level script code that never runs
 * under PHPUnit (a bin/ entrypoint is never require()'d by a test -- it would
 * exit() and read real stdin). That left this logic entirely unit-tested,
 * 0% line coverage on the entrypoint files per the Codecov snapshot this
 * issue cites. Extracting the pure parsing into this class makes the logic
 * itself testable; the two entrypoints now call WorkingDirectoryArgument::parse()
 * instead of repeating the inline foreach/str_starts_with/substr.
 */
final class WorkingDirectoryArgumentTest extends TestCase
{
    public function testReturnsNullWhenNoWorkingDirFlagIsPresent(): void
    {
        self::assertNull(WorkingDirectoryArgument::parse(['rector-warm-lsp']));
    }

    public function testReturnsNullWhenArgvHasOnlyUnrelatedFlags(): void
    {
        self::assertNull(WorkingDirectoryArgument::parse(['rector-warm-lsp', '--debug', '--config=foo.php']));
    }

    public function testParsesTheFlagValue(): void
    {
        self::assertSame(
            '/srv/project',
            WorkingDirectoryArgument::parse(['rector-warm-lsp', '--working-dir=/srv/project']),
        );
    }

    public function testParsesTheFlagValueAmongOtherArgs(): void
    {
        self::assertSame(
            '/srv/project',
            WorkingDirectoryArgument::parse(['rector-warm-lsp', '--debug', '--working-dir=/srv/project', '--config=foo.php']),
        );
    }

    public function testStopsAtTheFirstOccurrenceWhenTheFlagIsRepeated(): void
    {
        self::assertSame(
            '/first',
            WorkingDirectoryArgument::parse(['rector-warm-lsp', '--working-dir=/first', '--working-dir=/second']),
        );
    }

    public function testIgnoresArgv0SoAProgramNameThatHappensToContainTheFlagTextIsNotMisread(): void
    {
        self::assertNull(WorkingDirectoryArgument::parse(['--working-dir=/trap']));
    }

    public function testEmptyValueAfterTheEqualsSignParsesAsAnEmptyString(): void
    {
        self::assertSame('', WorkingDirectoryArgument::parse(['rector-warm-lsp', '--working-dir=']));
    }
}
