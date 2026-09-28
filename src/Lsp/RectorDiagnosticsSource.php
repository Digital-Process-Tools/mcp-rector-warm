<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

use Dpt\McpRectorWarm\RectorTool;
use Mcp\Schema\Result\CallToolResult;

/**
 * The real DiagnosticsSource: runs the same warm RectorTool::process() the
 * MCP tool uses (dry-run), on the single file the LSP asks about, and turns
 * its `file_diffs` entry into fixes via RectorDiffParser.
 */
final class RectorDiagnosticsSource implements DiagnosticsSource
{
    public function __construct(private readonly RectorTool $tool)
    {
    }

    public function diagnose(string $absolutePath): array
    {
        $result = $this->tool->process($absolutePath, true);

        if ($result instanceof CallToolResult) {
            $error = is_array($result->structuredContent) ? ($result->structuredContent['error'] ?? null) : null;
            $message = is_string($error) && $error !== '' ? $error : 'unknown error';
            fwrite(STDERR, sprintf(
                "rector-warm-lsp: diagnostics failed for %s: %s\n",
                $absolutePath,
                $message,
            ));

            // #90: a refused call (out-of-root path, SecurityError) used to
            // come back looking identical to "nothing to report" -- the
            // stderr line above was the only trace. Surface it as an error
            // diagnostic too, since stderr is not where an editor looks.
            return ['fixes' => [], 'errors' => [['message' => $message, 'line' => 0]]];
        }

        $report = self::extractReport($result['output'] ?? '');
        if ($report === null) {
            // Self-review finding on #90 (independent auditor pass): a
            // successful call (no CallToolResult refusal) whose output never
            // contains a parseable `{"totals":...}` report -- truncated
            // output, a decode failure, a lost boot-handshake byte (see #73,
            // #74) -- used to null-coalesce straight through to `[]` for
            // BOTH `errors` and `file_diffs`, the exact same "looks clean"
            // shape #90 exists to close, just for a third channel.
            return ['fixes' => [], 'errors' => [[
                'message' => 'rector_process succeeded but produced no parseable report.',
                'line' => 0,
            ]]];
        }

        $errors = self::buildErrors($report['errors'] ?? []);
        $fileDiffs = $report['file_diffs'] ?? [];
        if ($fileDiffs === []) {
            return ['fixes' => [], 'errors' => $errors];
        }

        $entry = $fileDiffs[0];

        return [
            'fixes' => RectorDiffParser::buildFixes(
                $entry['diff'] ?? '',
                $entry['applied_rectors'] ?? [],
                $entry['changes'] ?? [],
            ),
            'errors' => $errors,
        ];
    }

    /**
     * #90: `report['errors']` (a syntax error, or any other per-file Rector
     * failure) used to be read nowhere at all -- a file that goes from
     * "1 fixable diagnostic" to "does not even parse" dropped to zero
     * diagnostics, the same silent-clean shape as the CallToolResult branch
     * above. Tolerant of both the documented shape (`{"message":...,
     * "line":...}`) and a bare string entry, since nothing upstream pins
     * Rector's own error-entry shape across versions.
     *
     * @param mixed $rawErrors
     * @return list<array{message: string, line: int}>
     */
    private static function buildErrors(mixed $rawErrors): array
    {
        if (!is_array($rawErrors)) {
            return [];
        }

        $errors = [];
        foreach ($rawErrors as $raw) {
            if (is_array($raw)) {
                $message = is_string($raw['message'] ?? null) ? $raw['message'] : 'Rector reported an error.';
                $line = is_int($raw['line'] ?? null) ? $raw['line'] : 0;
            } elseif (is_string($raw)) {
                $message = $raw;
                $line = 0;
            } else {
                continue;
            }

            $errors[] = ['message' => $message, 'line' => $line];
        }

        return $errors;
    }

    /**
     * Rector's own JSON report out of raw process output, skipping any text
     * BEFORE it (e.g. a no-config warning) and tolerating any text AFTER it
     * too (a self-review finding on #53: decoding `substr($output, $pos)`
     * whole -- to the end of the string -- fails on ANY trailing byte after
     * the closing `}`, e.g. a PHP deprecation notice a future Rector/PHP
     * version prints after its JSON, and fails SILENTLY here, since a null
     * report reads identically to "0 changes"). This scans for the matching
     * closing brace instead, so only the JSON object itself is decoded.
     *
     * @return array<string, mixed>|null
     */
    private static function extractReport(string $output): ?array
    {
        for ($pos = strpos($output, '{'); $pos !== false; $pos = strpos($output, '{', $pos + 1)) {
            $end = self::matchingBraceEnd($output, $pos);
            if ($end === null) {
                continue;
            }

            $decoded = json_decode(substr($output, $pos, $end - $pos + 1), true);
            if (is_array($decoded) && array_key_exists('totals', $decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * The index of the `}` that closes the `{` at $start, respecting JSON
     * string literals (so a brace inside a quoted string, e.g. a rule's diff
     * text, is never mistaken for structure) -- null if $output ends before
     * the brace at $start closes.
     */
    private static function matchingBraceEnd(string $output, int $start): ?int
    {
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start, $len = strlen($output); $i < $len; $i++) {
            $char = $output[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
