<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Lsp\DiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\LspServer;
use PHPUnit\Framework\TestCase;

/**
 * #52's handshake prototype plus #53's v1 scope: diagnostics on
 * didOpen/didSave, didClose clearing them, and codeAction building a
 * WorkspaceEdit from the fixes behind the last-published diagnostics.
 *
 * handle() now returns a LIST of frames (0, 1, or more) rather than a single
 * ?array -- a didOpen/didSave produces a publishDiagnostics NOTIFICATION,
 * which is not a reply to anything, so a single-response shape cannot carry
 * it. Every assertion below indexes into that list.
 */
final class LspServerTest extends TestCase
{
    /**
     * @param list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}> $fixes
     */
    private static function fakeSource(array $fixes): DiagnosticsSource
    {
        return new class ($fixes) implements DiagnosticsSource {
            public function __construct(private readonly array $fixes)
            {
            }

            public function diagnose(string $absolutePath): array
            {
                return ['fixes' => $this->fixes];
            }
        };
    }

    private static function fix(int $startLine, int $endLine, string $newText, string $rector): array
    {
        return [
            'range' => ['start' => ['line' => $startLine, 'character' => 0], 'end' => ['line' => $endLine, 'character' => 0]],
            'newText' => $newText,
            'rectors' => [$rector],
        ];
    }

