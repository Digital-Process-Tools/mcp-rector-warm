<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorRunner;
use Dpt\McpRectorWarm\RectorTool;
use PHPUnit\Framework\TestCase;

/**
 * #126: an error payload built after a failed call must read warm_boot from
 * RectorRunner::wasLastCallWarm() rather than isWarm() -- isWarm() alone
 * misreports once the worker that served the call has already been
 * discarded (e.g. by the kill paths), while wasLastCallWarm() was captured
 * before that discard. RectorTool::processWith() picks between the two only
 * when $runner is an actual RectorRunner (RunnerInterface has no
 * wasLastCallWarm() method at all); every other test double in this suite
 * exercises the `: isWarm()` branch, leaving the `instanceof RectorRunner`
 * branch itself uncovered.
 */
final class RectorToolWasLastCallWarmTest extends TestCase
{
    private string $cwdBackup;
    private string $workDir;

    protected function setUp(): void
    {
        $this->cwdBackup = getcwd() ?: '/';
        $this->workDir = sys_get_temp_dir() . '/mcp-rector-warm-boot-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o700, true);
        chdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwdBackup);
        @unlink($this->workDir . '/inside.php');
        @rmdir($this->workDir);
    }

    public function testErrorPayloadReadsWasLastCallWarmForARealRunner(): void
    {
        $inside = $this->workDir . '/inside.php';
        file_put_contents($inside, "<?php\n\nclass Inside\n{\n}\n");

        $runner = new class extends RectorRunner {
            public function run(array $argv, bool $dryRun = true, bool $noSession = false): array
            {
                throw new \RuntimeException('boom');
            }

            // isWarm() stays the real, inherited implementation: no worker was
            // ever booted, so it reports false. wasLastCallWarm() is overridden
            // to report true -- the only way the two methods disagree is if the
            // production code actually calls wasLastCallWarm() for a real
            // RectorRunner, which is exactly the branch this test pins.
            public function wasLastCallWarm(): bool
            {
                return true;
            }
        };

        self::assertFalse($runner->isWarm(), 'precondition: no worker was ever booted');

        $tool = RectorTool::withRunner($runner);
        $result = $tool->process($inside, true);

        self::assertInstanceOf(\Mcp\Schema\Result\CallToolResult::class, $result);
        $details = $result->structuredContent ?? [];
        self::assertSame(
            true,
            $details['warm_boot'] ?? null,
            'warm_boot must come from wasLastCallWarm(), not isWarm(), for a real RectorRunner',
        );
    }
}
