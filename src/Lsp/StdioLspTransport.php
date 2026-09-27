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
    /** @var resource */
    private $in;

    /** @var resource */
    private $out;

    /**
     * @param resource $in
     * @param resource $out
     */
    public function __construct($in, $out)
    {
        $this->in = $in;
        $this->out = $out;
    }

    /**
     * Reads one framed message. Returns null only at a CLEAN EOF -- nothing
     * pending, the stream simply ended between frames. A frame that starts
     * and then breaks (missing/short header, truncated body) is not EOF: it
     * throws, so a dropped byte is never silently read back as "nothing sent".
     */
    public function read(): ?array
    {
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

    public function write(array $message): void
    {
        $body = json_encode($message, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('failed to encode LSP message: ' . json_last_error_msg());
        }

        fwrite($this->out, "Content-Length: " . strlen($body) . "\r\n\r\n" . $body);
        fflush($this->out);
    }
}
