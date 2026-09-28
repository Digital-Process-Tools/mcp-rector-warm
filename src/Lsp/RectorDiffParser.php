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
     * @return list<array{oldStart: int, oldCount: int, newLines: list<string>}>
     */
    public static function parseHunks(string $diff): array
    {
        $hunks = [];
        $current = null;

        foreach (explode("\n", $diff) as $line) {
            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+\d+(?:,\d+)? @@/', $line, $m) === 1) {
                if ($current !== null) {
                    $hunks[] = $current;
                }
                $current = [
                    'oldStart' => (int) $m[1],
                    'oldCount' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1,
                    'newLines' => [],
                ];
                continue;
            }

            if ($current === null || $line === '') {
                continue;
            }

            $marker = $line[0] ?? '';
            if ($marker === '-') {
                continue;
            }
            if ($marker === '+' || $marker === ' ') {
                $current['newLines'][] = substr($line, 1);
            }
        }

        if ($current !== null) {
            $hunks[] = $current;
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
        $fixes = [];

        foreach (self::parseHunks($diff) as $hunk) {
            $lineFrom = $hunk['oldStart'];
            $lineTo = $hunk['oldCount'] > 0 ? $hunk['oldStart'] + $hunk['oldCount'] - 1 : $hunk['oldStart'];

            $rectors = [];
            foreach ($changes as $change) {
                $line = $change['line'] ?? null;
                if ($line !== null && $line >= $lineFrom && $line <= $lineTo) {
                    $rectors[] = $change['rector'];
                }
            }
            $rectors = $rectors !== [] ? array_values(array_unique($rectors)) : $appliedRectors;

            $fixes[] = [
                'range' => self::hunkRange($hunk),
                'newText' => self::hunkNewText($hunk),
                'rectors' => $rectors,
            ];
        }

        return $fixes;
    }

    /**
     * @param array{oldStart: int, oldCount: int, newLines: list<string>} $hunk
     */
    private static function hunkRange(array $hunk): array
    {
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
