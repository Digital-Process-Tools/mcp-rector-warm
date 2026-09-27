<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

use stdClass;

/**
 * v1 prototype for #52 (decide the wire approach) / #53 (build v1): answers
 * enough of the LSP handshake to prove the hand-rolled-JSON-RPC decision
 * works end to end. Diagnostics (didOpen/didSave -> publishDiagnostics) and
 * codeAction are #53's scope, built on the same warm RectorRunner/RectorTool
 * core -- not here.
 */
final class LspServer
{
    private bool $shuttingDown = false;

    public function __construct(private readonly string $serverVersion)
    {
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null the response frame to write, or null
     *     when the message needs no reply (a notification, or one this
     *     prototype does not implement yet)
     */
    public function handle(array $message): ?array
    {
        $method = $message['method'] ?? null;
        $isRequest = array_key_exists('id', $message);
        $id = $message['id'] ?? null;

        if ($method === 'initialize') {
            return $this->result($id, [
                // Empty on purpose: no textDocumentSync / codeActionProvider
                // yet, so a real client does not expect diagnostics from this
                // prototype. stdClass, not [], so this encodes as a JSON
                // object ({}) rather than an array ([]) -- the LSP spec
                // requires an object here.
                'capabilities' => new stdClass(),
                'serverInfo' => [
                    'name' => 'rector-warm-lsp',
                    'version' => $this->serverVersion,
                ],
            ]);
        }

        if ($method === 'initialized') {
            return null;
        }

        if ($method === 'shutdown') {
            $this->shuttingDown = true;

            return $this->result($id, null);
        }

        if ($isRequest) {
            return $this->error($id, -32601, sprintf('Method not found: %s', (string) $method));
        }

        return null;
    }

    public function isShuttingDown(): bool
    {
        return $this->shuttingDown;
    }

    /**
     * @param array<string, mixed>|null $result
     * @return array<string, mixed>
     */
    private function result(mixed $id, ?array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
