<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

/**
 * #107: one warm RectorDiagnosticsSource per resolved workspace root,
 * created lazily on first use and capped -- past the cap, the
 * least-recently-used root is dropped, which is also how its warm worker is
 * torn down: RectorRunner::__destruct() kills the forked worker process the
 * moment nothing holds a reference to the RectorTool that owns it, so
 * eviction here needs no explicit shutdown call of its own.
 *
 * Every get() call chdir()s to $root before returning -- not only on first
 * boot. RectorTool::processWith()'s containment check (and RectorRunner's
 * own project-root resolution) both read getcwd() at CALL time, not at boot
 * time, so a root that was last used for a different one of the server's
 * roots in between still needs the chdir repeated here on every reuse, not
 * merely once per root.
 */
final class RootedDiagnosticsSourcePool
{
    public const DEFAULT_CAP = 4;

    /** @var array<string, BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource> keyed by root, oldest-used first */
    private array $sources = [];

    /**
     * @param \Closure(string): (BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource) $factory
     */
    public function __construct(
        private readonly \Closure $factory,
        private readonly int $cap = self::DEFAULT_CAP,
    ) {}

    public function get(string $root): BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource
    {
        if (isset($this->sources[$root])) {
            $source = $this->sources[$root];
            unset($this->sources[$root]);
            $this->sources[$root] = $source;
        } else {
            $this->sources[$root] = ($this->factory)($root);

            if (\count($this->sources) > $this->cap) {
                $lruRoot = \array_key_first($this->sources);
                if ($lruRoot !== $root) {
                    unset($this->sources[$lruRoot]);
                }
            }
        }

        \chdir($root);

        return $this->sources[$root];
    }

    /** @return list<string> currently-pooled roots, oldest-used first. Test seam. */
    public function activeRoots(): array
    {
        return \array_keys($this->sources);
    }
}
