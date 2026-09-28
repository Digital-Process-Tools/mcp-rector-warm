<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

/**
 * What LspServer needs to turn a file on disk into fixes -- extracted so
 * LspServer's own unit tests can drive it with a fake, the same seam
 * RunnerInterface gives RectorTool (see RectorTool::withRunner()).
 */
interface DiagnosticsSource
{
    /**
     * @return array{fixes: list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>}
     *   An empty `fixes` list means "no diagnostics for this file" -- true
     *   both for a file Rector does not change AND for a failed Rector call
     *   (logged to stderr by the real implementation; #53's v1 scope has no
     *   diagnostic-channel error reporting yet).
     */
    public function diagnose(string $absolutePath): array;
}
