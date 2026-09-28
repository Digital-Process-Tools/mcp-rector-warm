<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\RectorDiagnosticsSource;
use Dpt\McpRectorWarm\RectorTool;
use Dpt\McpRectorWarm\RunnerInterface;
use PHPUnit\Framework\TestCase;

/**
 * #106: an unsaved buffer is diagnosed by writing it to a temp file inside
 * the project, next to the original, running Rector on that, and deleting it
 * again -- on success, on a Rector error, and on a runner that throws.
 */
final class RectorDiagnosticsSourceBufferTest extends TestCase
{
    private string $cwdBackup;
    private string $workDir;
    private string $original;

    protected function setUp(): void
    {
        $this->cwdBackup = getcwd() ?: '/';
        $this->workDir = sys_get_temp_dir() . '/mcp-rector-lsp-buffer-' . bin2hex(random_bytes(4));
        mkdir($this->workDir . '/src', 0o700, true);
        chdir($this->workDir);
        $this->original = $this->workDir . '/src/Sample.php';
        file_put_contents($this->original, "<?php\n\nclass Sample\n{\n}\n");
    }

    protected function tearDown(): void
    {
        chdir($this->cwdBackup);
        $this->removeTree($this->workDir);
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Every entry under the project, relative, so a leftover temp file or
     * temp directory shows up by name in the failure message.
     *
     * @return list<string>
     */
    private function projectEntries(): array
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $file) {
            $entries[] = str_replace('\\', '/', substr((string) $file, strlen($this->workDir) + 1));
        }
        sort($entries);

        return $entries;
    }

    /**
     * @param \Closure(string $path): string $behaviour gets the path Rector
     *   was asked to process and returns Rector's raw output (or throws)
     */
    private function source(\Closure $behaviour): RectorDiagnosticsSource
    {
        $runner = new class ($behaviour) implements RunnerInterface {
            public function __construct(private readonly \Closure $behaviour)
            {
            }

            public function run(array $argv, bool $dryRun = true): array
            {
                return ['exit_code' => 0, 'output' => ($this->behaviour)(end($argv)), 'warm_boot' => false];
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

        return new RectorDiagnosticsSource(RectorTool::withRunner($runner));
    }

    public function testTheBufferIsProcessedFromATempFileBesideTheOriginalThenRemoved(): void
    {
        $seen = null;
        $buffer = "<?php\n\nclass Sample\n{\n    // unsaved\n}\n";
        $output = '{"totals":{"changed_files":1,"errors":0},"file_diffs":['
            . '{"file":"x","diff":"--- Original\n+++ New\n@@ -1,1 +1,1 @@\n-old\n+new\n",'
            . '"applied_rectors":["SomeRector"],"changes":[]}]}';

        $result = $this->source(function (string $path) use (&$seen, $output): string {
            $seen = ['path' => $path, 'exists' => is_file($path), 'content' => (string) @file_get_contents($path)];

            return $output;
        })->diagnoseBuffer($this->original, $buffer);

        self::assertNotNull($seen);
        self::assertNotSame($this->original, $seen['path']);
        self::assertTrue($seen['exists']);
        self::assertSame($buffer, $seen['content']);
        // Same basename, one hidden directory below the original's own
        // directory: path-glob skips like `*/Sample.php` or `*Test.php` and
        // directory skips keep matching.
        self::assertSame('Sample.php', basename($seen['path']));
        self::assertSame(dirname($this->original), dirname($seen['path'], 2));
        self::assertStringStartsWith('.rector-warm-', basename(dirname($seen['path'])));

        self::assertCount(1, $result['fixes']);
        self::assertSame(['SomeRector'], $result['fixes'][0]['rectors']);
        self::assertSame(['src', 'src/Sample.php'], $this->projectEntries());
        self::assertSame("<?php\n\nclass Sample\n{\n}\n", file_get_contents($this->original));
    }

    public function testASyntaxErrorBufferReportsTheErrorAgainstTheOriginalAndLeavesNothingBehind(): void
    {
        $result = $this->source(function (string $path): string {
            return json_encode([
                'totals' => ['changed_files' => 0, 'errors' => 1],
                'errors' => [['message' => 'Syntax error in ' . $path . ', unexpected EOF', 'file' => $path, 'line' => 3]],
                'file_diffs' => [],
            ], JSON_UNESCAPED_SLASHES);
        })->diagnoseBuffer($this->original, "<?php\n\nclass Sample {\n");

        self::assertSame([], $result['fixes']);
        self::assertCount(1, $result['errors']);
        self::assertSame(3, $result['errors'][0]['line']);
        self::assertStringNotContainsString('.rector-warm-', $result['errors'][0]['message']);
        self::assertStringContainsString('Sample.php', $result['errors'][0]['message']);
        self::assertSame(['src', 'src/Sample.php'], $this->projectEntries());
    }

    public function testARunnerThatThrowsStillLeavesNothingBehind(): void
    {
        $result = $this->source(function (string $path): string {
            throw new \RuntimeException('worker died on ' . $path);
        })->diagnoseBuffer($this->original, "<?php\n");

        self::assertSame([], $result['fixes']);
        self::assertNotSame([], $result['errors']);
        self::assertStringNotContainsString('.rector-warm-', $result['errors'][0]['message']);
        self::assertSame(['src', 'src/Sample.php'], $this->projectEntries());
    }

    public function testABufferForAFileOutsideTheProjectIsRefusedWithoutWritingAnything(): void
    {
        $outsideDir = sys_get_temp_dir() . '/mcp-rector-lsp-outside-' . bin2hex(random_bytes(4));
        mkdir($outsideDir);

        try {
            $called = false;
            $result = $this->source(function () use (&$called): string {
                $called = true;

                return '{"totals":{"changed_files":0,"errors":0}}';
            })->diagnoseBuffer($outsideDir . '/Evil.php', "<?php\n");

            self::assertFalse($called);
            self::assertStringContainsString('outside the configured working directory', $result['errors'][0]['message']);
            self::assertSame(['.', '..'], scandir($outsideDir));
        } finally {
            @rmdir($outsideDir);
        }
    }
}
