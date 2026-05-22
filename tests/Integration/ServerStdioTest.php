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
