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

    public function testInitializedNotificationRegistersAConfigFileWatcher(): void
    {
        // #101: `initialized` used to produce no reply at all. Now it sends
        // a `client/registerCapability` REQUEST (server -> client) asking
        // to be told about changes to rector.php/composer.lock, so the
        // server can react to an edit made outside any didOpen/didSave --
        // the missing trigger the issue names. This is a request, not a
        // notification, so it carries an id the client is expected to
        // reply to (the reply itself needs no handling: nothing in this
        // server depends on its result).
        $server = new LspServer('0.1.0-prototype');

        $responses = $server->handle(['jsonrpc' => '2.0', 'method' => 'initialized']);

        self::assertCount(1, $responses);
        $request = $responses[0];
        self::assertSame('2.0', $request['jsonrpc']);
        self::assertSame('client/registerCapability', $request['method']);
        self::assertArrayHasKey('id', $request);

        $registrations = $request['params']['registrations'];
        self::assertCount(1, $registrations);
        self::assertSame('workspace/didChangeWatchedFiles', $registrations[0]['method']);

        $patterns = array_column($registrations[0]['registerOptions']['watchers'], 'globPattern');
        self::assertSame(['**/rector.php', '**/composer.lock'], $patterns);
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

    public function testDidOpenOnAUncPathBuildsAWindowsUncPathDeliberately(): void
    {
        // #99: `file://server/share/A.php` puts `server` in parse_url()'s
        // HOST component -- uriToPath() used to read only PHP_URL_PATH, so
        // the host was silently dropped and the path collapsed to
        // `/share/A.php` (a plausible-looking but wrong, host-free path).
        // Deliberate handling: fold the host back in as a `\\host\share`
        // UNC prefix, backslash-separated, the form Windows itself expects.
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

        $server = new LspServer('1.0.0', $capturing);
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file://server/share/A.php', 'version' => 1]],
        ]);

        self::assertSame('\\\\server\\share\\A.php', $seen);
    }

    public function testDidOpenOnALocalhostAuthorityIsNotTreatedAsUnc(): void
    {
        // Negative control for the case above: `file://localhost/...` is
        // RFC 8089's spelling for an empty authority, not a UNC host -- it
        // must resolve exactly like the bare `file:///...` form, never grow
        // a `\\localhost\` prefix.
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

        $server = new LspServer('1.0.0', $capturing);
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file://localhost/tmp/Sample.php', 'version' => 1]],
        ]);

        self::assertSame('/tmp/Sample.php', $seen);
    }

    public function testWatchedRectorConfigChangeReDiagnosesEveryOpenDocument(): void
    {
        // #101: a `rector.php` edit made without the editor re-saving any
        // open PHP file (a different tool touched it, or the editor never
        // watches it itself) must still reach every currently-open
        // document once the client reports the watched-file change -- the
        // trigger the issue says is missing. RectorRunner already reloads
        // the config on the NEXT process() call on its own (#101's
        // "already-shipped" half); this only has to prove the re-diagnose
        // is actually requested for both open documents.
        $source = new class implements DiagnosticsSource {
            private int $calls = 0;

            public function diagnose(string $absolutePath): array
            {
                $this->calls++;
                $rector = $this->calls <= 2 ? 'RuleBeforeConfigChange' : 'RuleAfterConfigChange';

                return ['fixes' => [[
                    'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]],
                    'newText' => "x\n",
                    'rectors' => [$rector],
                ]]];
            }
        };

        $server = new LspServer('1.0.0', $source);
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/A.php', 'version' => 1]],
        ]);
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/B.php', 'version' => 1]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => ['changes' => [['uri' => 'file:///tmp/rector.php', 'type' => 2]]],
        ]);

        self::assertCount(2, $responses);
        $uris = array_map(static fn (array $r): string => $r['params']['uri'], $responses);
        sort($uris);
        self::assertSame(['file:///tmp/A.php', 'file:///tmp/B.php'], $uris);
        foreach ($responses as $notification) {
            self::assertSame('textDocument/publishDiagnostics', $notification['method']);
            self::assertSame('RuleAfterConfigChange', $notification['params']['diagnostics'][0]['message']);
        }
    }

    public function testWatchedComposerLockChangeAlsoReDiagnoses(): void
    {
        // Positive control's sibling: composer.lock is the OTHER file the
        // issue names ("register for rector.php and composer.lock").
        $fixes = [self::fix(0, 1, "x\n", 'AnyRector')];
        $server = new LspServer('1.0.0', self::fakeSource($fixes));
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/A.php', 'version' => 1]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => ['changes' => [['uri' => 'file:///tmp/composer.lock', 'type' => 2]]],
        ]);

        self::assertCount(1, $responses);
        self::assertSame('file:///tmp/A.php', $responses[0]['params']['uri']);
    }

    public function testWatchedFileChangeToAnUnrelatedFileTriggersNoRediagnosis(): void
    {
        // Negative control: an event for a file that is neither rector.php
        // nor composer.lock must not blindly re-diagnose every open
        // document -- otherwise this would fire on any workspace edit at
        // all, not just a config change.
        $fixes = [self::fix(0, 1, "x\n", 'AnyRector')];
        $server = new LspServer('1.0.0', self::fakeSource($fixes));
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/A.php', 'version' => 1]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => ['changes' => [['uri' => 'file:///tmp/Unrelated.php', 'type' => 2]]],
        ]);

        self::assertSame([], $responses);
    }

    public function testWatchedConfigChangeWithNoOpenDocumentsProducesNoNotifications(): void
    {
        // Second negative control: nothing is open to republish for.
        $server = new LspServer('1.0.0', self::fakeSource([]));

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => ['changes' => [['uri' => 'file:///tmp/rector.php', 'type' => 2]]],
        ]);

        self::assertSame([], $responses);
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

    public function testAFixWithNoAttributedRectorsGetsAGenericLabelNotABlankOne(): void
    {
        // #91 self-review finding: RectorDiffParser's closest-hunk
        // attribution can legitimately leave a hunk's `rectors` list empty.
        // The diagnostic message and the quickfix title must never go blank
        // as a result.
        $fixes = [self::fix(3, 12, "fixed\n", '')];
        $fixes[0]['rectors'] = [];
        $server = new LspServer('1.0.0', self::fakeSource($fixes));

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        self::assertSame('Rector fix', $responses[0]['params']['diagnostics'][0]['message']);

        $codeActionResponses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'textDocument/codeAction',
            'params' => [
                'textDocument' => ['uri' => 'file:///tmp/Sample.php'],
                'range' => ['start' => ['line' => 3, 'character' => 0], 'end' => ['line' => 12, 'character' => 0]],
                'context' => ['diagnostics' => []],
            ],
        ]);

        self::assertSame('Apply Rector: Rector fix', $codeActionResponses[0]['result'][0]['title']);
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

    public function testCodeActionAtAnInsertOnlyHunksOwnZeroWidthRangeOffersItsQuickfix(): void
    {
        // #93 regression: #92 narrowed diagnostic ranges to the changed
        // lines, so a pure-insertion hunk (e.g. NewlineAfterStatementRector
        // adding a blank line) now gets a zero-width diagnostic range like
        // `66:0-66:0`. The half-open overlap test treated an empty $b as
        // never overlapping anything, so a codeAction request using the
        // diagnostic's own range (exactly what a real editor sends for a
        // cursor on that line) returned no per-hunk quickfix at all.
        $fixes = [self::fix(66, 66, "\n", 'NewlineAfterStatementRector')];
        $server = new LspServer('1.0.0', self::fakeSource($fixes));
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'textDocument/codeAction',
            'params' => [
                'textDocument' => ['uri' => 'file:///tmp/Sample.php'],
                'range' => ['start' => ['line' => 66, 'character' => 0], 'end' => ['line' => 66, 'character' => 0]],
                'context' => ['diagnostics' => []],
            ],
        ]);

        $actions = $responses[0]['result'];
        self::assertCount(2, $actions);
        self::assertSame('Apply Rector: NewlineAfterStatementRector', $actions[0]['title']);
    }

    public function testCodeActionOnAnUnrelatedLineNearAnInsertOnlyHunkIsNotOffered(): void
    {
        // Negative-control pairing for the test above: a zero-width request
        // range on a DIFFERENT line than an insert-only hunk's own
        // zero-width diagnostic range must not surface that quickfix.
        $fixes = [self::fix(66, 66, "\n", 'NewlineAfterStatementRector')];
        $server = new LspServer('1.0.0', self::fakeSource($fixes));
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'textDocument/codeAction',
            'params' => [
                'textDocument' => ['uri' => 'file:///tmp/Sample.php'],
                'range' => ['start' => ['line' => 70, 'character' => 0], 'end' => ['line' => 70, 'character' => 0]],
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
