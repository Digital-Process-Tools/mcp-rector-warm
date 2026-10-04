<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\WorkspaceRootResolver;
use PHPUnit\Framework\TestCase;

/**
 * #107: pure logic, no process-wide chdir involved -- the thing
 * RootedDiagnosticsSourcePool and MultiRootDiagnosticsSource both lean on
 * to decide which of the server's known workspace roots a document
 * belongs to.
 */
final class WorkspaceRootResolverTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/mcp-rector-root-resolver-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0o700, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->base);
    }

    public function testFindsRectorPhpAtTheFolderItself(): void
    {
        $folder = $this->base . '/one';
        mkdir($folder . '/src', 0o700, true);
        touch($folder . '/rector.php');
        $file = $folder . '/src/Sample.php';
        touch($file);

        self::assertSame($folder, WorkspaceRootResolver::resolve($file, [$folder], $this->base));
    }

    public function testFindsComposerJsonWhenNoRectorPhpExists(): void
    {
        $folder = $this->base . '/one';
        mkdir($folder . '/src', 0o700, true);
        touch($folder . '/composer.json');
        $file = $folder . '/src/Sample.php';
        touch($file);

        self::assertSame($folder, WorkspaceRootResolver::resolve($file, [$folder], $this->base));
    }

    public function testFindsANestedRectorPhpCloserThanTheFolderRoot(): void
    {
        $folder = $this->base . '/one';
        mkdir($folder . '/packages/lib/src', 0o700, true);
        touch($folder . '/rector.php');
        touch($folder . '/packages/lib/rector.php');
        $file = $folder . '/packages/lib/src/Sample.php';
        touch($file);

        self::assertSame($folder . '/packages/lib', WorkspaceRootResolver::resolve($file, [$folder], $this->base));
    }

    public function testFallsBackToTheOwningFolderWhenNoMarkerExistsAnywhereUnderIt(): void
    {
        $folder = $this->base . '/one';
        mkdir($folder . '/src', 0o700, true);
        $file = $folder . '/src/Sample.php';
        touch($file);

        self::assertSame($folder, WorkspaceRootResolver::resolve($file, [$folder], $this->base));
    }

    public function testPicksTheLongestMatchingFolderAmongNestedOnes(): void
    {
        $outer = $this->base . '/outer';
        $inner = $this->base . '/outer/inner';
        mkdir($inner . '/src', 0o700, true);
        touch($inner . '/rector.php');
        $file = $inner . '/src/Sample.php';
        touch($file);

        // Both $outer and $inner are "known folders" that contain $file --
        // the longer (more specific) one must win, otherwise a document
        // inside a nested project resolves to the wrong rector.php.
        self::assertSame($inner, WorkspaceRootResolver::resolve($file, [$outer, $inner], $this->base));
    }

    public function testFallsBackToTheGivenFallbackWhenNoKnownFolderOwnsTheDocument(): void
    {
        $elsewhere = $this->base . '/elsewhere/src';
        mkdir($elsewhere, 0o700, true);
        $file = $elsewhere . '/Sample.php';
        touch($file);

        $unrelatedFolder = $this->base . '/unrelated';
        mkdir($unrelatedFolder, 0o700, true);

        self::assertSame($this->base, WorkspaceRootResolver::resolve($file, [$unrelatedFolder], $this->base));
    }

    public function testNeverWalksAboveTheOwningFolderEvenWhenAnAncestorHasAMarker(): void
    {
        // A rector.php sitting ABOVE the workspace folder (e.g. a monorepo
        // root the editor never opened) must never leak in -- otherwise
        // two different folders of the same monorepo could resolve to the
        // same root, defeating the whole point of #107.
        touch($this->base . '/rector.php');
        $folder = $this->base . '/one';
        mkdir($folder . '/src', 0o700, true);
        $file = $folder . '/src/Sample.php';
        touch($file);

        self::assertSame($folder, WorkspaceRootResolver::resolve($file, [$folder], $this->base));
    }

    /**
     * #107 self-review finding (oss:auditor pass, reasoned from POSIX
     * dirname() semantics -- no Windows runner to observe this on
     * directly): dirname('C:') is '.', NOT 'C:' again, unlike POSIX '/'
     * which IS its own dirname(). Pins that reaching a bare Windows drive
     * root terminates cleanly at the drive letter itself, never falling
     * through to a relative '.' that would read markers against this
     * TEST RUNNER's own cwd instead of the intended root. is_file() on
     * these paths is always false on a non-Windows runner either way --
     * this test is about where the walk terminates, not about finding a
     * real rector.php.
     */
    public function testReachingAWindowsDriveRootTerminatesThereRatherThanFallingThroughToARelativePath(): void
    {
        self::assertSame('C:', WorkspaceRootResolver::resolve('C:/Project/src/Sample.php', [], 'C:'));
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
