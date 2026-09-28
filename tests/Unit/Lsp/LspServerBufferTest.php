<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\BufferDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\LspServer;
use PHPUnit\Framework\TestCase;

/**
 * #106: diagnostics on unsaved buffers. didChange (full sync) stores the
 * buffer and schedules a debounced run; runDueDiagnostics() computes it once
 * the debounce has elapsed; takeReadyDiagnostics() publishes it only if the
 * document's version is still the one the run started from.
 */
final class LspServerBufferTest extends TestCase
{
    private const URI = 'file:///tmp/Sample.php';

    /** Far enough in the future that every debounce deadline has passed. */
    private const LATER = 1.0e12;

    /**
     * A buffer-capable fake: content containing "fixable" yields one fix,
     * content containing "broken" yields one error, anything else is clean.
     * Records every call so a test can assert on what ran.
     */
    private static function source(): BufferDiagnosticsSource
    {
        return new class implements BufferDiagnosticsSource {
            /** @var list<array{kind: string, path: string, content: ?string}> */
            public array $calls = [];

            public function diagnose(string $absolutePath): array
            {
                $this->calls[] = ['kind' => 'disk', 'path' => $absolutePath, 'content' => null];

                return ['fixes' => []];
            }

            public function diagnoseBuffer(string $absolutePath, string $content): array
            {
                $this->calls[] = ['kind' => 'buffer', 'path' => $absolutePath, 'content' => $content];

                if (str_contains($content, 'broken')) {
                    return ['fixes' => [], 'errors' => [['message' => 'Syntax error, unexpected EOF', 'line' => 3]]];
                }

                if (str_contains($content, 'fixable')) {
                    return ['fixes' => [[
                        'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 2, 'character' => 0]],
                        'newText' => "fixed\n",
                        'rectors' => ['SimplifyIfReturnBoolRector'],
                    ]]];
                }

                return ['fixes' => []];
            }
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function change(LspServer $server, int $version, string $text): array
    {
        return $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => ['uri' => self::URI, 'version' => $version],
                'contentChanges' => [['text' => $text]],
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function codeAction(LspServer $server): array
    {
        return $server->handle([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'textDocument/codeAction',
            'params' => [
                'textDocument' => ['uri' => self::URI],
                'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]],
                'context' => ['diagnostics' => []],
            ],
        ])[0]['result'];
    }

    public function testInitializeAdvertisesFullSyncWhenTheSourceCanDiagnoseBuffers(): void
    {
        $server = new LspServer('1.0.0', self::source());

        $response = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => []]]);

        self::assertSame(1, $response[0]['result']['capabilities']['textDocumentSync']['change']);
    }

