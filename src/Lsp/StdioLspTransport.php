<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

use RuntimeException;

/**
 * Content-Length-framed JSON-RPC over stdio -- the wire format LSP uses
 * (https://microsoft.github.io/language-server-protocol/specifications/lsp/3.17/specification/#headerPart).
 * Hand-rolled deliberately: see docs/decisions/0001-lsp-library-choice.md.
 * Distinct from MCP's own framing, which mcp/sdk already owns.
 */
final class StdioLspTransport
{
    /**
     * @var list<array<string, mixed>> PR #128 E2E review (blocking finding
     *   2): messages read AHEAD by tryRead() -- a peek when called with
     *   its default 0.0 timeout, but a genuine, potentially-blocking wait
     *   when called with a real one (second E2E review round:
     *   LspServer::isProgressCreateRefused() waits up to
     *   CREATE_REPLY_TIMEOUT_SECONDS this way) -- that turned out not to
     *   be what the caller was looking for, and were handed back via
     *   pushBack() rather than dropped. read() and waitForInput() both
     *   drain this FIFO first, so a caller that knows nothing about
     *   tryRead() (LspLoop's own read loop) still sees a pushed-back
     *   message exactly like any other, in order.
     */
    private array $pending = [];

    /**
     * @param resource $in
     * @param resource $out
     */
    public function __construct(private $in, private $out) {}

    /**
     * Reads one framed message. Returns null only at a CLEAN EOF -- nothing
     * pending, the stream simply ended between frames. A frame that starts
     * and then breaks (missing/short header, truncated body) is not EOF: it
     * throws, so a dropped byte is never silently read back as "nothing sent".
     */
    public function read(): ?array
    {
        if ($this->pending !== []) {
            return array_shift($this->pending);
        }

        $headerLine = fgets($this->in);
        if ($headerLine === false) {
            return null;
        }

        $contentLength = null;
        while ($headerLine !== false && trim($headerLine) !== '') {
            if (preg_match('/^Content-Length:\s*(\d+)/i', $headerLine, $matches) === 1) {
                $contentLength = (int) $matches[1];
            }
            $headerLine = fgets($this->in);
        }

        if ($contentLength === null) {
            throw new RuntimeException('LSP frame missing a Content-Length header');
        }

        $body = '';
        while (strlen($body) < $contentLength) {
            $chunk = fread($this->in, $contentLength - strlen($body));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException(sprintf(
                    'LSP frame truncated: expected %d bytes, got %d',
                    $contentLength,
                    strlen($body),
                ));
            }
            $body .= $chunk;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('LSP frame body did not decode to a JSON object/array');
        }

