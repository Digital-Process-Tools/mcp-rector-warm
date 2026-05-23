<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorTool;
use PHPUnit\Framework\TestCase;

/**
 * Containment regression: RectorTool::process must reject paths outside the
 * working directory before invoking RectorRunner. Without this guard, a hostile
 * MCP client could trigger refactor rewrites on arbitrary PHP files on the host.
 */
final class RectorToolContainmentTest extends TestCase
{
    private string $cwdBackup;
    private string $workDir;
    private string $outsideDir;

    protected function setUp(): void
    {
        $this->cwdBackup = getcwd() ?: '/';
        $this->workDir    = sys_get_temp_dir() . '/mcp-rector-work-' . bin2hex(random_bytes(4));
        $this->outsideDir = sys_get_temp_dir() . '/mcp-rector-outside-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o700, true);
        mkdir($this->outsideDir, 0o700, true);
        chdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwdBackup);
        @unlink($this->workDir . '/inside.php');
        @unlink($this->outsideDir . '/leak.php');
        @rmdir($this->workDir);
        @rmdir($this->outsideDir);
    }

    public function testRejectsPathOutsideWorkingDir(): void
    {
        $leak = $this->outsideDir . '/leak.php';
        file_put_contents($leak, "<?php\nclass Leak {}\n");

        $tool = new RectorTool();
        $result = $tool->process($leak, true);

        self::assertSame(-1, $result['exit_code']);
        self::assertSame('SecurityError', $result['error_class'] ?? null);
        self::assertStringContainsString('outside', $result['error'] ?? '');
        // Crucial: ensure rector was NOT booted (would dirty the daemon).
        self::assertFalse($result['warm_boot']);
    }

    public function testRejectsNonexistentPath(): void
    {
        $tool = new RectorTool();
        $result = $tool->process($this->workDir . '/does-not-exist.php', true);

        self::assertSame(-1, $result['exit_code']);
        self::assertSame('SecurityError', $result['error_class'] ?? null);
    }
}
