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
     * @return list<array{oldStart: int, oldCount: int, newLines: list<string>, hasChange: bool, changeFrom: int|null, changeToExclusive: int|null}>
     *   `hasChange` (#91.3) is false for a hunk whose body is entirely context
     *   lines -- Rector has been observed emitting exactly this for a
     *   line-ending-only (CRLF) difference. `changeFrom`/`changeToExclusive`
     *   (#91.2) are the 1-based OLD-file line span the hunk's own `-`/`+`
     *   lines actually touch, narrower than the full `oldStart..oldStart+
     *   oldCount-1` span whenever the hunk carries unified-diff context
     *   lines (3 on each side, by default) -- both null when `hasChange` is
     *   false.
     */
    public static function parseHunks(string $diff): array
    {
        $hunks = [];
        $current = null;
        $oldLine = 0;
        $rawNew = [];

        $finalize = static function () use (&$current, &$rawNew): ?array {
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
                ];
                $rawNew = [];
                $oldLine = $oldStart;
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
                continue;
            }
            if ($marker === '+') {
                $current['hasChange'] = true;
                $current['changeFrom'] ??= $oldLine;
                $current['changeToExclusive'] = max($current['changeToExclusive'] ?? $oldLine, $oldLine);
                $rawNew[] = ['pos' => $oldLine, 'text' => substr($line, 1), 'isAdd' => true];
                continue;
            }
            if ($marker === ' ') {
                $rawNew[] = ['pos' => $oldLine, 'text' => substr($line, 1), 'isAdd' => false];
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
        // per-hunk) is a real follow-up, tracked as a trap.d fragment
        // rather than attempted here.
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
     * @param array{oldStart: int, oldCount: int, newLines: list<string>} $hunk
     */
    private static function hunkNewText(array $hunk): string
    {
        if ($hunk['newLines'] === []) {
            return '';
        }

        return implode("\n", $hunk['newLines']) . "\n";
    }
}
