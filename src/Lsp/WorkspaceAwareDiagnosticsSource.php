<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

/**
 * #107: a DiagnosticsSource that knows about the client's workspace
 * folders -- LspServer calls setWorkspaceFolders() once from `initialize`
 * and changeWorkspaceFolders() on every `workspace/didChangeWorkspaceFolders`
 * notification. Kept as its own interface, same reason as
 * WorkspaceDiagnosticsSource and EditDiagnosticsSource (see their own
 * docblocks): every existing DiagnosticsSource fake in the test suite keeps
 * compiling unchanged, and a source with no use for multi-root (every fake,
 * and a RectorDiagnosticsSource used directly, single-root) simply never
 * implements this.
 */
interface WorkspaceAwareDiagnosticsSource
{
    /** @param list<string> $folderPaths absolute filesystem paths */
    public function setWorkspaceFolders(array $folderPaths): void;

    /**
     * @param list<string> $added
     * @param list<string> $removed
     */
    public function changeWorkspaceFolders(array $added, array $removed): void;
}
