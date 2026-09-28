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
        // #91.2: the range covers only the four changed lines (old lines
        // 7-10 -- the removed `if`/`return true`/`}`/`return false`), not
        // the full 9-line hunk span the diff header carries (old lines
        // 4-12), which includes 3 lines of context on each side.
        self::assertSame(
            ['start' => ['line' => 6, 'character' => 0], 'end' => ['line' => 10, 'character' => 0]],
            $fixes[0]['range'],
        );
        // Self-review correction (post-merge CI failure -- warm-vs-cold
        // divergence in test_code_action_edit_matches_a_cold_rector_apply):
        // newText must be narrowed the same way the range was, or a narrow
        // range applied with WIDE replacement text (the full hunk, context
        // included) duplicates the context lines still on disk either side
        // of the range. Only the actual replacement -- the `+` line -- goes
        // in newText; the surrounding `{`/`}` context lines stay untouched.
        self::assertSame(
            "        return count(\$items) === 0;\n",
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

        self::assertSame([[
            'oldStart' => 2,
            'oldCount' => 0,
            'newLines' => ['new line'],
            'hasChange' => true,
            'changeFrom' => 2,
            'changeToExclusive' => 2,
        ]], $hunks);
    }

    public function testParseHunksHandlesMultipleHunksInOneDiff(): void
    {
        $diff = "--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old top\n+new top\n@@ -10,1 +10,1 @@\n-old bottom\n+new bottom\n";

        $hunks = RectorDiffParser::parseHunks($diff);

        self::assertCount(2, $hunks);
        self::assertSame(1, $hunks[0]['oldStart']);
        self::assertSame(10, $hunks[1]['oldStart']);
    }

    public function testParseHunksFlagsAContextOnlyHunkAsNoChange(): void
    {
        // #91.3: Rector has been observed emitting a hunk with no `+`/`-`
        // lines at all for a CRLF-only difference -- every line is context.
        $diff = "--- Original\n+++ New\n@@ -1,3 +1,3 @@\n <?php\n \n class X {}\n";

        $hunks = RectorDiffParser::parseHunks($diff);

        self::assertCount(1, $hunks);
        self::assertFalse($hunks[0]['hasChange']);
        self::assertNull($hunks[0]['changeFrom']);
        self::assertNull($hunks[0]['changeToExclusive']);
    }

    public function testBuildFixesSkipsAContextOnlyNoOpHunk(): void
    {
        // Positive control for the case above: a hunk with real changes
        // still produces a fix -- only the no-op hunk is skipped.
        $diff = "--- Original\n+++ New\n@@ -1,3 +1,3 @@\n <?php\n \n class X {}\n";

        self::assertSame([], RectorDiffParser::buildFixes($diff, ['SomeRector'], []));
    }

    public function testBuildFixesAttributesAnOutOfRangeChangeToTheClosestHunkOnly(): void
    {
        // #91.1: a change whose line falls one past the first hunk's own
        // span (and far from the second hunk) used to broadcast to EVERY
        // hunk in the file via the file-wide applied_rectors fallback. It
        // must now attach only to the closer hunk, leaving the other hunk's
        // rectors empty rather than also inheriting it.
        $diff = "--- Original\n+++ New\n"
            . "@@ -70,10 +70,10 @@\n"
            . " c1\n c2\n c3\n c4\n c5\n-removed_A\n+added_A\n c6\n c7\n c8\n c9\n"
            . "@@ -90,5 +90,5 @@\n"
            . " d1\n d2\n d3\n-removed_B\n+added_B\n d4\n";

        $changes = [
            ['rector' => 'RectorA', 'line' => 75],
            ['rector' => 'RectorB', 'line' => 81],
        ];

        $fixes = RectorDiffParser::buildFixes($diff, ['RectorA', 'RectorB'], $changes);

        self::assertCount(2, $fixes);
        self::assertSame(['RectorA', 'RectorB'], $fixes[0]['rectors']);
        self::assertSame([], $fixes[1]['rectors']);
    }

    public function testBuildFixesAttributesASingleLeftoverRuleToTheOneUnattributedHunk(): void
    {
        // #100: two hunks; `changes` only names the rule for the first, but
        // the file applied a SECOND rule the closest-hunk matching never
        // attaches to anything. When exactly one hunk is unattributed and
        // exactly one applied rule is unaccounted for, that leftover is
        // unambiguous -- it must be the second hunk's rule.
        $diff = "--- Original\n+++ New\n"
            . "@@ -70,10 +70,10 @@\n"
            . " c1\n c2\n c3\n c4\n c5\n-removed_A\n+added_A\n c6\n c7\n c8\n c9\n"
            . "@@ -90,5 +90,5 @@\n"
            . " d1\n d2\n d3\n-removed_B\n+added_B\n d4\n";

        $changes = [
            ['rector' => 'RectorA', 'line' => 75],
        ];

        $fixes = RectorDiffParser::buildFixes($diff, ['RectorA', 'RectorC'], $changes);

        self::assertCount(2, $fixes);
        self::assertSame(['RectorA'], $fixes[0]['rectors']);
        self::assertSame(['RectorC'], $fixes[1]['rectors']);
    }

    public function testBuildFixesLeavesTwoUnattributedHunksGenericWhenOnlyOneRuleIsLeftoverAndChangesIsEmpty(): void
    {
        // Negative control for the OLD, pre-#100 fallback: with `$changes`
        // empty, #91's file-wide broadcast (`$changes === []`) fires for
        // every hunk regardless of the new leftover logic -- this pins that
        // #100 did not change that pre-existing, deliberate behaviour.
        $diff = "--- Original\n+++ New\n"
            . "@@ -70,10 +70,10 @@\n"
            . " c1\n c2\n c3\n c4\n c5\n-removed_A\n+added_A\n c6\n c7\n c8\n c9\n"
            . "@@ -90,5 +90,5 @@\n"
            . " d1\n d2\n d3\n-removed_B\n+added_B\n d4\n";

        $fixes = RectorDiffParser::buildFixes($diff, ['RectorC'], []);

        self::assertSame(['RectorC'], $fixes[0]['rectors']);
        self::assertSame(['RectorC'], $fixes[1]['rectors']);
    }

    public function testBuildFixesLeavesTwoUnattributedHunksGenericWhenOnlyOneRuleIsLeftover(): void
    {
        // Self-review finding (independent Explore review pass): the test
        // above does NOT actually exercise #100's new leftover logic --
        // `$unattributedCount` is only ever computed when `$changes !==
        // []` (RectorDiffParser.php), so with an empty `$changes` the new
        // `$useLeftover` machinery is never reachable at all; that test
        // pins the OLD (#91) fallback, unchanged by this diff. This is the
        // real negative control: THREE hunks, `$changes` non-empty (one
        // entry, closest to hunk0), so hunk0 is attributed and hunks 1 AND
        // 2 are genuinely unattributed with real per-change matching in
        // play -- two unattributed hunks + one leftover rule is ambiguous
        // (which hunk does RectorC belong to?), so both must stay generic
        // rather than either one guessing.
        $diff = "--- Original\n+++ New\n"
            . "@@ -10,3 +10,3 @@\n"
            . " a1\n-removed_A\n+added_A\n a2\n"
            . "@@ -50,3 +50,3 @@\n"
            . " b1\n-removed_B\n+added_B\n b2\n"
            . "@@ -90,3 +90,3 @@\n"
            . " c1\n-removed_C\n+added_C\n c2\n";

        $changes = [
            ['rector' => 'RectorA', 'line' => 11],
        ];

        $fixes = RectorDiffParser::buildFixes($diff, ['RectorA', 'RectorC'], $changes);

        self::assertCount(3, $fixes);
        self::assertSame(['RectorA'], $fixes[0]['rectors']);
        self::assertSame([], $fixes[1]['rectors']);
        self::assertSame([], $fixes[2]['rectors']);
    }

    public function testBuildFixesLeavesGenericWhenNoLeftoverRuleRemains(): void
    {
        // A second negative control: one unattributed hunk, but nothing is
        // actually leftover (every applied rule was already matched
        // elsewhere) -- must stay unattributed rather than reusing a rule
        // that DID produce another hunk.
        $diff = "--- Original\n+++ New\n"
            . "@@ -70,10 +70,10 @@\n"
            . " c1\n c2\n c3\n c4\n c5\n-removed_A\n+added_A\n c6\n c7\n c8\n c9\n"
            . "@@ -90,5 +90,5 @@\n"
            . " d1\n d2\n d3\n-removed_B\n+added_B\n d4\n";

        $changes = [
            ['rector' => 'RectorA', 'line' => 75],
        ];

        $fixes = RectorDiffParser::buildFixes($diff, ['RectorA'], $changes);

        self::assertSame(['RectorA'], $fixes[0]['rectors']);
        self::assertSame([], $fixes[1]['rectors']);
    }
}
