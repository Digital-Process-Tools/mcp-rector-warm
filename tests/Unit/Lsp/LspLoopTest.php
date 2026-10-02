<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\BufferDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\LspLoop;
use Dpt\McpRectorWarm\Lsp\LspServer;
use Dpt\McpRectorWarm\Lsp\StdioLspTransport;
use PHPUnit\Framework\TestCase;

/**
 * #106: the stdio loop is synchronous, so the debounce is a read with a
 * timeout (stream_select). These drive the real loop over a real socket
 * pair -- php://memory cannot be selected on.
 */
final class LspLoopTest extends TestCase
{
    /** @param array<string, mixed> $message */
    private static function frame(array $message): string
    {
        $body = json_encode($message, JSON_UNESCAPED_SLASHES);
        self::assertNotFalse($body);

        return 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
    }

    /** @return resource */
    private static function memoryStream(string $mode = 'r+')
    {
        $stream = fopen('php://memory', $mode);
        self::assertNotFalse($stream, "fopen('php://memory', '{$mode}') unexpectedly failed");

        return $stream;
    }

    private static function didChange(int $version, string $text): string
    {
        return self::frame([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => $version],
                'contentChanges' => [['text' => $text]],
            ],
        ]);
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private static function pair(): array
    {
        // CI's no-pcntl leg disables stream_socket_pair() through
        // disable_functions, and on Windows it fails (no AF_UNIX pair); a
        // loopback TCP connection gives the loop the same selectable stream
        // there, so these tests still run.
        if (function_exists('stream_socket_pair')) {
            $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair !== false) {
                [$a, $b] = $pair;

                return [$a, $b];
            }
        }

        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($listener, "loopback listener: {$errstr}");
        $client = stream_socket_client('tcp://' . stream_socket_get_name($listener, false), $errno, $errstr, 5.0);
        self::assertNotFalse($client, "loopback client: {$errstr}");
        $server = stream_socket_accept($listener, 5.0);
        self::assertNotFalse($server, 'loopback accept');
        fclose($listener);

        return [$server, $client];
    }

    /**
     * @param resource $stream
     * @return list<array<string, mixed>>
     */
    private static function framesIn($stream): array
    {
        rewind($stream);
        $raw = stream_get_contents($stream);
        $frames = [];
        while (preg_match('/^Content-Length: (\d+)\r\n\r\n/', $raw, $m) === 1) {
            $start = strlen($m[0]);
            $frames[] = json_decode(substr($raw, $start, (int) $m[1]), true);
            $raw = substr($raw, $start + (int) $m[1]);
        }

        return $frames;
    }

    public function testWaitForInputTimesOutWhenNothingWasSent(): void
    {
        [$server, $client] = self::pair();
        $transport = new StdioLspTransport($server, self::memoryStream('w'));

        $started = microtime(true);
        self::assertFalse($transport->waitForInput(0.05));
        self::assertGreaterThanOrEqual(0.04, microtime(true) - $started);
        fclose($client);
    }

    public function testWaitForInputSeesAFrameStillSittingInPhpsReadBuffer(): void
    {
        // Must fire, and the case a naive select() on the raw descriptor
        // gets wrong: two frames arrive in one write, read() consumes the
        // first, and the second is already in PHP's own buffer, not in the
        // kernel's -- it still has to count as pending input.
        [$server, $client] = self::pair();
        $transport = new StdioLspTransport($server, self::memoryStream('w'));
        fwrite($client, self::didChange(1, 'a') . self::didChange(2, 'b'));

        self::assertTrue($transport->waitForInput(1.0));
        $transport->read();
        self::assertTrue($transport->waitForInput(0.0));
        $second = $transport->read();
        self::assertNotNull($second, 'the second already-buffered frame must still be readable');
        self::assertSame(2, $second['params']['textDocument']['version']);
        self::assertFalse($transport->waitForInput(0.0));
        fclose($client);
    }

    public function testAChangeThatArrivesWhileRectorRunsSupersedesThatRunsResult(): void
    {
        // Must not fire: version 1's run is in flight when version 2 lands on
        // the pipe. The loop has to drain that input before publishing, so
        // version 1's result never goes out. Positive control in the same
        // run: version 2 is then diagnosed and published.
        [$serverEnd, $client] = self::pair();
        $out = self::memoryStream('w+');

        $source = new class ($client, $serverEnd) implements BufferDiagnosticsSource {
            /** @var list<string> */
            public array $diagnosed = [];

            /**
             * @param resource $client
             * @param resource $serverEnd
             */
            public function __construct(private $client, private $serverEnd) {}

            public function diagnose(string $absolutePath): array
            {
                return ['fixes' => []];
            }

            public function diagnoseBuffer(string $absolutePath, string $content): array
            {
                $this->diagnosed[] = $content;
                if (count($this->diagnosed) === 1) {
                    fwrite($this->client, LspLoopTest::changeFrame(2, "<?php\nclean\n"));
                    // "Arrived while Rector ran" means readable on the
                    // server's end before this run returns. Over the
                    // loopback-TCP fallback that takes a moment; wait for
                    // it (without reading) so the test states that premise.
                    $read = [$this->serverEnd];
                    $none = null;
                    stream_select($read, $none, $none, 2);
                } else {
                    fclose($this->client);
                }

                return str_contains($content, 'fixable')
                    ? ['fixes' => [[
                        'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 2, 'character' => 0]],
                        'newText' => "fixed\n",
                        'rectors' => ['SomeRector'],
                    ]]]
                    : ['fixes' => []];
            }
        };

        fwrite($client, self::didChange(1, "<?php\nfixable\n"));
        $loop = new LspLoop(new StdioLspTransport($serverEnd, $out), new LspServer('1.0.0', $source, 0.01));

        $exit = $loop->run();

        self::assertSame(1, $exit, 'EOF without shutdown exits 1');
        self::assertSame(["<?php\nfixable\n", "<?php\nclean\n"], $source->diagnosed);
        $published = array_values(array_filter(
            self::framesIn($out),
            static fn(array $f): bool => ($f['method'] ?? null) === 'textDocument/publishDiagnostics',
        ));
        self::assertCount(1, $published);
        self::assertSame(2, $published[0]['params']['version']);
        self::assertSame([], $published[0]['params']['diagnostics']);
    }

    public function testAnUninterruptedChangeIsPublishedByTheLoop(): void
    {
        // Must fire, through the real loop: one change, nothing else sent,
        // the debounce elapses and the diagnostic goes out.
        [$serverEnd, $client] = self::pair();
        $out = self::memoryStream('w+');
        $source = new class ($client) implements BufferDiagnosticsSource {
            /** @param resource $client */
            public function __construct(private $client) {}

            public function diagnose(string $absolutePath): array
            {
                return ['fixes' => []];
            }

            public function diagnoseBuffer(string $absolutePath, string $content): array
            {
                fclose($this->client);

                return ['fixes' => [[
                    'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 2, 'character' => 0]],
                    'newText' => "fixed\n",
                    'rectors' => ['SomeRector'],
                ]]];
            }
        };

        fwrite($client, self::didChange(1, "<?php\nfixable\n"));
        (new LspLoop(new StdioLspTransport($serverEnd, $out), new LspServer('1.0.0', $source, 0.01)))->run();

        $frames = self::framesIn($out);
        self::assertCount(1, $frames);
        self::assertSame(1, $frames[0]['params']['version']);
        self::assertCount(1, $frames[0]['params']['diagnostics']);
    }

    public function testShutdownThenExitStillExitsZero(): void
    {
        [$serverEnd, $client] = self::pair();
        $out = self::memoryStream('w+');
        fwrite($client, self::frame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'shutdown']) . self::frame(['jsonrpc' => '2.0', 'method' => 'exit']));

        $exit = (new LspLoop(new StdioLspTransport($serverEnd, $out), new LspServer('1.0.0')))->run();

        self::assertSame(0, $exit);
        self::assertSame(1, self::framesIn($out)[0]['id']);
        fclose($client);
    }

    public static function changeFrame(int $version, string $text): string
    {
        return self::didChange($version, $text);
    }
}
