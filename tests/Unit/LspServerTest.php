<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Lsp\LspServer;
use PHPUnit\Framework\TestCase;

/**
 * The v1 prototype for #52/#53: enough of the handshake to prove a hand-rolled
 * JSON-RPC-over-stdio server answers `initialize`, without pulling in an
 * event-loop-based library (see docs/decisions/0001-lsp-library-choice.md).
 * Diagnostics and code actions are #53's scope, not this one's.
 */
final class LspServerTest extends TestCase
{
    public function testInitializeReturnsCapabilitiesAndServerInfo(): void
    {
        $server = new LspServer('0.1.0-prototype');

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['processId' => null, 'rootUri' => null, 'capabilities' => []],
        ]);

        self::assertSame(1, $response['id']);
        self::assertSame('2.0', $response['jsonrpc']);
        self::assertArrayHasKey('capabilities', $response['result']);
        self::assertSame(
            ['name' => 'rector-warm-lsp', 'version' => '0.1.0-prototype'],
            $response['result']['serverInfo'],
        );
    }

    public function testInitializedNotificationGetsNoReply(): void
    {
        // Positive control for the request/notification split below: a
        // notification (no "id" key at all) must not get a response frame.
        $server = new LspServer('0.1.0-prototype');

        self::assertNull($server->handle(['jsonrpc' => '2.0', 'method' => 'initialized']));
    }

    public function testUnknownRequestGetsAMethodNotFoundError(): void
    {
        // The failing-loud counterpart to the notification case above: a
        // REQUEST (has "id") for an unimplemented method must still get a
        // reply, or a client would hang waiting for one that never comes.
        $server = new LspServer('0.1.0-prototype');

        $response = $server->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'textDocument/hover']);

        self::assertSame(7, $response['id']);
        self::assertSame(-32601, $response['error']['code']);
    }

    public function testUnknownNotificationIsIgnored(): void
    {
        $server = new LspServer('0.1.0-prototype');

        self::assertNull($server->handle(['jsonrpc' => '2.0', 'method' => '$/some-unknown-notification']));
    }

    public function testShutdownRepliesWithNullResultAndFlagsShuttingDown(): void
    {
        $server = new LspServer('0.1.0-prototype');

        self::assertFalse($server->isShuttingDown());
        $response = $server->handle(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'shutdown']);

        self::assertSame(9, $response['id']);
        self::assertNull($response['result']);
        self::assertTrue($server->isShuttingDown());
    }
}
