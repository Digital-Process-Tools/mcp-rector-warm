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
    /** @return resource */
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

    public function testWriteThrowsWhenTheStreamRejectsTheBytes(): void
    {
        // A failed fwrite() must be as loud as the short-read case above
        // (testReadThrowsOnTruncatedBody) -- a caller that only checks for a
        // thrown exception on the read side would otherwise never learn a
        // message was dropped on its way out. A read-only stream makes
        // fwrite() return false cleanly (no warning, no TypeError), which is
        // exactly the silent-failure shape this guards against.
        $out = fopen('php://memory', 'r');

        $transport = new StdioLspTransport(fopen('php://memory', 'r'), $out);

        $this->expectException(\RuntimeException::class);
        $transport->write(['jsonrpc' => '2.0', 'method' => 'initialized']);
    }

    /**
     * PR #128 E2E review (blocking finding 2), second self-review pass:
     * tryRead() now delegates to waitForInput() (see its own docblock for
     * why -- the windows-latest hang this replaces), which already
     * degrades a stream stream_select() cannot poll at all (ValueError,
     * observed for php://memory -- this class's own stand-in for STDIN in
     * every test here) to `pollForInput()`'s fstat()-based check instead of
     * propagating the exception. A plain php://memory stream is therefore
     * enough for every case below -- no real socket pair needed (the
     * earlier STREAM_PF_UNIX-based version of these tests is what CI's
     * no-pcntl leg refused outright: that build disables
     * stream_socket_pair() via disable_functions, which makes the
     * function call itself fatal, not merely return false).
     */
    public function testTryReadReturnsAMessageThatIsAlreadyWaiting(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}';
        $in = fopen('php://memory', 'r+');
        fwrite($in, "Content-Length: " . strlen($body) . "\r\n\r\n" . $body);
        rewind($in);

        $transport = new StdioLspTransport($in, fopen('php://memory', 'w'));

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []],
            $transport->tryRead(),
        );
    }

    /**
     * Positive control for the test above: nothing waiting must return
     * null rather than blocking -- this is the ordinary case (a real
     * client that has not replied yet), and the whole point of tryRead()
     * over read() is that this case returns immediately instead of
     * hanging the server.
     */
    public function testTryReadReturnsNullWhenNothingIsWaiting(): void
    {
        $transport = new StdioLspTransport(fopen('php://memory', 'r'), fopen('php://memory', 'w'));

        self::assertNull($transport->tryRead());
    }

    /**
     * A message pushed back (tryRead() peeked it, but it was not what the
     * caller wanted) must be the very next thing read() returns -- before
     * whatever is still sitting on the real stream underneath.
     */
    public function testPushBackIsReadBeforeTheUnderlyingStream(): void
    {
        $body = '{"jsonrpc":"2.0","method":"textDocument/didSave","params":{}}';
        $in = fopen('php://memory', 'r+');
        fwrite($in, "Content-Length: " . strlen($body) . "\r\n\r\n" . $body);
        rewind($in);

        $transport = new StdioLspTransport($in, fopen('php://memory', 'w'));
        $transport->pushBack(['jsonrpc' => '2.0', 'method' => 'pushedBack']);

        self::assertSame(['jsonrpc' => '2.0', 'method' => 'pushedBack'], $transport->read());
        self::assertSame(
            ['jsonrpc' => '2.0', 'method' => 'textDocument/didSave', 'params' => []],
            $transport->read(),
        );
    }
}
