<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

/**
 * Turns one file's unified diff (Rector's `file_diffs[].diff`, produced by a
 * dry-run `rector process --output-format=json`) into LSP-ready fixes: an
 * exclusive-line Range in the CURRENT on-disk document plus the replacement
 * text, one per hunk. #53's diagnostics/codeAction/WorkspaceEdit machinery
 * builds directly on this -- no full-file read is needed, since a WorkspaceEdit
 * is just a list of per-hunk TextEdits (the "whole-file" action in
 * LspServer is simply all of them applied together).
 *
 * Known v1 gap, same shape as "unsaved buffers" being out of v1's scope: a
 * hunk touching the very last line of a file that has NO trailing newline
 * loses that fact (newText always ends with "\n" when non-empty). Rector's
 * own fixtures/rectors overwhelmingly produce trailing-newline files, so this
 * has not been observed in practice, but it is reasoned rather than proven.
 */
final class RectorDiffParser
{
    /**
     * @return list<array{oldStart: int, oldCount: int, newLines: list<string>, hasChange: bool, changeFrom: int|null, changeToExclusive: int|null, eol: string}>
     *   `hasChange` (#91.3) is false for a hunk whose body is entirely context
     *   lines -- Rector has been observed emitting exactly this for a
     *   line-ending-only (CRLF) difference. `changeFrom`/`changeToExclusive`
     *   (#91.2) are the 1-based OLD-file line span the hunk's own `-`/`+`
     *   lines actually touch, narrower than the full `oldStart..oldStart+
     *   oldCount-1` span whenever the hunk carries unified-diff context
     *   lines (3 on each side, by default) -- both null when `hasChange` is
     *   false. `eol` (#160) is THIS HUNK's own line-ending convention --
     *   "\r\n" when any of ITS OWN kept `-`/` ` lines still carries a
     *   trailing `\r` (this function only explodes on "\n", so a CRLF
     *   source file's own lines keep it), "\n" when it has at least one
     *   kept line and none of them do. A hunk with NO kept lines at all
     *   (e.g. a pure insertion with no surrounding context) has no signal
     *   of its own, so it falls back to whatever the REST of the diff's
     *   kept lines say (self-review: reviewed independently and found to
     *   default to a bare "\n" even for a CRLF file otherwise, silently
     *   reproducing #160 in that narrow shape -- the file-wide fallback
     *   closes it for every diff that has at least one hunk with context).
     *   Rector's pretty-printer emits newly ADDED (`+`) lines with a bare
     *   "\n" regardless of the source file's convention, so `+` lines
     *   cannot be used to detect it -- only `-`/` ` lines, which are
     *   verbatim copies of the original file, can. Per-hunk (rather than
     *   file-wide only) so a source file with genuinely mixed line
     *   endings does not have one hunk's convention bleed into another's.
     */
    public static function parseHunks(string $diff): array
    {
        // #160: a file-wide FALLBACK only, used for a hunk with no kept
        // line of its own to read -- see the `eol` doc above.
        $fallbackEol = preg_match('/^[- ].*\r$/m', $diff) === 1 ? "\r\n" : "\n";

        $hunks = [];
        $current = null;
        $oldLine = 0;
        $rawNew = [];
        $hunkHasKeptLine = false;
        $hunkKeptLineIsCrlf = false;

        $finalize = static function () use (&$current, &$rawNew, &$hunkHasKeptLine, &$hunkKeptLineIsCrlf, $fallbackEol): ?array {
            if ($current === null) {
                return null;
            }

            // Self-review correction (post-merge CI failure on #91): `newLines`
            // used to carry EVERY context/added line in the hunk, matching the
            // old WIDE range. Once hunkRange() narrows to changeFrom/
            // changeToExclusive only, replacement text built from the full
            // hunk duplicates the leading/trailing context lines that are now
            // OUTSIDE the range but still present on disk either side of it.
            // Every `+` line is genuine added content and always belongs in
            // the replacement; a ` ` (context) line belongs only when it sits
            // strictly between the first and last change (interior context --
            // e.g. two edits three lines apart, kept in the same hunk) rather
            // than being pure leading/trailing padding.
            $changeFrom = $current['changeFrom'];
            $changeToExclusive = $current['changeToExclusive'];
            $core = [];
            foreach ($rawNew as $entry) {
                if ($entry['isAdd']) {
                    $core[] = $entry['text'];
                    continue;
                }
                if (
                    $changeFrom !== null && $changeToExclusive !== null
                    && $entry['pos'] >= $changeFrom && $entry['pos'] < $changeToExclusive
                ) {
                    $core[] = $entry['text'];
                }
            }
            $current['newLines'] = $core;
            $current['eol'] = $hunkHasKeptLine ? ($hunkKeptLineIsCrlf ? "\r\n" : "\n") : $fallbackEol;

            return $current;
        };

        foreach (explode("\n", $diff) as $line) {
            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+\d+(?:,\d+)? @@/', $line, $m) === 1) {
                $finalized = $finalize();
                if ($finalized !== null) {
                    $hunks[] = $finalized;
                }
                $oldStart = (int) $m[1];
                $current = [
                    'oldStart' => $oldStart,
                    'oldCount' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1,
                    'newLines' => [],
                    'hasChange' => false,
                    'changeFrom' => null,
                    'changeToExclusive' => null,
                    'eol' => $fallbackEol,
                ];
                $rawNew = [];
                $oldLine = $oldStart;
                $hunkHasKeptLine = false;
                $hunkKeptLineIsCrlf = false;
                continue;
            }

            if ($current === null || $line === '') {
                continue;
            }

            $marker = $line[0] ?? '';
            if ($marker === '-') {
                $current['hasChange'] = true;
                $current['changeFrom'] ??= $oldLine;
                $oldLine++;
                $current['changeToExclusive'] = $oldLine;
                $hunkHasKeptLine = true;
                if (str_ends_with($line, "\r")) {
                    $hunkKeptLineIsCrlf = true;
                }
                continue;
            }
            if ($marker === '+') {
                $current['hasChange'] = true;
                $current['changeFrom'] ??= $oldLine;
                $current['changeToExclusive'] = max($current['changeToExclusive'] ?? $oldLine, $oldLine);
                // #160: strip a single trailing \r a CRLF source line still
                // carries (see the `eol` doc above) -- newLines stores bare
                // text, and hunkNewText() re-adds the detected `eol` itself,
                // so a kept trailing \r here would double up into \r\r\n.
                // Self-review (independent Explore review pass): `rtrim`
                // used to strip here, which removes EVERY trailing \r --
                // wrong for a line whose real content genuinely ends in one,
                // however rare. Only ever one \r to remove (this function's
                // own explode("\n", ...) leaves at most one), so strip
                // exactly that one instead.
                $text = substr($line, 1);
                if (str_ends_with($text, "\r")) {
                    $text = substr($text, 0, -1);
                }
                $rawNew[] = ['pos' => $oldLine, 'text' => $text, 'isAdd' => true];
                continue;
            }
            if ($marker === ' ') {
                $hunkHasKeptLine = true;
                if (str_ends_with($line, "\r")) {
                    $hunkKeptLineIsCrlf = true;
                }
                $text = substr($line, 1);
                if (str_ends_with($text, "\r")) {
                    $text = substr($text, 0, -1);
                }
                $rawNew[] = ['pos' => $oldLine, 'text' => $text, 'isAdd' => false];
                $oldLine++;
            }
        }

