<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Spawns bin/mcp-rector-warm as a subprocess, feeds JSON-RPC on stdin, asserts responses.
 * Covers the real boot path (autoload + scoper-autoload + Rector container).
 */
final class ServerStdioTest extends TestCase
{
    private static string $bin;
    private static string $fixtureDir;
    private static string $fixtureFile;

    /** @var list<string> temp project dirs created per test, removed in tearDown */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tmpDirs = [];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public static function setUpBeforeClass(): void
    {
        self::$bin = dirname(__DIR__, 2) . '/bin/mcp-rector-warm';
        // Absolute paths — server chdirs to working-dir; relative cwd-dependent paths would break.
        self::$fixtureDir = realpath(dirname(__DIR__) . '/Fixtures/project') ?: '';
        self::$fixtureFile = self::$fixtureDir . '/src/Sample.php';

        if (!is_file(self::$bin)) {
            self::markTestSkipped('bin/mcp-rector-warm missing');
        }
        if (!is_file(self::$fixtureFile)) {
            self::markTestSkipped('fixture missing');
        }
    }

    public function testInitializeAndListTools(): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ];
        $responses = $this->invoke($messages, withProject: false);

        // Response 1: initialize
        self::assertSame(1, $responses[0]['id']);
        self::assertArrayHasKey('result', $responses[0]);
        self::assertSame('mcp-rector-warm', $responses[0]['result']['serverInfo']['name']);

        // Response 2: tools/list
        self::assertSame(2, $responses[1]['id']);
        $tools = $responses[1]['result']['tools'];
        $names = array_column($tools, 'name');
        self::assertContains('rector_process', $names);
    }

    public function testRectorProcessCall(): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name' => 'rector_process',
                'arguments' => ['path' => self::$fixtureFile, 'dryRun' => true],
            ]],
        ];
        $responses = $this->invoke($messages, withProject: true);

        $call = array_values(array_filter($responses, fn($r) => ($r['id'] ?? null) === 2))[0] ?? null;
        self::assertNotNull($call, 'no response for id=2');
        self::assertArrayHasKey('result', $call, 'expected result, got: ' . json_encode($call));

        $structured = $call['result']['structuredContent'] ?? null;
        self::assertIsArray($structured);
        self::assertArrayHasKey('exit_code', $structured);
        self::assertArrayHasKey('warm_boot', $structured);
        self::assertFalse($structured['warm_boot'], 'first call should be cold boot');
    }

    public function testWarmBootOnSecondCall(): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name' => 'rector_process',
                'arguments' => ['path' => self::$fixtureFile, 'dryRun' => true],
            ]],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
                'name' => 'rector_process',
                'arguments' => ['path' => self::$fixtureFile, 'dryRun' => true],
            ]],
        ];
        $responses = $this->invoke($messages, withProject: true);

        $third = array_values(array_filter($responses, fn($r) => ($r['id'] ?? null) === 3))[0] ?? null;
        self::assertNotNull($third, 'no response for id=3');
        $structured = $third['result']['structuredContent'];
        self::assertTrue($structured['warm_boot'], 'second tools/call should reuse warm container');
    }

    /**
     * Staleness guard: an edit made BETWEEN two process calls on the same warm
     * container must be reflected on the second call.
     *
     * Unlike phpunit (which *executes* classes and so can't reload them after an
     * edit — claude-supertool#265), Rector parses source to an AST and re-reads it
     * each run, so a re-processed file should surface the edit. This pins it: a
     * file Rector leaves alone (0 changed) → introduce an all-readonly promoted
     * class on disk (ReadOnlyClassRector applies → 1 changed) → the same warm
     * container must report the change.
     */
    public function testEditedSourceIsReprocessedAcrossCalls(): void
    {
        $project = $this->makeProject(withChange: false);
        $file    = $project . '/src/RectorProbe.php';

        $proc = $this->spawnServer($project);

        try {
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]]);
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            // Clean file → Rector finds nothing to change.
            $this->send($proc['stdin'], $this->processCall(2, $file));
            $first = $this->readResponse($proc['stdout'], 2);
            self::assertNotSame(-1, $this->changedFiles($first), 'rector output was unparseable' . $this->stderrTail($proc['stderr']));
            self::assertSame(
                0,
                $this->changedFiles($first),
                'clean fixture should yield 0 changed files, got: ' . json_encode($first['result']['structuredContent'] ?? []) . $this->stderrTail($proc['stderr'])
            );

            // Introduce a refactorable pattern on disk; bump mtime past 1s granularity.
            file_put_contents($file, $this->probeClass(withChange: true));
            touch($file, time() + 5);

            // Same warm container must re-read the file and report the change.
            $this->send($proc['stdin'], $this->processCall(3, $file));
            $second = $this->readResponse($proc['stdout'], 3);
            self::assertTrue(
                $second['result']['structuredContent']['warm_boot'],
                'second call should reuse the warm container' . $this->stderrTail($proc['stderr'])
            );
            self::assertNotSame(-1, $this->changedFiles($second), 'rector output was unparseable' . $this->stderrTail($proc['stderr']));
            self::assertSame(
                1,
                $this->changedFiles($second),
                'edited source becomes refactorable — warm container must re-read and report it (stale AST would still report 0)' . $this->stderrTail($proc['stderr'])
            );
        } finally {
            fclose($proc['stdin']);
            stream_get_contents($proc['stdout']);
            fclose($proc['stdout']);
            proc_close($proc['handle']);
        }
    }

    /**
     * @param array<string,mixed> $response
     */
    private function changedFiles(array $response): int
    {
        $output = $response['result']['structuredContent']['output'] ?? '';
        $decoded = is_string($output) && $output !== '' ? json_decode($output, true) : [];

        return (int) ($decoded['totals']['changed_files'] ?? -1);
    }

    /**
     * @return array{handle: resource, stdin: resource, stdout: resource, stderr: string}
     */
    private function spawnServer(string $project): array
    {
        // Capture stderr to a file (not /dev/null) so a CI failure has diagnostics.
        $stderr = $project . '/server.stderr';
        $cmd = [
            self::$bin,
            '--working-dir=' . $project,
            '--config=' . $project . '/rector.php',
        ];
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
        );
        self::assertIsResource($proc);

        return ['handle' => $proc, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $stderr];
    }

    private function stderrTail(string $path): string
    {
        $contents = @file_get_contents($path);

        return ($contents === false || $contents === '') ? '' : ' | server stderr: ' . substr($contents, -1500);
    }

    /**
     * @param resource            $stdin
     * @param array<string,mixed> $message
     */
    private function send($stdin, array $message): void
    {
        fwrite($stdin, json_encode($message) . "\n");
        fflush($stdin);
    }

    /**
     * Block reading newline-delimited JSON-RPC until the response with $id arrives.
     * Rector cold boot can take several seconds — allow a generous read timeout.
     *
     * @param resource $stdout
     * @return array<string,mixed>
     */
    private function readResponse($stdout, int $id): array
    {
        stream_set_timeout($stdout, 120);
        while (($line = fgets($stdout)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['id'] ?? null) === $id) {
                return $decoded;
            }
        }

        self::fail("no response for id={$id}");
    }

    /**
     * @return array<string,mixed>
     */
    private function processCall(int $id, string $file): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => [
            'name'      => 'rector_process',
            'arguments' => ['path' => $file, 'dryRun' => true],
        ]];
    }

    private function makeProject(bool $withChange): string
    {
        $dir = sys_get_temp_dir() . '/rector_mcp_regr_' . bin2hex(random_bytes(6));
        mkdir($dir . '/src', 0777, true);
        $this->tmpDirs[] = $dir;

        file_put_contents($dir . '/src/RectorProbe.php', $this->probeClass($withChange));
        // Pin the SINGLE rule under test rather than the broad php82 set — a broad
        // set's "0 changes" baseline isn't contractually stable across rector
        // versions and could rewrite the clean fixture, failing for the wrong reason.
        file_put_contents(
            $dir . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\n"
            . "use Rector\\Config\\RectorConfig;\n"
            . "use Rector\\Php82\\Rector\\Class_\\ReadOnlyClassRector;\n\n"
            . "return RectorConfig::configure()->withPaths([__DIR__ . '/src'])->withRules([ReadOnlyClassRector::class]);\n"
        );

        return $dir;
    }

    /**
     * Clean version has no property to modernise (0 changes). The changed version
     * is a final class whose only state is a promoted readonly property, which
     * ReadOnlyClassRector rewrites to a `readonly class` (1 change).
     */
    private function probeClass(bool $withChange): string
    {
        if ($withChange) {
            return "<?php\n\ndeclare(strict_types=1);\n\n"
                . "final class RectorProbe\n{\n    public function __construct(private readonly int \$value) {}\n\n"
                . "    public function value(): int\n    {\n        return \$this->value;\n    }\n}\n";
        }

        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . "final class RectorProbe\n{\n    public function add(int \$first, int \$second): int\n    {\n        return \$first + \$second;\n    }\n}\n";
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    private function invoke(array $messages, bool $withProject): array
    {
        $args = [];
        if ($withProject) {
            $args[] = '--working-dir=' . self::$fixtureDir;
            $args[] = '--config=' . self::$fixtureDir . '/rector.php';
        }
        $cmd = array_merge([self::$bin], $args);

        $stdin = '';
        foreach ($messages as $m) {
            $stdin .= json_encode($m) . "\n";
        }

        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $responses = [];
        foreach (explode("\n", $stdout) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $responses[] = $decoded;
            }
        }
        self::assertNotEmpty($responses, 'no responses parsed. stdout=' . $stdout . ' stderr=' . $stderr);
        return $responses;
    }
}
