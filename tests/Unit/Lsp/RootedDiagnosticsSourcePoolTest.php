<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\BufferDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\EditDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\RootedDiagnosticsSourcePool;
use Dpt\McpRectorWarm\Lsp\WorkspaceDiagnosticsSource;
use PHPUnit\Framework\TestCase;

/**
 * #107: the pool's three jobs -- lazy creation (one factory call per root,
 * not per get()), a hard cap with least-recently-used eviction, and
 * chdir()ing to the resolved root on EVERY get(), not only the first.
 */
final class RootedDiagnosticsSourcePoolTest extends TestCase
{
    private string $cwdBackup;
    private string $base;

    protected function setUp(): void
    {
        $this->cwdBackup = getcwd() ?: '/';
        $this->base = sys_get_temp_dir() . '/mcp-rector-pool-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0o700, true);
    }

    protected function tearDown(): void
    {
        chdir($this->cwdBackup);
        self::removeTree($this->base);
    }

    public function testBuildsEachRootOnlyOnceOnRepeatedGet(): void
    {
        $root = $this->base . '/a';
        mkdir($root, 0o700, true);
        $builds = [];
        $pool = new RootedDiagnosticsSourcePool(
            static function (string $r) use (&$builds): BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource {
                $builds[] = $r;

                return self::fakeSource();
            },
        );

        $first = $pool->get($root);
        $second = $pool->get($root);

        self::assertSame($first, $second);
        self::assertSame([$root], $builds);
    }

    public function testChdirsToTheResolvedRootOnEveryGetEvenWhenAlreadyPooled(): void
    {
        $rootA = $this->base . '/a';
        $rootB = $this->base . '/b';
        mkdir($rootA, 0o700, true);
        mkdir($rootB, 0o700, true);
        $pool = new RootedDiagnosticsSourcePool(static fn(string $r): BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource => self::fakeSource());

        $pool->get($rootA);
        self::assertSame(realpath($rootA), realpath((string) getcwd()));

        $pool->get($rootB);
        self::assertSame(realpath($rootB), realpath((string) getcwd()));

        // Re-fetching the already-pooled $rootA must move cwd back, not
        // leave it on $rootB -- the exact bug a "chdir only on first boot"
        // implementation would have.
        $pool->get($rootA);
        self::assertSame(realpath($rootA), realpath((string) getcwd()));
    }

    public function testEvictsTheLeastRecentlyUsedRootPastTheCap(): void
    {
        $roots = [];
        for ($i = 0; $i < 3; $i++) {
            $roots[] = $root = $this->base . '/r' . $i;
            mkdir($root, 0o700, true);
        }

        $pool = new RootedDiagnosticsSourcePool(static fn(string $r): BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource => self::fakeSource(), cap: 2);

        $pool->get($roots[0]);
        $pool->get($roots[1]);
        // r0 is now the least-recently-used of the two pooled roots.
        $pool->get($roots[2]);

        self::assertSame([$roots[1], $roots[2]], $pool->activeRoots());
    }

    public function testGettingAnAlreadyPooledRootCountsAsUseForEvictionOrdering(): void
    {
        $roots = [];
        for ($i = 0; $i < 3; $i++) {
            $roots[] = $root = $this->base . '/r' . $i;
            mkdir($root, 0o700, true);
        }

        $pool = new RootedDiagnosticsSourcePool(static fn(string $r): BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource => self::fakeSource(), cap: 2);

        $pool->get($roots[0]);
        $pool->get($roots[1]);
        // Touching r0 again makes r1 the least-recently-used one.
        $pool->get($roots[0]);
        $pool->get($roots[2]);

        self::assertSame([$roots[0], $roots[2]], $pool->activeRoots());
    }

    private static function fakeSource(): BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource
    {
        return new class implements BufferDiagnosticsSource, WorkspaceDiagnosticsSource, EditDiagnosticsSource {
            public function diagnose(string $absolutePath): array
            {
                return ['fixes' => []];
            }

            public function diagnoseBuffer(string $absolutePath, string $content): array
            {
                return ['fixes' => []];
            }

            public function diagnoseForEdit(string $absolutePath): array
            {
                return ['fixes' => []];
            }

            public function diagnoseBufferForEdit(string $absolutePath, string $content): array
            {
                return ['fixes' => []];
            }

            public function diagnoseWorkspace(string $rootPath): array
            {
                return ['files' => [], 'errors' => []];
            }
        };
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