    public function testInitializeKeepsSyncNoneWhenTheSourceCannotDiagnoseBuffers(): void
    {
        $server = new LspServer('1.0.0');

        $response = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => []]]);

        self::assertSame(0, $response[0]['result']['capabilities']['textDocumentSync']['change']);
    }

    public function testADidChangeWithFixableContentPublishesADiagnosticWithoutAnySave(): void
    {
        // Must fire: no didOpen, no didSave -- only the buffer.
        $source = self::source();
        $server = new LspServer('1.0.0', $source);

        self::assertSame([], self::change($server, 2, "<?php\nfixable\n"));
        self::assertNotNull($server->nextDiagnosticsDeadline());

        $server->runDueDiagnostics(self::LATER);
        $frames = $server->takeReadyDiagnostics();

        self::assertCount(1, $frames);
        self::assertSame('textDocument/publishDiagnostics', $frames[0]['method']);
        self::assertSame(self::URI, $frames[0]['params']['uri']);
        self::assertSame(2, $frames[0]['params']['version']);
        self::assertCount(1, $frames[0]['params']['diagnostics']);
        self::assertSame('SimplifyIfReturnBoolRector', $frames[0]['params']['diagnostics'][0]['message']);
        self::assertSame([['kind' => 'buffer', 'path' => '/tmp/Sample.php', 'content' => "<?php\nfixable\n"]], $source->calls);
        self::assertNull($server->nextDiagnosticsDeadline());
    }

    public function testNothingRunsBeforeTheDebounceHasElapsed(): void
    {
        // Negative control for the debounce: a change followed immediately
        // by a tick must not call Rector yet. Paired with the must-fire test
        // above, which ticks after the deadline.
        $source = self::source();
        $server = new LspServer('1.0.0', $source, 30.0);

        self::change($server, 1, "<?php\nfixable\n");
        $server->runDueDiagnostics(microtime(true));

        self::assertSame([], $source->calls);
        self::assertSame([], $server->takeReadyDiagnostics());
    }

    public function testRapidChangesRunRectorOnceOnTheLatestText(): void
    {
        $source = self::source();
        $server = new LspServer('1.0.0', $source);

        self::change($server, 1, "<?php\nfix\n");
        self::change($server, 2, "<?php\nfixa\n");
        self::change($server, 3, "<?php\nfixable\n");
        $server->runDueDiagnostics(self::LATER);

        self::assertCount(1, $source->calls);
        self::assertSame("<?php\nfixable\n", $source->calls[0]['content']);
        self::assertSame(3, $server->takeReadyDiagnostics()[0]['params']['version']);
    }

    public function testAResultForVersionNIsNotPublishedOnceVersionNPlusOneHasArrived(): void
    {
        // Must not fire: version 1's run completed, then version 2 arrived
        // before the result went out -- version 1's result is dropped.
        $source = self::source();
        $server = new LspServer('1.0.0', $source);

        self::change($server, 1, "<?php\nfixable\n");
        $server->runDueDiagnostics(self::LATER);
        self::change($server, 2, "<?php\nclean\n");

        self::assertSame([], $server->takeReadyDiagnostics());

        // Positive control: version 2 is then diagnosed and published.
        $server->runDueDiagnostics(self::LATER);
        $frames = $server->takeReadyDiagnostics();
        self::assertCount(1, $frames);
        self::assertSame(2, $frames[0]['params']['version']);
        self::assertSame([], $frames[0]['params']['diagnostics']);
    }

    public function testABufferChangedToCleanContentPublishesAnEmptyList(): void
    {
        $server = new LspServer('1.0.0', self::source());

        self::change($server, 1, "<?php\nfixable\n");
        $server->runDueDiagnostics(self::LATER);
        self::assertCount(1, $server->takeReadyDiagnostics()[0]['params']['diagnostics']);

        self::change($server, 2, "<?php\nclean\n");
        $server->runDueDiagnostics(self::LATER);
        $frames = $server->takeReadyDiagnostics();

        self::assertCount(1, $frames);
        self::assertSame([], $frames[0]['params']['diagnostics']);
    }

    public function testABrokenBufferPublishesTheErrorDiagnostic(): void
    {
        $server = new LspServer('1.0.0', self::source());

        self::change($server, 1, "<?php\nbroken\n");
        $server->runDueDiagnostics(self::LATER);
        $diagnostics = $server->takeReadyDiagnostics()[0]['params']['diagnostics'];

        self::assertCount(1, $diagnostics);
        self::assertSame(1, $diagnostics[0]['severity']);
        self::assertSame(2, $diagnostics[0]['range']['start']['line']);
    }

    public function testCodeActionsUseTheDiagnosticsVersionAndGoQuietOnceTheBufferMovesOn(): void
    {
        $server = new LspServer('1.0.0', self::source());

        self::change($server, 1, "<?php\nfixable\n");
        $server->runDueDiagnostics(self::LATER);
        $server->takeReadyDiagnostics();

        // Must fire: the fix computed for version 1 is offered while the
        // buffer is still at version 1.
        $actions = self::codeAction($server);
        self::assertNotSame([], $actions);
        self::assertSame("fixed\n", $actions[0]['edit']['changes'][self::URI][0]['newText']);

        // Must not fire: the buffer is now at version 2, so ranges computed
        // for version 1 would land on the wrong text.
        self::change($server, 2, "<?php\n\nfixable\n");
        self::assertSame([], self::codeAction($server));
    }

    public function testCodeActionsCarryTheVersionWhenTheClientSupportsDocumentChanges(): void
    {
        $server = new LspServer('1.0.0', self::source());
        $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => [
            'workspace' => ['workspaceEdit' => ['documentChanges' => true]],
        ]]]);

        self::change($server, 4, "<?php\nfixable\n");
        $server->runDueDiagnostics(self::LATER);
        $server->takeReadyDiagnostics();

        $edit = self::codeAction($server)[0]['edit'];
        self::assertArrayNotHasKey('changes', $edit);
        self::assertSame(['uri' => self::URI, 'version' => 4], $edit['documentChanges'][0]['textDocument']);
        self::assertSame("fixed\n", $edit['documentChanges'][0]['edits'][0]['newText']);
    }

    public function testDidCloseDropsThePendingBuffer(): void
    {
        $source = self::source();
        $server = new LspServer('1.0.0', $source);

        self::change($server, 1, "<?php\nfixable\n");
        $closed = $server->handle(['jsonrpc' => '2.0', 'method' => 'textDocument/didClose', 'params' => ['textDocument' => ['uri' => self::URI]]]);
        self::assertSame([], $closed[0]['params']['diagnostics']);

        self::assertNull($server->nextDiagnosticsDeadline());
        $server->runDueDiagnostics(self::LATER);
        self::assertSame([], $source->calls);
        self::assertSame([], $server->takeReadyDiagnostics());
    }

    public function testDidSaveDiagnosesFromDiskAndCancelsThePendingBufferRun(): void
    {
        $source = self::source();
        $server = new LspServer('1.0.0', $source);

        self::change($server, 1, "<?php\nfixable\n");
        $saved = $server->handle(['jsonrpc' => '2.0', 'method' => 'textDocument/didSave', 'params' => ['textDocument' => ['uri' => self::URI]]]);

        self::assertCount(1, $saved);
        self::assertSame('disk', $source->calls[0]['kind']);
        self::assertNull($server->nextDiagnosticsDeadline());
        $server->runDueDiagnostics(self::LATER);
        self::assertCount(1, $source->calls);
    }
}
