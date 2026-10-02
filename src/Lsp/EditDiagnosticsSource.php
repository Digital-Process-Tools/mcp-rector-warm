<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

/**
 * #216: a DiagnosticsSource whose dry run can be recomputed with the warm
 * session forced off, for a call whose result is about to be turned into a
 * WorkspaceEdit (an LSP code action, or `rector-warm.fixWorkspace`) rather
 * than merely displayed as a diagnostic. A session can go stale on an input
 * a custom rule reads itself and keeps in a static (docs/how-it-works.md
 * §1b); that is an acceptable limit for a diagnostic a cold CI run will
 * still catch, but never for bytes this server writes into the user's file.
 *
 * Kept as its own interface, same reason as WorkspaceDiagnosticsSource (see
 * its own docblock): every existing DiagnosticsSource/BufferDiagnosticsSource
 * fake in the test suite keeps compiling unchanged. Only a source that opts
 * in by ALSO implementing this one gets its code-action edits recomputed
 * before they are applied -- LspServer falls back to its cached (possibly
 * session-served) fixes when $diagnostics does not implement it, which is
 * correct for every test double (none of them has a session to be stale)
 * and for a future source that never has one either.
 */
interface EditDiagnosticsSource
{
    /**
     * Same result shape and contract as DiagnosticsSource::diagnose(), computed with the session forced off.
     *
     * @return array{
     *   fixes: list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>,
     *   errors?: list<array{message: string, line: int, file?: string}>,
     * }
     */
    public function diagnoseForEdit(string $absolutePath): array;

    /**
     * Same result shape and contract as BufferDiagnosticsSource::diagnoseBuffer(), computed with the session forced off.
     *
     * @return array{
     *   fixes: list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>,
     *   errors?: list<array{message: string, line: int, file?: string}>,
     * }
     */
    public function diagnoseBufferForEdit(string $absolutePath, string $content): array;
}
