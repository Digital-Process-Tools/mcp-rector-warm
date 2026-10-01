<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\RectorDiffParser;
use PHPUnit\Framework\TestCase;

/**
 * #219: the remaining write-path gaps in RectorDiffParser.php's Codecov
 * report (post-#218) -- each test pins a branch no existing
 * RectorDiffParserTest case reached.
 */
final class RectorDiffParserWritePathGapsTest extends TestCase
{
    /**
     * finalizeHunk()'s $core loop keeps a context (' ') line only when it
     * sits strictly between the first and last change in the hunk --
     * "interior" context from two edits a few lines apart kept in the same
     * hunk (see the docblock above finalizeHunk()). Every existing diff
     * fixture in RectorDiffParserTest has at most one change per hunk, so
     * that interior-context branch (line 161) was never exercised.
     */
    public function testParseHunksKeepsInteriorContextBetweenTwoChangesInOneHunk(): void
    {
        $diff = "--- Original\n+++ New\n@@ -2,5 +2,5 @@\n keep before\n-removed A\n+added A\n context middle\n-removed B\n+added B\n keep after\n";

        $hunks = RectorDiffParser::parseHunks($diff);

        self::assertCount(1, $hunks);
        self::assertSame(3, $hunks[0]['changeFrom']);
        self::assertSame(6, $hunks[0]['changeToExclusive']);
        // Leading "keep before" and trailing "keep after" are pure padding
        // and are excluded; "context middle" sits between the two changes
        // and is interior, so it is kept alongside the two additions.
        self::assertSame(['added A', 'context middle', 'added B'], $hunks[0]['newLines']);
    }

    /**
     * A pure-deletion hunk (every body line is `-`, no `+` and no context
     * line at all) has hasChange=true but an empty $core: there is nothing
     * to add back to the file. hunkNewText() must report that as '', not
     * crash on an empty newLines array (line 345).
     */
    public function testPureDeletionHunkProducesEmptyNewText(): void
    {
        $diff = "--- Original\n+++ New\n@@ -5,3 +5,0 @@\n-removed line 1\n-removed line 2\n-removed line 3\n";

        $hunks = RectorDiffParser::parseHunks($diff);
        self::assertSame([], $hunks[0]['newLines']);

        $fixes = RectorDiffParser::buildFixes($diff, [], []);

        self::assertCount(1, $fixes);
        self::assertSame('', $fixes[0]['newText']);
        self::assertSame(
            ['start' => ['line' => 4, 'character' => 0], 'end' => ['line' => 7, 'character' => 0]],
            $fixes[0]['range'],
        );
    }

    /**
     * Rector's own JSON report is an external process output buildFixes()
     * does not fully trust (see its own @param docblock): a `changes[]`
     * entry missing `line` or `rector` must be skipped rather than crash
     * the attribution loop, falling through to the same single-hunk/
     * single-rule leftover attribution a genuinely empty $changes would get.
     */
    public function testMalformedChangeEntryIsSkippedNotCrashed(): void
    {
        $diff = "--- Original\n+++ New\n@@ -4,9 +4,6 @@\n {\n     public function isEmpty(array \$items): bool\n     {\n-        if (count(\$items) === 0) {\n-            return true;\n-        }\n-        return false;\n+        return count(\$items) === 0;\n     }\n }\n";
        $rector = \Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector::class;

        $fixes = RectorDiffParser::buildFixes(
            $diff,
            [$rector],
            // Neither entry carries both `line` and `rector` -- both are
            // skipped by the `continue` this test pins, leaving the hunk
            // unattributed so it falls through to leftover attribution.
            [['line' => 5], ['rector' => $rector]],
        );

        self::assertCount(1, $fixes);
        self::assertSame([$rector], $fixes[0]['rectors']);
    }
}
