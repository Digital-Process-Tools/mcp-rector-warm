<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\BufferDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\EditDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\MultiRootDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\RootedDiagnosticsSourcePool;
use Dpt\McpRectorWarm\Lsp\WorkspaceDiagnosticsSource;
use PHPUnit\Framework\TestCase;

/**
 * #107: the drop-in LspServer actually talks to. Every diagnose*() call
 * must route to the pooled source for the root WorkspaceRootResolver picks,
 * --working-dir must override that resolution unconditionally, and
 * setWorkspaceFolders()/changeWorkspaceFolders() must actually change which
 * root a later call resolves to.
 */
final class MultiRootDiagnosticsSourceTest extends TestCase
{
    private string $cwdBackup;
    private string $base;

    protected function setUp(): void
    {
        $this->cwdBackup = getcwd() ?: '/';
        $this->base = sys_get_temp_dir() . '/mcp-rector-multiroot-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0o700, true);
    }

    protected function tearDown(): void
    {
        chdir($this->cwdBackup);
        self::removeTree($this->base);
    }

    public function testDiagnoseRoutesToTheRootOwningTheDocument(): void
    {
        [$folderA, $folderB] = $this->twoFolders();

        /** @var array<string, int> $calls */
        $calls = [];
        $pool = new RootedDiagnosticsSourcePool(
            static function (string $root) use (&$calls): BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource {
                $calls[$root] = ($calls[$root] ?? 0) + 1;

                return self::recordingSource($root);
            },
        );
        $source = new MultiRootDiagnosticsSource($pool, fallbackRoot: $this->base);

        $resultA = $source->diagnose($folderA . '/src/Sample.php');
        $resultB = $source->diagnose($folderB . '/src/Sample.php');

        self::assertSame($folderA, $resultA['fixes'][0]['newText']);
        self::assertSame($folderB, $resultB['fixes'][0]['newText']);
        self::assertSame([$folderA => 1, $folderB => 1], $calls);
    }

    public function testWorkingDirOverrideWinsOverEveryWorkspaceFolder(): void
    {
        [$folderA, $folderB] = $this->twoFolders();

        $pool = new RootedDiagnosticsSourcePool(static fn(string $root): BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource => self::recordingSource($root));
        $source = new MultiRootDiagnosticsSource($pool, workingDirOverride: $folderA, fallbackRoot: $this->base);
        $source->setWorkspaceFolders([$folderA, $folderB]);

        // A document physically inside $folderB still resolves to the
        // override, exactly #107's own "--working-dir stays an override".
        $result = $source->diagnose($folderB . '/src/Sample.php');

        self::assertSame($folderA, $result['fixes'][0]['newText']);
    }

    public function testChangeWorkspaceFoldersAddsAndRemovesLiveRoots(): void
    {
        // $folderB deliberately has no rector.php/composer.json of its own
        // -- the only way MultiRootDiagnosticsSource can root a document
        // there at all is by knowing $folderB IS a workspace folder (so
        // the resolver's "no marker found: fall back to the owning
        // folder" branch returns $folderB itself, never $fallbackRoot).
        $folderA = $this->base . '/a';
        $folderB = $this->base . '/b';
        mkdir($folderA . '/src', 0o700, true);
        mkdir($folderB . '/src', 0o700, true);
        touch($folderA . '/rector.php');
        touch($folderA . '/src/Sample.php');
        touch($folderB . '/src/Sample.php');

        $pool = new RootedDiagnosticsSourcePool(static fn(string $root): BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource => self::recordingSource($root));
        $source = new MultiRootDiagnosticsSource($pool, fallbackRoot: $this->base);
        $source->setWorkspaceFolders([$folderA]);

        // $folderB is not yet known: the owning-folder search finds
        // nothing, so the walk-up reaches $fallbackRoot with no marker
        // found anywhere along the way.
        $beforeAdd = $source->diagnose($folderB . '/src/Sample.php');
        self::assertSame($this->base, $beforeAdd['fixes'][0]['newText']);

        $source->changeWorkspaceFolders(added: [$folderB], removed: []);
        $afterAdd = $source->diagnose($folderB . '/src/Sample.php');
        self::assertSame($folderB, $afterAdd['fixes'][0]['newText']);

        $source->changeWorkspaceFolders(added: [], removed: [$folderB]);
        $afterRemove = $source->diagnose($folderB . '/src/Sample.php');
        self::assertSame($this->base, $afterRemove['fixes'][0]['newText']);
    }

    /** @return list{string, string} */
    private function twoFolders(): array
    {
        $folderA = $this->base . '/a';
        $folderB = $this->base . '/b';
        mkdir($folderA . '/src', 0o700, true);
        mkdir($folderB . '/src', 0o700, true);
        touch($folderA . '/rector.php');
        touch($folderB . '/rector.php');
        touch($folderA . '/src/Sample.php');
        touch($folderB . '/src/Sample.php');

        return [$folderA, $folderB];
    }

    /**
     * A fake whose every diagnose*() call reports, as its one "fix", which
     * $root it was built for -- so a test can assert routing without a real
     * RectorTool.
     */
    private static function recordingSource(string $root): BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource
    {
        return new class ($root) implements BufferDiagnosticsSource, WorkspaceDiagnosticsSource, EditDiagnosticsSource {
            public function __construct(private readonly string $root) {}

            public function diagnose(string $absolutePath): array
            {
                return ['fixes' => [['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]], 'newText' => $this->root, 'rectors' => []]]];
            }

            public function diagnoseBuffer(string $absolutePath, string $content): array
            {
                return $this->diagnose($absolutePath);
            }

            public function diagnoseForEdit(string $absolutePath): array
            {
                return $this->diagnose($absolutePath);
            }

            public function diagnoseBufferForEdit(string $absolutePath, string $content): array
            {
                return $this->diagnose($absolutePath);
            }

            public function diagnoseWorkspace(string $rootPath): array
            {
                return ['files' => [$rootPath => $this->diagnose($rootPath)['fixes']], 'errors' => []];
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
