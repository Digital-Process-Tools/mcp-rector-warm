<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Lsp\StdioLspTransport;
use PHPUnit\Framework\TestCase;

/**
 * The LSP wire format is Content-Length-framed JSON-RPC over stdio -- distinct
 * from MCP's own framing (handled by mcp/sdk). Nothing in this repo hand-rolls
 * a protocol frame today, so this is new surface: read one message, write one
 * message, and fail loudly rather than silently on a truncated frame.
 */
final class StdioLspTransportTest extends TestCase
{
    private function streamWith(string $contents)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function testReadsOneFramedMessage(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}';
        $frame = "Content-Length: " . strlen($body) . "\r\n\r\n" . $body;
        $transport = new StdioLspTransport($this->streamWith($frame), fopen('php://memory', 'w'));

        $message = $transport->read();

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []],
            $message,
        );
    }

    public function testReadReturnsNullAtCleanEof(): void
    {
        // Positive control for the case below: an EMPTY stream (nothing ever
        // sent) must read as null -- the same answer a stream that dies
        // mid-header must NOT give, or the two become indistinguishable.
        $transport = new StdioLspTransport($this->streamWith(''), fopen('php://memory', 'w'));

        self::assertNull($transport->read());
    }

    public function testReadThrowsOnTruncatedBody(): void
    {
        // The header promises 500 bytes; the stream closes after 10. A quiet
        // null here would be indistinguishable from the clean-EOF case above
        // and would drop a real body silently.
        $body = 'only-ten!!';
        $frame = "Content-Length: 500\r\n\r\n" . $body;
        $transport = new StdioLspTransport($this->streamWith($frame), fopen('php://memory', 'w'));

        $this->expectException(\RuntimeException::class);
        $transport->read();
    }

    public function testReadThrowsWhenContentLengthHeaderIsMissing(): void
    {
        $frame = "X-Something-Else: yes\r\n\r\n{}";
        $transport = new StdioLspTransport($this->streamWith($frame), fopen('php://memory', 'w'));

        $this->expectException(\RuntimeException::class);
        $transport->read();
    }

    public function testWriteFramesTheMessageWithAMatchingContentLength(): void
    {
        $out = fopen('php://memory', 'w+');
        $transport = new StdioLspTransport(fopen('php://memory', 'r'), $out);

        $transport->write(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]]);

        rewind($out);
        $written = stream_get_contents($out);
        [$header, $body] = explode("\r\n\r\n", $written, 2);
        self::assertMatchesRegularExpression('/^Content-Length: (\d+)$/', $header, $header);
        preg_match('/^Content-Length: (\d+)$/', $header, $m);
        self::assertSame((int) $m[1], strlen($body));
        self::assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]], json_decode($body, true));
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $stream = fopen('php://memory', 'r+');
        $transport = new StdioLspTransport($stream, $stream);

        $transport->write(['jsonrpc' => '2.0', 'method' => 'initialized']);
        rewind($stream);

        self::assertSame(
            ['jsonrpc' => '2.0', 'method' => 'initialized'],
            $transport->read(),
        );
    }
}
