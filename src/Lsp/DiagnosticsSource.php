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
     * @return array{
     *   fixes: list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>,
     *   errors?: list<array{message: string, line: int}>,
     * }
     *   An empty `fixes` list means "no fixable hunks for this file" -- true
     *   both for a file Rector does not change AND for a failed Rector call.
     *   `errors` (#90) is what actually reports the failure: a syntax error,
     *   an out-of-root refusal, or any other Rector/tool-level error, each as
     *   a message plus a 1-based line (0 when none applies). An empty or
     *   absent `errors` list means the call succeeded with nothing to
     *   report -- the real implementation still logs every failure to
     *   stderr too, but `errors` is what reaches the editor.
     */
    public function diagnose(string $absolutePath): array;
}
