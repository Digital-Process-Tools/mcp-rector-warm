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
        // --debug disables parallel mode (required: workers can't be re-spawned from our bin).
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
