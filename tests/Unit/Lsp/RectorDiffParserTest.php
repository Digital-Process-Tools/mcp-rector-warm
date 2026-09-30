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
            'eol' => "\n",
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

    public function testBuildFixesPinsTheKnownAmbiguousLeftoverAttributionFromIssue154(): void
    {
        // #154: this is the SAME input shape as the test above --
        // buildFixes()'s own inputs (diff, applied_rectors, changes[])
        // cannot tell "RectorC produced hunk1" apart from "RectorA
        // produced BOTH hunks, and changes[] only reported the one for
        // hunk0" -- Rector's `--output-format=json` report exposes no
        // per-rule hunk count that would settle it. The self-review
        // comment right above the leftover-attribution code in
        // buildFixes() already documented this exact ambiguity; #154
        // filed it, and its own resolution accepted the residual
        // false-positive rate as a presentation-layer (diagnostic label)
        // risk rather than a correctness/security one, since narrowing
        // the guard further needs data Rector does not currently expose.
        // This test exists so a future change to the leftover heuristic
        // does not silently alter this documented, accepted behaviour
        // without anyone noticing -- it pins the CURRENT output, not a
        // claim that the output is provably correct for this shape.
        $diff = "--- Original\n+++ New\n"
            . "@@ -70,10 +70,10 @@\n"
            . " c1\n c2\n c3\n c4\n c5\n-removed_A\n+added_A\n c6\n c7\n c8\n c9\n"
            . "@@ -90,5 +90,5 @@\n"
            . " d1\n d2\n d3\n-removed_B\n+added_B\n d4\n";

        // Same shape as RectorA producing BOTH hunks while changes[] only
        // reports the line for hunk0 -- RectorC stands in for "some other
        // rule that happened to apply but touched neither hunk itself".
        $changes = [
            ['rector' => 'RectorA', 'line' => 75],
        ];

        $fixes = RectorDiffParser::buildFixes($diff, ['RectorA', 'RectorC'], $changes);

        self::assertCount(2, $fixes);
        self::assertSame(['RectorA'], $fixes[0]['rectors']);
        // Current, documented, accepted behaviour: hunk1 is attributed to
        // the leftover rule RectorC -- which is WRONG if RectorA (not
        // RectorC) is what actually produced hunk1, the exact scenario
        // #154 describes and that buildFixes() cannot distinguish.
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
        // A second negative control: two applied rules, both already
        // matched to the SAME hunk (so nothing is genuinely leftover), and
        // a second hunk with no per-change attribution at all -- must stay
        // unattributed rather than reusing a rule that DID produce another
        // hunk. Unlike the reopened #100 case below, this file applied MORE
        // than one rule, so which of them (if either) produced the second
        // hunk is genuinely ambiguous.
        $diff = "--- Original\n+++ New\n"
            . "@@ -70,10 +70,10 @@\n"
            . " c1\n c2\n c3\n c4\n c5\n-removed_A\n+added_A\n c6\n c7\n c8\n c9\n"
            . "@@ -90,5 +90,5 @@\n"
            . " d1\n d2\n d3\n-removed_B\n+added_B\n d4\n";

        $changes = [
            ['rector' => 'RectorA', 'line' => 74],
            ['rector' => 'RectorB', 'line' => 75],
        ];

        $fixes = RectorDiffParser::buildFixes($diff, ['RectorA', 'RectorB'], $changes);

        self::assertCount(2, $fixes);
        self::assertSame(['RectorA', 'RectorB'], $fixes[0]['rectors']);
        self::assertSame([], $fixes[1]['rectors']);
    }

    public function testHunkNewTextUsesCrlfWhenTheKeptLinesInTheHunkAreCrlf(): void
    {
        // #160 unit-level coverage (self-review: independent Explore review
        // pass flagged that only the slow, full-stack E2E test exercised
        // this at all): context lines carry a trailing \r, exactly the
        // shape a real CRLF file's diff has -- Rector's own printer still
        // emits the `+` line with a bare "\n".
        $diff = "--- Original\n+++ New\n@@ -1,3 +1,3 @@\n <?php\r\n-old\r\n+new\n <?php\r\n";

        $fixes = RectorDiffParser::buildFixes($diff, ['SomeRector'], []);

        self::assertSame("new\r\n", $fixes[0]['newText']);
    }

    public function testParseHunksDetectsEolPerHunkNotFileWide(): void
    {
        // Self-review (independent Explore review pass): a single
        // file-wide `eol` used to be stamped onto every hunk -- wrong for a
        // source file with genuinely mixed line endings (a real, if rare,
        // shape after a partial bad conversion). Hunk 1's own kept lines
        // are CRLF; hunk 2's own kept lines are plain LF -- each hunk must
        // keep its OWN convention rather than one bleeding into the other.
        $diff = "--- Original\n+++ New\n"
            . "@@ -1,3 +1,3 @@\n <?php\r\n-old1\r\n+new1\n <?php\r\n"
            . "@@ -10,3 +10,3 @@\n <?php\n-old2\n+new2\n <?php\n";

        $hunks = RectorDiffParser::parseHunks($diff);

        self::assertCount(2, $hunks);
        self::assertSame("\r\n", $hunks[0]['eol']);
        self::assertSame("\n", $hunks[1]['eol']);
    }

    public function testParseHunksFallsBackToTheFileWideEolWhenAHunkHasNoKeptLineOfItsOwn(): void
    {
        // Self-review (independent auditor pass): a pure-insertion hunk
        // with no surrounding context has no `-`/` ` line of its own to
        // read -- it must fall back to what the REST of the diff's kept
        // lines say (here: CRLF, from hunk 1) rather than defaulting to a
        // bare "\n" and silently reproducing #160 in this narrow shape.
        $diff = "--- Original\n+++ New\n"
            . "@@ -1,3 +1,3 @@\n <?php\r\n-old1\r\n+new1\n <?php\r\n"
            . "@@ -20,0 +20,1 @@\n+inserted\n";

        $hunks = RectorDiffParser::parseHunks($diff);

        self::assertCount(2, $hunks);
        self::assertSame("\r\n", $hunks[0]['eol']);
        // Hunk 2 has no kept line of its own -- falls back to the
        // file-wide signal from hunk 1, not a hardcoded "\n".
        self::assertSame("\r\n", $hunks[1]['eol']);
    }

    public function testParseHunksDoesNotOverStripARealTrailingCarriageReturn(): void
    {
        // Self-review (independent Explore review pass): the CRLF fix used
        // to strip stored line text with `rtrim($text, "\r")`, which
        // removes EVERY trailing \r rather than just the single one a
        // CRLF-terminated diff line carries -- wrong for a line whose real
        // content genuinely ends in a literal \r (rare, but this function's
        // whole purpose is byte-exact fidelity). Two trailing \r bytes
        // here: one is the line's own content, the other is the CRLF
        // terminator this function's `explode("\n", ...)` leaves attached.
        // Only the terminator must be stripped.
        $diff = "--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old\r\n+line ending in a real carriage return\r\r\n";

        $hunks = RectorDiffParser::parseHunks($diff);

        self::assertSame("line ending in a real carriage return\r", $hunks[0]['newLines'][0]);
    }

    public function testBuildFixesAttributesTheSoleAppliedRuleToEveryUnattributedHunk(): void
    {
        // #100 reopen (PR #103 did not fix this): a single rule can produce
        // TWO hunks while `changes[]` reports only one line for it --
        // closest-hunk matching attributes that one line to hunk0, leaving
        // hunk1 with no per-change attribution and, critically, the rule is
        // now in `$attributed` so the OLD leftover fallback (which only
        // fires for a rule unaccounted for ANYWHERE) never reaches it
        // either. When the file applied exactly one rule, there is no other
        // candidate: any active hunk in the diff must be that rule's work.
        // Observed on real consumer files for NewlineAfterStatementRector
        // and RemoveUnusedPrivatePropertyRector (see the issue's reopen
        // comment).
        $diff = "--- Original\n+++ New\n"
            . "@@ -70,10 +70,10 @@\n"
            . " c1\n c2\n c3\n c4\n c5\n-removed_A\n+added_A\n c6\n c7\n c8\n c9\n"
            . "@@ -90,5 +90,5 @@\n"
            . " d1\n d2\n d3\n-removed_B\n+added_B\n d4\n";

        $changes = [
            ['rector' => 'RectorA', 'line' => 75],
        ];

        $fixes = RectorDiffParser::buildFixes($diff, ['RectorA'], $changes);

        self::assertCount(2, $fixes);
        self::assertSame(['RectorA'], $fixes[0]['rectors']);
        self::assertSame(['RectorA'], $fixes[1]['rectors']);
    }
}
