<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\RectorDiffParser;
use PHPUnit\Framework\TestCase;

/**
 * A real diff captured from `vendor/bin/rector process --output-format=json
 * --debug --no-progress-bar --dry-run` against a fixture that
 * SimplifyIfReturnBoolRector fires on (verified 2026-09-28 against
 * rector/rector ^2.4 -- see RectorTool.php's corrected comment for #53).
 */
final class RectorDiffParserTest extends TestCase
{
    private const SIMPLIFY_IF_DIFF = "--- Original\n+++ New\n@@ -4,9 +4,6 @@\n {\n     public function isEmpty(array \$items): bool\n     {\n-        if (count(\$items) === 0) {\n-            return true;\n-        }\n-        return false;\n+        return count(\$items) === 0;\n     }\n }\n";

    public function testBuildFixesTurnsOneHunkIntoOneRangeAndNewText(): void
    {
        $fixes = RectorDiffParser::buildFixes(
            self::SIMPLIFY_IF_DIFF,
            ['Rector\\CodeQuality\\Rector\\If_\\SimplifyIfReturnBoolRector'],
            [['rector' => 'Rector\\CodeQuality\\Rector\\If_\\SimplifyIfReturnBoolRector', 'line' => 5]],
        );

        self::assertCount(1, $fixes);
        self::assertSame(
            ['start' => ['line' => 3, 'character' => 0], 'end' => ['line' => 12, 'character' => 0]],
            $fixes[0]['range'],
        );
        self::assertSame(
            "{\n    public function isEmpty(array \$items): bool\n    {\n        return count(\$items) === 0;\n    }\n}\n",
            $fixes[0]['newText'],
        );
        self::assertSame(
            ['Rector\\CodeQuality\\Rector\\If_\\SimplifyIfReturnBoolRector'],
            $fixes[0]['rectors'],
        );
    }

    public function testBuildFixesFallsBackToAppliedRectorsWhenNoChangeLineIsInRange(): void
    {
        // No `changes` entry lands inside [4,12] -- the fallback to the
        // file-wide applied_rectors list is what keeps the message non-empty.
        $fixes = RectorDiffParser::buildFixes(
            self::SIMPLIFY_IF_DIFF,
            ['Rector\\CodeQuality\\Rector\\If_\\SimplifyIfReturnBoolRector'],
            [],
        );

        self::assertSame(
            ['Rector\\CodeQuality\\Rector\\If_\\SimplifyIfReturnBoolRector'],
            $fixes[0]['rectors'],
        );
    }

    public function testEmptyDiffProducesNoFixes(): void
    {
        // Negative control for the case above: a file Rector does not change
        // has an empty diff and must produce zero fixes, not a false one.
        self::assertSame([], RectorDiffParser::buildFixes('', [], []));
    }

    public function testParseHunksHandlesAPureInsertion(): void
    {
        // `@@ -a,0 +b,c @@`: nothing consumed from the old file.
        $diff = "--- Original\n+++ New\n@@ -2,0 +3,1 @@\n+new line\n";

        $hunks = RectorDiffParser::parseHunks($diff);

        self::assertSame([['oldStart' => 2, 'oldCount' => 0, 'newLines' => ['new line']]], $hunks);
    }

    public function testParseHunksHandlesMultipleHunksInOneDiff(): void
    {
        $diff = "--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old top\n+new top\n@@ -10,1 +10,1 @@\n-old bottom\n+new bottom\n";

        $hunks = RectorDiffParser::parseHunks($diff);

        self::assertCount(2, $hunks);
        self::assertSame(1, $hunks[0]['oldStart']);
        self::assertSame(10, $hunks[1]['oldStart']);
    }
}
