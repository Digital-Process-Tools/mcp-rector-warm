<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

/**
 * #106: a DiagnosticsSource that can also diagnose an editor's unsaved
 * buffer -- content that is not (yet) what is on disk at $absolutePath.
 * LspServer only advertises full text sync (`textDocumentSync.change: 1`)
 * when its source implements this; a plain DiagnosticsSource keeps the
 * disk-only behaviour.
 */
interface BufferDiagnosticsSource extends DiagnosticsSource
{
    /**
     * Same result shape as diagnose(), computed as if the file at
     * $absolutePath held $content. Must never modify $absolutePath itself,
     * and must never leave anything behind in the project, on success or on
     * failure. Error messages refer to $absolutePath, not to any temp copy.
     *
     * @return array{
     *   fixes: list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>,
     *   errors?: list<array{message: string, line: int, file?: string}>,
     * }
     */
    public function diagnoseBuffer(string $absolutePath, string $content): array;
}
