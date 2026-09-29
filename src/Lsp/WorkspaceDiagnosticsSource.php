<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

/**
 * #102: what LspServer needs to turn a whole rooted tree into a set of
 * per-file fixes for `workspace/executeCommand` (`rector-warm.fixWorkspace`)
 * -- the same warm RectorTool::process() dry run DiagnosticsSource already
 * uses for one file (RectorDiagnosticsSource::diagnose()), generalized to
 * however many files Rector's own `file_diffs` names for $rootPath.
 *
 * Kept as its own interface rather than folded into DiagnosticsSource so
 * every existing DiagnosticsSource fake in the test suite keeps compiling
 * unchanged -- only a source that opts in by ALSO implementing this one
 * gains the workspace-fix command; LspServer advertises
 * `executeCommandProvider` only when its $diagnostics does.
 */
interface WorkspaceDiagnosticsSource
{
    /**
     * @return array{
     *   files: array<string, list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>>,
     *   errors: list<array{message: string, line: int}>,
     * }
     *   `files` keys are absolute paths -- Rector's own `file_diffs[].file`
     *   entries, resolved against $rootPath when Rector reports them
     *   relative -- each holding the same fix shape DiagnosticsSource::
     *   diagnose() returns for one file. A file Rector touched but left with
     *   no active hunk (see RectorDiffParser::buildFixes()'s #91.3 filter)
     *   is simply absent from `files`, exactly like `diagnose()`'s own empty
     *   `fixes` case.
     */
    public function diagnoseWorkspace(string $rootPath): array;
}
