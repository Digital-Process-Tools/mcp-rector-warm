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
final class RectorDiagnosticsSource implements BufferDiagnosticsSource
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
     * #106: Rector only reads files, so an unsaved buffer is written to a
     * temp copy and Rector runs on that. Where the copy lives is what keeps
     * the result equal to a cold run on the original:
     *
     * - inside the project (a hidden `.rector-warm-<pid>` directory in the
     *   original's own directory), so autoload, rector.php discovery and
     *   RectorTool's path containment all apply exactly as for the original;
     * - under the original's own basename, so Rector's path-based skips
     *   (`withSkip([...])` globs on the basename such as `*Test.php` or a
     *   pattern ending in `/Foo.php`, a skipped directory, a rule skipped
     *   for such a glob) match the copy as they match the original. Checked
     *   against rector/rector 2.x: all of those hold. The one that cannot
     *   hold is a skip naming the original's EXACT path
     *   (`__DIR__ . '/src/Foo.php'`) -- the copy's path differs, so such a
     *   file IS diagnosed while unsaved. Documented in the README.
     *
     * The copy (and its directory) is removed in a `finally`, so a Rector
     * error, a refused call or a throwing runner leaves nothing behind.
     */
    public function diagnoseBuffer(string $absolutePath, string $content): array
    {
        $directory = dirname($absolutePath);
        $realDirectory = realpath($directory);
        $cwd = realpath(getcwd() ?: '.');

        // Checked BEFORE anything is written: RectorTool refuses an
        // out-of-root path too, but only after the temp copy would already
        // exist outside the project.
        if ($realDirectory === false || $cwd === false || !self::isWithinRoot($realDirectory, $cwd)) {
            return self::failure('rector_process: path is outside the configured working directory.');
        }

        $tempDirectory = $directory . DIRECTORY_SEPARATOR . self::tempDirectoryName();
        $tempPath = $tempDirectory . DIRECTORY_SEPARATOR . basename($absolutePath);

        try {
            if (!is_dir($tempDirectory) && !@mkdir($tempDirectory, 0o700) && !is_dir($tempDirectory)) {
                return self::failure(sprintf('rector-warm-lsp: could not create a temp directory in %s', $directory));
            }

            if (@file_put_contents($tempPath, $content) !== strlen($content)) {
                return self::failure(sprintf('rector-warm-lsp: could not write the unsaved buffer to a temp file in %s', $directory));
            }

            $result = $this->diagnose($tempPath);
        } catch (\Throwable $e) {
            $result = self::failure($e->getMessage());
        } finally {
            @unlink($tempPath);
            @rmdir($tempDirectory);
        }

        foreach ($result['errors'] ?? [] as $i => $error) {
            $result['errors'][$i]['message'] = self::withoutTempDirectory($error['message']);
        }

        return $result;
    }

    private static function tempDirectoryName(): string
    {
        return '.rector-warm-' . getmypid();
    }

    /**
     * Rector's messages name the file it processed -- the temp copy, in
     * absolute or project-relative form. Dropping the temp directory segment
     * turns either form back into the original's path.
     */
    private static function withoutTempDirectory(string $message): string
    {
        $segment = self::tempDirectoryName();

        return str_replace(['/' . $segment . '/', '\\' . $segment . '\\'], ['/', '\\'], $message);
    }

    /**
     * @return array{fixes: list<never>, errors: list<array{message: string, line: int}>}
     */
    private static function failure(string $message): array
    {
        return ['fixes' => [], 'errors' => [['message' => $message, 'line' => 0]]];
    }

    /**
     * Same rule as RectorTool's own containment check (#99: case-insensitive
     * on Windows, where realpath() does not normalise case).
     */
    private static function isWithinRoot(string $real, string $root): bool
    {
        $caseInsensitive = PHP_OS_FAMILY === 'Windows';
        $root = rtrim($root, '/\\');

        if ($caseInsensitive ? strcasecmp($real, $root) === 0 : $real === $root) {
            return true;
        }

        $prefix = $root . DIRECTORY_SEPARATOR;

        return $caseInsensitive
            ? strncasecmp($real, $prefix, strlen($prefix)) === 0
            : str_starts_with($real, $prefix);
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
