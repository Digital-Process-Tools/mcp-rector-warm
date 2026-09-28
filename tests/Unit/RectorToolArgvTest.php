<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorTool;
use Dpt\McpRectorWarm\RunnerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Argv regression (#21): the path argument must be preceded by '--' so a path
 * that happens to start with '-' (e.g. a file literally named "-rf") is never
 * parsed as a Rector CLI flag.
 */
final class RectorToolArgvTest extends TestCase
{
    private string $cwdBackup;
    private string $workDir;

    protected function setUp(): void
    {
        $this->cwdBackup = getcwd() ?: '/';
        $this->workDir = sys_get_temp_dir() . '/mcp-rector-argv-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o700, true);
        chdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwdBackup);
        @unlink($this->workDir . '/inside.php');
        @rmdir($this->workDir);
    }

    public function testPathArgumentIsPrecededByDoubleDash(): void
    {
        $inside = $this->workDir . '/inside.php';
        file_put_contents($inside, "<?php\n\nclass Inside\n{\n}\n");

        $fake = new class implements RunnerInterface {
            /** @var list<string> */
            public array $lastArgv = [];

            public function run(array $argv, bool $dryRun = true): array
            {
                $this->lastArgv = $argv;

                return ['exit_code' => 0, 'output' => '{"totals":{"errors":0}}', 'warm_boot' => false];
            }

            public function isWarm(): bool
            {
                return false;
            }

            public function reboot(): void
            {
            }

            public function getCallTimeoutSeconds(): int
            {
                return 0;
            }
        };

        $tool = RectorTool::withRunner($fake);
        $tool->process($inside, true);

        $separatorIndex = array_search('--', $fake->lastArgv, true);
        $pathIndex = array_search($inside, $fake->lastArgv, true);

        self::assertNotFalse($separatorIndex, 'argv must contain a \'--\' separator');
        self::assertNotFalse($pathIndex, 'argv must contain the path');
        self::assertSame($separatorIndex + 1, $pathIndex, "'--' must immediately precede the path argument");
    }
}
