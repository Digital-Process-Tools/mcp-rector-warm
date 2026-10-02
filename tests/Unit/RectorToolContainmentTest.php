<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Tests\Support\Json;
use Dpt\McpRectorWarm\RectorTool;
use Mcp\Schema\Result\CallToolResult;
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

        // A refusal must be flagged as an MCP tool error (isError: true), not
        // returned as an ordinary successful result — #16.
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
        $details = $result->structuredContent ?? [];

        self::assertSame(-1, $details['exit_code']);
        self::assertSame('SecurityError', $details['error_class'] ?? null);
        self::assertStringContainsString('outside', Json::string($details, 'error'));
        // Crucial: ensure rector was NOT booted (would dirty the daemon).
        self::assertFalse($details['warm_boot']);
    }

    public function testRejectsNonexistentPath(): void
    {
        $tool = new RectorTool();
        $result = $tool->process($this->workDir . '/does-not-exist.php', true);

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
        $details = $result->structuredContent ?? [];

        self::assertSame(-1, $details['exit_code']);
        self::assertSame('SecurityError', $details['error_class'] ?? null);
    }

    /**
     * #99: on a real Windows filesystem, realpath() can hand back a drive
     * letter in a different case than getcwd() did (or vice versa) for the
     * same file -- the filesystem itself is case-insensitive there, so
     * neither case is "wrong". The plain str_starts_with() containment
     * check used before this fix is case-SENSITIVE and would misjudge an
     * in-root file as out-of-root purely on a drive-letter case mismatch.
     * No Windows machine is available here, so this drives the extracted
     * comparison directly (via reflection) with $caseInsensitive forced
     * true/false, rather than relying on the host OS's own
     * DIRECTORY_SEPARATOR to select the branch -- the string logic itself
     * is what this pins, independent of platform.
     */
    public function testIsWithinRootIgnoresDriveLetterCaseWhenCaseInsensitive(): void
    {
        $method = new \ReflectionMethod(RectorTool::class, 'isWithinRoot');

        self::assertTrue($method->invoke(null, 'C:\proj\src\A.php', 'c:\proj', true));
        self::assertTrue($method->invoke(null, 'c:\proj\src\A.php', 'C:\proj', true));
    }

    public function testIsWithinRootStaysCaseSensitiveOnPosix(): void
    {
        // Positive control's pair: on a case-sensitive filesystem, a case
        // mismatch is a genuinely different path and must NOT be folded
        // into "in root".
        $method = new \ReflectionMethod(RectorTool::class, 'isWithinRoot');

        self::assertFalse($method->invoke(null, '/Proj/src/A.php', '/proj', false));
        self::assertTrue($method->invoke(null, '/proj/src/A.php', '/proj', false));
    }
}
