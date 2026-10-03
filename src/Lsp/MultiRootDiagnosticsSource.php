<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

/**
 * #107: the single $diagnostics LspServer talks to when multi-root support
 * is wired in -- every existing call site in LspServer stays exactly as it
 * is (diagnose($path), diagnoseForEdit($path), ...); this is what decides,
 * per call, which of the server's resolved workspace roots $path actually
 * belongs to, and delegates to that root's own pooled RectorDiagnosticsSource.
 *
 * $workingDirOverride, when given (the existing --working-dir flag), wins
 * over every workspaceFolders-based resolution unconditionally -- #107's own
 * requirement that the flag "stays as an override". $fallbackRoot is what a
 * client that never sends workspaceFolders at all (or any document outside
 * every known folder) resolves to: the server's own boot directory, which is
 * exactly today's single-root behaviour whenever multi-root support is
 * simply never exercised.
 */
final class MultiRootDiagnosticsSource implements
    DiagnosticsSource,
    BufferDiagnosticsSource,
    WorkspaceDiagnosticsSource,
    EditDiagnosticsSource,
    WorkspaceAwareDiagnosticsSource
{
    /** @var list<string> */
    private array $folders = [];

    public function __construct(
        private readonly RootedDiagnosticsSourcePool $pool,
        private readonly ?string $workingDirOverride = null,
        private readonly string $fallbackRoot = '.',
    ) {}

    public function setWorkspaceFolders(array $folderPaths): void
    {
        $this->folders = \array_values(\array_unique($folderPaths));
    }

    public function changeWorkspaceFolders(array $added, array $removed): void
    {
        if ($removed !== []) {
            $removedSet = \array_fill_keys($removed, true);
            $this->folders = \array_values(\array_filter(
                $this->folders,
                static fn(string $folder): bool => !isset($removedSet[$folder]),
            ));
        }

        foreach ($added as $folder) {
            if (!\in_array($folder, $this->folders, true)) {
                $this->folders[] = $folder;
            }
        }
    }

    /** @return list<string> test/diagnostic seam */
    public function workspaceFolders(): array
    {
        return $this->folders;
    }

    public function diagnose(string $absolutePath): array
    {
        return $this->sourceFor($absolutePath)->diagnose($absolutePath);
    }

    public function diagnoseForEdit(string $absolutePath): array
    {
        return $this->sourceFor($absolutePath)->diagnoseForEdit($absolutePath);
    }

    public function diagnoseBuffer(string $absolutePath, string $content): array
    {
        return $this->sourceFor($absolutePath)->diagnoseBuffer($absolutePath, $content);
    }

    public function diagnoseBufferForEdit(string $absolutePath, string $content): array
    {
        return $this->sourceFor($absolutePath)->diagnoseBufferForEdit($absolutePath, $content);
    }

    public function diagnoseWorkspace(string $rootPath): array
    {
        $root = $this->resolveRoot($rootPath);

        return $this->pool->get($root)->diagnoseWorkspace($root);
    }

    private function sourceFor(string $absolutePath): BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource
    {
        return $this->pool->get($this->resolveRoot($absolutePath));
    }

    private function resolveRoot(string $path): string
    {
        if ($this->workingDirOverride !== null) {
            return $this->workingDirOverride;
        }

        return WorkspaceRootResolver::resolve($path, $this->folders, $this->fallbackRoot);
    }
}