        return $decoded;
    }

    /**
     * #106: whether a message can be read without blocking, waiting up to
     * $timeoutSeconds for one to arrive -- the synchronous loop's debounce
     * timer. stream_select() also reports a stream whose next frame already
     * sits in PHP's own read buffer (read() pulls whole chunks through
     * fgets()), not only one with bytes pending in the kernel, so a second
     * frame that arrived in the same write as the first is not missed. EOF
     * counts as readable: the following read() returns null.
     *
     * Windows: select() there only works on sockets (used as-is for a
     * socket), and for a pipe PHP
     * either fails or reports the handle as always ready -- the second would
     * make the loop block in read() and never fire the debounce. So on
     * Windows this polls instead: PHP's own read buffer
     * (`unread_bytes`), then the bytes waiting in the pipe (fstat()'s size,
     * which Windows fills from PeekNamedPipe for a pipe), every 5 ms until
     * the timeout. If neither can see pending input, the debounce still
     * fires on time and a message that arrived meanwhile is read right
     * after, so the only loss is superseding a run in flight.
     */
    public function waitForInput(float $timeoutSeconds): bool
    {
        // PR #128 E2E review: a message already read ahead and pushed back
        // (see $pending's own docblock) counts as waiting -- LspLoop's
        // debounce must not block for the full timeout when the very next
        // read() would return instantly anyway.
        if ($this->pending !== []) {
            return true;
        }

        $timeoutSeconds = max(0.0, $timeoutSeconds);

        if ($this->hasBufferedInput()) {
            return true;
        }

        if (PHP_OS_FAMILY === 'Windows' && !$this->isSocket()) {
            return $this->pollForInput($timeoutSeconds);
        }

        $seconds = (int) floor($timeoutSeconds);
        $microseconds = min(999_999, (int) round(($timeoutSeconds - $seconds) * 1_000_000));

        $read = [$this->in];
        $write = null;
        $except = null;

        try {
            // PR #128 self-review: stream_select() throws ValueError
            // instead of returning false for a stream it cannot poll at
            // all (observed for php://memory, this class's own test
            // doubles for STDIN) -- `@` does not suppress a thrown
            // exception, only a warning, so this used to propagate
            // uncaught. Same fallback as the false case: poll instead.
            $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
        } catch (\ValueError) {
            return $this->pollForInput($timeoutSeconds);
        }

        if ($ready === false) {
            return $this->pollForInput($timeoutSeconds);
        }

        return $ready > 0;
    }

    /** Windows' select() does handle sockets; only pipes and files need polling. */
    private function isSocket(): bool
    {
        // 'stream_type' is a documented key of stream_get_meta_data()'s
        // return array, always present -- no ?? fallback needed.
        return str_contains(strtolower((string) stream_get_meta_data($this->in)['stream_type']), 'socket');
    }

    private function hasBufferedInput(): bool
    {
        $meta = stream_get_meta_data($this->in);

        // 'unread_bytes' is a documented key of stream_get_meta_data()'s
        // return array, always present -- no ?? fallback needed.
        return $meta['unread_bytes'] > 0;
    }

    private function pollForInput(float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            if ($this->hasBufferedInput()) {
                return true;
            }

            // is_array() guards fstat()'s documented false-on-error return;
            // 'size' itself is one of fstat()'s own always-present keys once
            // it succeeds, so no ?? fallback is needed on top of that.
            $stat = @fstat($this->in);
            if (is_array($stat) && $stat['size'] > 0) {
                return true;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                return false;
            }

            usleep((int) min(5_000, max(1, $remaining * 1_000_000)));
        }
    }

    public function write(array $message): void
    {
        $body = json_encode($message, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('failed to encode LSP message: ' . json_last_error_msg());
        }

        $frame = "Content-Length: " . strlen($body) . "\r\n\r\n" . $body;

        // fwrite() can return false (stream error) or fewer bytes than asked
        // for (a partial write, e.g. a full pipe interrupted by a signal).
        // Either way a silent return here would leave a client unable to
        // tell "the message never fully went out" from "nothing more to
        // send" -- the same discipline read() already applies to a short
        // fread() three lines up in this class.
        $written = fwrite($this->out, $frame);
        if ($written !== strlen($frame)) {
            throw new RuntimeException(sprintf(
                'LSP frame write failed or was short: expected %d bytes, wrote %s',
                strlen($frame),
                $written === false ? 'false (stream error)' : (string) $written,
            ));
        }

        fflush($this->out);
    }

    /**
     * PR #128 E2E review (blocking finding 2): reads the next message ONLY
     * if it becomes available within $timeoutSeconds, null otherwise --
     * 0.0 (the default) is a pure non-blocking peek.
     *
     * Self-review correction (second E2E pass, PR #128): this used to run
     * its own `stream_select()` call with a bare 0/0 timeout, independent
     * of `waitForInput()` -- and on windows-latest CI that call was
     * OBSERVED to hang (job #109299103869: a `read_frame()` timeout waiting
     * for `$/progress` begin, not merely the "reasoned, not observed"
     * degradation this docblock originally claimed). `waitForInput()`
     * already solves exactly this problem for #106's own debounce timer,
     * including the Windows case (a non-socket, file-backed STDIN pipe
     * polls via `PeekNamedPipe`/`fstat()` there instead of `select()`), so
     * this delegates to it rather than duplicating a narrower, broken
     * version of the same logic.
     *
     * Third self-review pass (PR #128, second E2E review round): a
     * zero-timeout peek right after writing `create` never actually saw a
     * real client's reply, which arrives milliseconds later over a real
     * transport, not instantly -- accepting a real $timeoutSeconds here is
     * what lets LspServer::isProgressCreateRefused() genuinely wait for it
     * instead.
     */
    public function tryRead(float $timeoutSeconds = 0.0): ?array
    {
        return $this->waitForInput($timeoutSeconds) ? $this->read() : null;
    }

    /**
     * Companion to tryRead(): a message read ahead that turned out not to
     * be what the caller was looking for, handed back for ordinary
     * dispatch on the very next read()/waitForInput() call rather than
     * dropped. See $pending's own docblock.
     */
    public function pushBack(array $message): void
    {
        array_unshift($this->pending, $message);
    }
}
