<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Integration;

use Dpt\McpRectorWarm\Lsp\BufferDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\EditDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\MultiRootDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\RectorDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\RootedDiagnosticsSourcePool;
use Dpt\McpRectorWarm\Lsp\WorkspaceDiagnosticsSource;
use Dpt\McpRectorWarm\RectorTool;
use PHPUnit\Framework\TestCase;

/**
 * #107's own acceptance bar: "two folders with different rector.php configs
 * each get diagnostics matching their own cold run" -- this is the warm
 * half of that, with a REAL RectorTool/RectorRunner per root (no fakes), run
 * against tests/Fixtures/project-multiroot-a and -b. They hold the SAME
 * Sample.php (same unused private method), but only -b's rector.php turns
 * on dead-code removal: a result that differs per root here is exactly the
 * #94 bug (Sublime's `${folder}` taking only the first folder, every
 * document diagnosed against the wrong root) made impossible by
 * construction, not merely asserted away. The byte-for-byte warm == cold
 * comparison itself stays this repo's own required independent E2E check
 * before merge (CLAUDE.md), which this unit test is not a substitute for.
 */
final class MultiRootDiagnosticsSourceIntegrationTest extends TestCase
{
    private string $cwdBackup;

    protected function setUp(): void
    {
        $this->cwdBackup = getcwd() ?: '/';
    }

    protected function tearDown(): void
    {
        chdir($this->cwdBackup);
    }

    public function testEachRootIsDiagnosedAgainstItsOwnRectorPhp(): void
    {
        $rootA = (string) realpath(__DIR__ . '/../Fixtures/project-multiroot-a');
        $rootB = (string) realpath(__DIR__ . '/../Fixtures/project-multiroot-b');
        self::assertNotSame('', $rootA);
        self::assertNotSame('', $rootB);

        $pool = new RootedDiagnosticsSourcePool(
            static fn(string $root): BufferDiagnosticsSource&WorkspaceDiagnosticsSource&EditDiagnosticsSource => new RectorDiagnosticsSource(new RectorTool()),
        );
        $source = new MultiRootDiagnosticsSource($pool, fallbackRoot: $rootA);
        $source->setWorkspaceFolders([$rootA, $rootB]);

        $resultA = $source->diagnose($rootA . '/src/Sample.php');
        $resultB = $source->diagnose($rootB . '/src/Sample.php');

        self::assertSame([], $resultA['fixes'], 'root A has no dead-code rule -- the unused method must survive untouched');
        self::assertNotSame([], $resultB['fixes'], 'root B turns on dead-code removal -- the unused method must be flagged');
    }
}