    public function testInitializeReturnsCapabilitiesAndServerInfo(): void
    {
        $server = new LspServer('0.1.0-prototype');

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['processId' => null, 'rootUri' => null, 'capabilities' => []],
        ]);

        self::assertCount(1, $responses);
        $response = $responses[0];
        self::assertSame(1, $response['id']);
        self::assertSame('2.0', $response['jsonrpc']);
        self::assertTrue($response['result']['capabilities']['codeActionProvider']);
        self::assertSame(
            ['name' => 'rector-warm-lsp', 'version' => '0.1.0-prototype'],
            $response['result']['serverInfo'],
        );
    }

    public function testInitializedNotificationGetsNoReply(): void
    {
        $server = new LspServer('0.1.0-prototype');

        self::assertSame([], $server->handle(['jsonrpc' => '2.0', 'method' => 'initialized']));
    }

    public function testUnknownRequestGetsAMethodNotFoundError(): void
    {
        $server = new LspServer('0.1.0-prototype');

        $responses = $server->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'textDocument/hover']);

        self::assertSame(7, $responses[0]['id']);
        self::assertSame(-32601, $responses[0]['error']['code']);
    }

    public function testUnknownNotificationIsIgnored(): void
    {
        $server = new LspServer('0.1.0-prototype');

        self::assertSame([], $server->handle(['jsonrpc' => '2.0', 'method' => '$/some-unknown-notification']));
    }

    public function testShutdownRepliesWithNullResultAndFlagsShuttingDown(): void
    {
        $server = new LspServer('0.1.0-prototype');

        self::assertFalse($server->isShuttingDown());
        $responses = $server->handle(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'shutdown']);

        self::assertSame(9, $responses[0]['id']);
        self::assertNull($responses[0]['result']);
        self::assertTrue($server->isShuttingDown());
    }

    public function testDidOpenTurnsTheUriIntoAPlainFilesystemPath(): void
    {
        // Self-review addition: uriToPath() had no direct test at all (every
        // fake diagnose() in this file used to ignore its argument). Pins the
        // POSIX case and the two Windows file:// shapes the docblock
        // distinguishes (plain drive letter vs. percent-encoded colon) --
        // the second is reasoned rather than observed (no Windows here), but
        // the string transformation itself is exercised on any platform.
        $seen = null;
        $capturing = new class ($seen) implements DiagnosticsSource {
            public function __construct(private mixed &$seen)
            {
            }

            public function diagnose(string $absolutePath): array
            {
                $this->seen = $absolutePath;

                return ['fixes' => []];
            }
        };

        $cases = [
            'file:///tmp/Sample.php' => '/tmp/Sample.php',
            'file:///C:/Users/x/Sample.php' => 'C:/Users/x/Sample.php',
            'file:///c%3A/Users/x/Sample.php' => 'c:/Users/x/Sample.php',
        ];

        foreach ($cases as $uri => $expectedPath) {
            $seen = null;
            $server = new LspServer('1.0.0', $capturing);
            $server->handle([
                'jsonrpc' => '2.0',
                'method' => 'textDocument/didOpen',
                'params' => ['textDocument' => ['uri' => $uri, 'version' => 1]],
            ]);
            self::assertSame($expectedPath, $seen, $uri);
        }
    }

    public function testDidOpenPublishesOneDiagnosticPerFix(): void
    {
        $fixes = [self::fix(3, 12, "fixed\n", 'Rector\\CodeQuality\\Rector\\If_\\SimplifyIfReturnBoolRector')];
        $server = new LspServer('1.0.0', self::fakeSource($fixes));

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        self::assertCount(1, $responses);
        $notification = $responses[0];
        self::assertSame('textDocument/publishDiagnostics', $notification['method']);
        self::assertSame('file:///tmp/Sample.php', $notification['params']['uri']);
        self::assertCount(1, $notification['params']['diagnostics']);
        $diagnostic = $notification['params']['diagnostics'][0];
        self::assertSame($fixes[0]['range'], $diagnostic['range']);
        self::assertSame('rector', $diagnostic['source']);
        self::assertSame('Rector\\CodeQuality\\Rector\\If_\\SimplifyIfReturnBoolRector', $diagnostic['message']);
    }

    public function testDidOpenPublishesAnErrorDiagnosticForAnErrorsEntryWithNoQuickfix(): void
    {
        // #90: an `errors` entry (e.g. a syntax error) has no fix behind it
        // -- it must still reach the editor as an Error-severity diagnostic,
        // and must NOT be offered as a quickfix (no `data.hunkIndex`, and it
        // never enters fixesByUri, so codeAction cannot build a
        // WorkspaceEdit for it that would do nothing when applied).
        $source = new class implements DiagnosticsSource {
            public function diagnose(string $absolutePath): array
            {
                return ['fixes' => [], 'errors' => [['message' => 'Syntax error, unexpected token', 'line' => 7]]];
            }
        };
        $server = new LspServer('1.0.0', $source);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Broken.php', 'version' => 1]],
        ]);

        self::assertCount(1, $responses);
        $diagnostics = $responses[0]['params']['diagnostics'];
        self::assertCount(1, $diagnostics);
        self::assertSame(1, $diagnostics[0]['severity']);
        self::assertSame('Syntax error, unexpected token', $diagnostics[0]['message']);
        self::assertSame(['line' => 6, 'character' => 0], $diagnostics[0]['range']['start']);
        self::assertArrayNotHasKey('data', $diagnostics[0]);

        $codeActionResponses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'textDocument/codeAction',
            'params' => [
                'textDocument' => ['uri' => 'file:///tmp/Broken.php'],
                'range' => ['start' => ['line' => 6, 'character' => 0], 'end' => ['line' => 6, 'character' => 0]],
                'context' => ['diagnostics' => []],
            ],
        ]);

        // No quickfix at all for the error line -- fixesByUri is empty, so
        // only the (in this case pointless, but harmless) whole-file action
        // would be offered if there were any fixes; there are none.
        self::assertSame([], $codeActionResponses[0]['result']);
    }

    public function testDidSaveOnAnUnchangedFilePublishesEmptyDiagnostics(): void
    {
        // Positive control for the case above, and the negative control the
        // repo convention (CLAUDE.md) asks for: a file Rector does not touch
        // must publish an EMPTY list, not simply skip publishing (which would
        // leave a stale diagnostic on screen after the code was fixed by hand).
        $server = new LspServer('1.0.0', self::fakeSource([]));

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didSave',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Clean.php', 'version' => 1]],
        ]);

        self::assertCount(1, $responses);
        self::assertSame([], $responses[0]['params']['diagnostics']);
    }

    public function testDidCloseClearsDiagnostics(): void
    {
        $fixes = [self::fix(0, 1, "x\n", 'SomeRector')];
        $server = new LspServer('1.0.0', self::fakeSource($fixes));
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didClose',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php']],
        ]);

        self::assertSame('textDocument/publishDiagnostics', $responses[0]['method']);
        self::assertSame([], $responses[0]['params']['diagnostics']);
    }

    public function testCodeActionBuildsAWorkspaceEditFromTheMatchingFixPlusAWholeFileAction(): void
    {
        $fixes = [self::fix(3, 12, "fixed\n", 'SimplifyIfReturnBoolRector')];
        $server = new LspServer('1.0.0', self::fakeSource($fixes));
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'textDocument/codeAction',
            'params' => [
                'textDocument' => ['uri' => 'file:///tmp/Sample.php'],
                'range' => ['start' => ['line' => 3, 'character' => 0], 'end' => ['line' => 12, 'character' => 0]],
                'context' => ['diagnostics' => []],
            ],
        ]);

        $actions = $responses[0]['result'];
        self::assertCount(2, $actions);
        self::assertSame('Apply Rector: SimplifyIfReturnBoolRector', $actions[0]['title']);
        $edit = $actions[0]['edit']['changes']['file:///tmp/Sample.php'][0];
        self::assertSame($fixes[0]['range'], $edit['range']);
        self::assertSame('fixed' . "\n", $edit['newText']);
        self::assertSame('Apply all Rector fixes', $actions[1]['title']);
    }

    public function testCodeActionWithAZeroWidthCursorOnTheFixsFirstLineStillOffersIt(): void
    {
        // Self-review finding on #53: a real editor commonly sends a
        // zero-width (no-selection) request range at the cursor position.
        // Half-open interval overlap treats an empty interval as never
        // overlapping anything, so a naive implementation drops the quick
        // fix exactly when the cursor sits on the fix's OWN first line --
        // the most likely place to invoke it from.
        $fixes = [self::fix(3, 12, "fixed\n", 'SimplifyIfReturnBoolRector')];
        $server = new LspServer('1.0.0', self::fakeSource($fixes));
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'textDocument/codeAction',
            'params' => [
                'textDocument' => ['uri' => 'file:///tmp/Sample.php'],
                'range' => ['start' => ['line' => 3, 'character' => 0], 'end' => ['line' => 3, 'character' => 0]],
                'context' => ['diagnostics' => []],
            ],
        ]);

        $actions = $responses[0]['result'];
        self::assertCount(2, $actions);
        self::assertSame('Apply Rector: SimplifyIfReturnBoolRector', $actions[0]['title']);
    }

    public function testCodeActionOutsideTheFixRangeIsNotOffered(): void
    {
        // Negative-control pairing for the test above: a requested range that
        // does not overlap the fix must not surface the quickfix (the
        // whole-file action stays offered regardless of the requested range).
        $fixes = [self::fix(3, 12, "fixed\n", 'SimplifyIfReturnBoolRector')];
        $server = new LspServer('1.0.0', self::fakeSource($fixes));
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'textDocument/codeAction',
            'params' => [
                'textDocument' => ['uri' => 'file:///tmp/Sample.php'],
                'range' => ['start' => ['line' => 50, 'character' => 0], 'end' => ['line' => 51, 'character' => 0]],
                'context' => ['diagnostics' => []],
            ],
        ]);

        $actions = $responses[0]['result'];
        self::assertCount(1, $actions);
        self::assertSame('Apply all Rector fixes', $actions[0]['title']);
    }

    public function testAStaleResultIsDiscardedWhenTheVersionChangedMidCall(): void
    {
        // #53's version-pinning requirement: a diagnose() call that (from the
        // server's point of view) takes long enough for a NEWER didSave for
        // the same file to land and complete first must have its own,
        // now-stale result thrown away rather than published over the newer
        // one. Modelled here by having the fake source itself re-enter
        // handle() with a newer version before returning -- the only way a
        // single-threaded synchronous handle() can be raced in a unit test.
        $holder = new class {
            public ?LspServer $server = null;
        };
        $racy = new class ($holder) implements DiagnosticsSource {
            private bool $raced = false;

            public function __construct(private readonly object $holder)
            {
            }

            public function diagnose(string $absolutePath): array
            {
                if (!$this->raced) {
                    $this->raced = true;
                    $this->holder->server->handle([
                        'jsonrpc' => '2.0',
                        'method' => 'textDocument/didSave',
                        'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 2]],
                    ]);
                }

                return ['fixes' => [['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]], 'newText' => "stale\n", 'rectors' => ['Stale']]]];
            }
        };
        $server = new LspServer('1.0.0', $racy);
        $holder->server = $server;

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didSave',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        // The version:1 call's own result must be discarded -- no frame at
        // all from it, since the reentrant version:2 call already published.
        self::assertSame([], $responses);
    }
}
