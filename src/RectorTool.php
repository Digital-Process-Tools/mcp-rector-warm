<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm;

use Mcp\Capability\Attribute\McpTool;

final class RectorTool
{
    private RectorRunner $runner;

    public function __construct(?RectorRunner $runner = null)
    {
        $this->runner = $runner ?? new RectorRunner();
    }

    /**
     * Run Rector on a path. Config + working dir are pinned at server startup (--working-dir, --config flags).
     *
     * @param string $path Absolute path to file or directory under the server's working dir
     * @param bool $dryRun true = preview changes only (default), false = apply
     * @return array{exit_code: int, output: string, warm_boot: bool, error?: string, error_class?: string, trace?: string}
     */
    #[McpTool(name: 'rector_process', description: 'Run Rector refactoring on a path. Server-pinned config.')]
    public function process(string $path, bool $dryRun = true): array
    {
        // Containment: rector reads (dry-run) or rewrites (non-dry) PHP files at
        // $path. Reject paths outside realpath(cwd) — set at boot via --working-dir.
        // Prevents a hostile MCP caller from triggering refactor writes or content
        // disclosure on arbitrary files (e.g. ~/projects/*.php, /etc/php/*.php).
        $cwd = realpath(getcwd() ?: '.');
        $real = realpath($path);
        if ($cwd === false || $real === false || ($real !== $cwd && !str_starts_with($real, $cwd . DIRECTORY_SEPARATOR))) {
            return [
                'exit_code'   => -1,
                'output'      => '',
                'warm_boot'   => $this->runner->isWarm(),
                'error'       => 'rector_process: path is outside the configured working directory.',
                'error_class' => 'SecurityError',
                'trace'       => '',
            ];
        }

        // --debug disables parallel mode + suppresses file_diffs in JSON output.
        // We keep it for speed: parallel mode on 1 file is 14s overhead because rector
        // still scans all configured paths at boot. Single-thread bypasses the worker
        // dance entirely. Consumers can filter the bland "would refactor" message client-side.
        $argv = ['rector', 'process', '--output-format=json', '--debug', '--no-progress-bar'];
        if ($dryRun) {
            $argv[] = '--dry-run';
        }
        $argv[] = $path;

        try {
            return $this->runner->run($argv);
        } catch (\Throwable $e) {
            return [
                'exit_code' => -1,
                'output' => '',
                'warm_boot' => $this->runner->isWarm(),
                'error' => $e->getMessage(),
                'error_class' => $e::class,
                'trace' => $e->getTraceAsString(),
            ];
        }
    }
}
