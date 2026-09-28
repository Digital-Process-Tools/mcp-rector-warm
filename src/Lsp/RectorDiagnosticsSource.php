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
            fwrite(STDERR, sprintf(
                "rector-warm-lsp: diagnostics failed for %s: %s\n",
                $absolutePath,
                $error ?? 'unknown error',
            ));

            return ['fixes' => []];
        }

        $report = self::extractReport($result['output'] ?? '');
        $fileDiffs = $report['file_diffs'] ?? [];
        if ($fileDiffs === []) {
            return ['fixes' => []];
        }

        $entry = $fileDiffs[0];

        return ['fixes' => RectorDiffParser::buildFixes(
            $entry['diff'] ?? '',
            $entry['applied_rectors'] ?? [],
            $entry['changes'] ?? [],
        )];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function extractReport(string $output): ?array
    {
        $pos = strpos($output, '{');
        while ($pos !== false) {
            $decoded = json_decode(substr($output, $pos), true);
            if (is_array($decoded) && array_key_exists('totals', $decoded)) {
                return $decoded;
            }
            $pos = strpos($output, '{', $pos + 1);
        }

        return null;
    }
}