        $finalized = $finalize();
        if ($finalized !== null) {
            $hunks[] = $finalized;
        }

        return $hunks;
    }

    /**
     * @param list<array{rector: string, line?: int}> $changes
     * @param list<string> $appliedRectors
     * @return list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>
     */
    public static function buildFixes(string $diff, array $appliedRectors, array $changes): array
    {
        $hunks = self::parseHunks($diff);

        // #91.3: a hunk with no actual `+`/`-` content (a CRLF-only diff, or
        // any other hunk Rector reports where nothing really changed) has no
        // fix to offer -- publishing one produces a diagnostic AND a
        // quickfix that both do nothing when applied.
        $activeHunks = array_values(array_filter(
            $hunks,
            static fn (array $hunk): bool => $hunk['hasChange'],
        ));
        if ($activeHunks === []) {
            return [];
        }

        // #91.1: attribute each change to the hunk whose full (context-
        // included) old-line window contains it, or -- when none does, e.g.
        // an insertion reported one line past a hunk's own span -- to
        // whichever hunk's window is closest. This is what stops a change
        // from being broadcast to every hunk in the file: the file-wide
        // `$appliedRectors` fallback below now applies per-hunk, and only
        // when `$changes` is empty (Rector gave no per-change attribution at
        // all), never merely because THIS hunk's own window missed.
        $windows = array_map(
            static fn (array $hunk): array => [
                $hunk['oldStart'],
                $hunk['oldCount'] > 0 ? $hunk['oldStart'] + $hunk['oldCount'] - 1 : $hunk['oldStart'],
            ],
            $activeHunks,
        );

        $rectorsByHunk = array_fill(0, count($activeHunks), []);
        foreach ($changes as $change) {
            $line = $change['line'] ?? null;
            $rector = $change['rector'] ?? null;
            if ($line === null || $rector === null) {
                continue;
            }

            $bestIndex = null;
            $bestDistance = null;
            foreach ($windows as $index => [$from, $to]) {
                $distance = $line < $from ? ($from - $line) : ($line > $to ? ($line - $to) : 0);
                if ($bestDistance === null || $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex !== null) {
                $rectorsByHunk[$bestIndex][] = $rector;
            }
        }

        // #100: a hunk can genuinely have no per-change attribution (its
        // window matched no `changes[]` entry) while the file applied a
        // rule closest-hunk matching never landed on ANY hunk -- e.g. one
        // rule fires on two separate hunks but `changes[]` reports only one
        // line for it. When exactly one hunk is unattributed and exactly
        // one applied rule is unaccounted for anywhere, that pairing is
        // attributed. Two-or-more of either side stays generic: assigning a
        // rule to a hunk it cannot be proven to have produced would break
        // the "never attribute a rule that did not produce the hunk"
        // invariant the E2E already checks.
        //
        // Self-review note (independent Explore review pass): "exactly one
        // of each" narrows the ambiguity a lot but does not eliminate it in
        // every theoretical case -- a rule that itself produced TWO hunks
        // (with `changes[]` reporting only one of them) combined with a
        // second, unrelated rule that produced ZERO hunks of its own is
        // indistinguishable, from this function's own inputs alone, from
        // the genuinely-unambiguous one-hunk/one-rule case this guard is
        // for. Narrowing further (e.g. cross-checking against hunk COUNT
        // per rule, which Rector's JSON report does not currently expose
        // per-hunk) is a real follow-up -- tracked as #154, which pinned
        // this exact ambiguity as a test (see
        // RectorDiffParserTest::testBuildFixesPinsTheKnownAmbiguousLeftoverAttributionFromIssue154)
        // and, per #154's own resolution, accepted the residual
        // false-positive rate as a presentation-layer (diagnostic label)
        // risk rather than a correctness/security one -- narrowing further
        // needs data Rector's own report does not expose today.
        $attributed = [];
        foreach ($rectorsByHunk as $matched) {
            foreach ($matched as $rector) {
                $attributed[$rector] = true;
            }
        }
        $leftover = array_values(array_diff($appliedRectors, array_keys($attributed)));

        $unattributedCount = 0;
        foreach ($rectorsByHunk as $matched) {
            if ($matched === [] && $changes !== []) {
                $unattributedCount++;
            }
        }
        $useLeftover = $changes !== [] && count($leftover) === 1 && $unattributedCount === 1;

        $fixes = [];
        foreach ($activeHunks as $index => $hunk) {
            $matched = $rectorsByHunk[$index];
            if ($matched !== []) {
                $rectors = array_values(array_unique($matched));
            } elseif ($changes === []) {
                $rectors = $appliedRectors;
            } elseif ($useLeftover) {
                $rectors = $leftover;
            } elseif (count($appliedRectors) === 1) {
                // #100 reopen: a single rule can produce two (or more)
                // hunks while `changes[]` reports only one line for it --
                // closest-hunk matching then attributes that one line to
                // ONE hunk, which puts the rule into $attributed and takes
                // it out of $leftover, so the branch above never reaches
                // the other hunk. When the whole file applied exactly one
                // rule, there is no ambiguity to guard against: no other
                // rule exists that could have produced this hunk instead.
                $rectors = $appliedRectors;
            } else {
                $rectors = [];
            }

            $fixes[] = [
                'range' => self::hunkRange($hunk),
                'newText' => self::hunkNewText($hunk),
                'rectors' => $rectors,
            ];
        }

        return $fixes;
    }

    /**
     * @param array{oldStart: int, oldCount: int, newLines: list<string>, hasChange: bool, changeFrom: int|null, changeToExclusive: int|null} $hunk
     */
    private static function hunkRange(array $hunk): array
    {
        // #91.2: highlight only the lines the hunk's `-`/`+` content
        // actually touches, not the full hunk (which includes up to 3
        // context lines on each side by default). `changeFrom`/
        // `changeToExclusive` are only null when `hasChange` is false, and
        // buildFixes() never calls this for a no-op hunk -- the full-hunk
        // span below is kept as a defensive fallback for any other caller.
        if ($hunk['hasChange'] && $hunk['changeFrom'] !== null && $hunk['changeToExclusive'] !== null) {
            $start = $hunk['changeFrom'] - 1;
            $end = max($hunk['changeToExclusive'] - 1, $start);

            return [
                'start' => ['line' => $start, 'character' => 0],
                'end' => ['line' => $end, 'character' => 0],
            ];
        }

        $start = $hunk['oldCount'] > 0 ? $hunk['oldStart'] - 1 : $hunk['oldStart'];
        $end = $hunk['oldCount'] > 0 ? $hunk['oldStart'] - 1 + $hunk['oldCount'] : $hunk['oldStart'];

        return [
            'start' => ['line' => $start, 'character' => 0],
            'end' => ['line' => $end, 'character' => 0],
        ];
    }

    /**
     * @param array{oldStart: int, oldCount: int, newLines: list<string>, eol: string} $hunk
     */
    private static function hunkNewText(array $hunk): string
    {
        if ($hunk['newLines'] === []) {
            return '';
        }

        // #160: rejoin with the ORIGINAL file's own line-ending convention
        // (parseHunks()'s `eol`), not a hardcoded "\n" -- a CRLF source file
        // used to come back with the hunk's kept lines joined by a bare
        // "\n" regardless, producing a mixed-ending result once applied
        // against the rest of the (untouched, still-CRLF) file.
        return implode($hunk['eol'], $hunk['newLines']) . $hunk['eol'];
    }
}
