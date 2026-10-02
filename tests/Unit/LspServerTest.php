<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Tests\Support\Json;
use Dpt\McpRectorWarm\Lsp\BufferDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\DiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\EditDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\LspServer;
use Dpt\McpRectorWarm\Support\Scalar;
use Dpt\McpRectorWarm\Lsp\WorkspaceDiagnosticsSource;
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
            /** @param list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}> $fixes */
            public function __construct(private readonly array $fixes) {}

            public function diagnose(string $absolutePath): array
            {
                return ['fixes' => $this->fixes];
            }
        };
    }

    /** @return array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>} */
    private static function fix(int $startLine, int $endLine, string $newText, string $rector): array
    {
        return [
            'range' => ['start' => ['line' => $startLine, 'character' => 0], 'end' => ['line' => $endLine, 'character' => 0]],
            'newText' => $newText,
            'rectors' => [$rector],
        ];
    }

    /**
     * #102: a source that ALSO implements WorkspaceDiagnosticsSource, so
     * `initialize` advertises executeCommandProvider and
     * `workspace/executeCommand` has somewhere to go.
     *
     * @param array<string, list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>> $files absolute path -> fixes
     * @param list<array{message: string, line: int, file?: string}> $errors
     */
    private static function fakeWorkspaceSource(array $files, array $errors = []): DiagnosticsSource
    {
        return new class ($files, $errors) implements DiagnosticsSource, WorkspaceDiagnosticsSource {
            /**
             * @param array<string, list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>> $files
             * @param list<array{message: string, line: int, file?: string}> $errors
             */
            public function __construct(private readonly array $files, private readonly array $errors) {}

            public function diagnose(string $absolutePath): array
            {
                return ['fixes' => $this->files[$absolutePath] ?? []];
            }

            public function diagnoseWorkspace(string $rootPath): array
            {
                return ['files' => $this->files, 'errors' => $this->errors];
            }
        };
    }

    /**
     * #140: same as fakeWorkspaceSource() but ALSO implements
     * BufferDiagnosticsSource, so a textDocument/didChange the test sends
     * actually populates LspServer's own $buffers[$uri] -- changeDocument()
     * only tracks a buffer when the configured diagnostics source
     * implements that interface. Without it, fixWorkspace's dirty-buffer
     * skip would have nothing to observe.
     *
     * @param array<string, list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>> $files absolute path -> fixes
     */
    private static function fakeWorkspaceAndBufferSource(array $files): DiagnosticsSource
    {
        return new class ($files) implements DiagnosticsSource, WorkspaceDiagnosticsSource, BufferDiagnosticsSource {
            /** @param array<string, list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>> $files */
            public function __construct(private readonly array $files) {}

            public function diagnose(string $absolutePath): array
            {
                return ['fixes' => $this->files[$absolutePath] ?? []];
            }

            public function diagnoseWorkspace(string $rootPath): array
            {
                return ['files' => $this->files, 'errors' => []];
            }

            public function diagnoseBuffer(string $absolutePath, string $content): array
            {
                return ['fixes' => []];
            }
        };
    }

    /**
     * #147 self-review finding (oss:auditor pass): building a `file://`
     * test URI via `'file://' . $path` (as the pre-existing case-fold test
     * above already does) breaks on a platform where sys_get_temp_dir()
     * returns a backslash-separated path -- parse_url() cannot read a
     * PHP_URL_PATH out of `file://C:\Users\...\A.php`, so uriToPath()
     * falls back to treating the whole raw URI string as the path, which
     * then fails stat() and routes the comparison away from the dev+ino
     * path these tests exist to exercise. Mirrors LspServer::pathToUri()'s
     * own backslash normalisation, without its percent-encoding -- these
     * tests deliberately want a literal, non-percent-encoded URI on one
     * side.
     */
    private static function filePathToTestUri(string $absolutePath): string
    {
        // #147 round-2 self-review finding, from a real windows-latest CI
        // failure (not reasoned -- observed): this helper normalised
        // backslash separators but did not reproduce
        // LspServer::pathToUri()'s OTHER piece of Windows handling -- a
        // leading '/' inserted before a drive letter, so `file://` plus a
        // bare `C:/...` collapses the URI's authority component onto the
        // drive letter instead of leaving it empty. `pathToUri()` itself
        // produces `file:///C:/...` (three slashes: scheme, empty
        // authority, then the drive-letter path) for exactly this shape;
        // this helper produced `file://C:/...` (two slashes), a URI this
        // test's own production-derived expectations never actually see
        // pathToUri() emit. Every windows-latest failure this caused was a
        // string-identity mismatch on the EXPECTED side, not a functional
        // regression: isBufferDirty() itself skipped/fixed correctly in
        // every case.
        $normalized = str_replace('\\', '/', $absolutePath);
        if (preg_match('#^[A-Za-z]:#', $normalized) === 1) {
            $normalized = '/' . $normalized;
        }

        return 'file://' . $normalized;
    }

    /**
     * #147 round-2 self-review finding, from the same windows-latest CI
     * failure as filePathToTestUri() above -- pinned directly rather than
     * only through the four higher-level fixWorkspace tests, so a future
     * change to this helper's drive-letter handling fails here first
     * rather than as a four-way array-identity mismatch on a platform
     * this developer's own machine cannot reproduce.
     */
    public function testFilePathToTestUriMatchesPathToUrisDriveLetterHandling(): void
    {
        self::assertSame('file:///C:/Users/x/A.php', self::filePathToTestUri('C:\\Users\\x\\A.php'));
        self::assertSame('file:///tmp/x/A.php', self::filePathToTestUri('/tmp/x/A.php'));
    }

    /**
     * #147 self-review finding (oss:auditor pass): the dev+ino comparison
     * this fix relies on is a no-op wherever stat() cannot report a real
     * inode (isBufferDirty()'s own docblock: ino === 0 on either side
     * falls back to the pre-existing case-insensitive comparison, which
     * does not exercise a symlinked or percent-encoded URI). Rather than
     * branching on PHP_OS_FAMILY -- which would assume every Windows PHP
     * build behaves the same way, an unverified claim -- this probes the
     * actual platform this test is running on and skips loudly, naming
     * what went untested, when the probe itself proves unreliable.
     */
    private static function skipIfInodesAreUnreliable(): void
    {
        $probe = @stat(__FILE__);
        if ($probe === false || $probe['ino'] === 0) {
            self::markTestSkipped(
                'This platform\'s stat() does not report a reliable inode (ino=0) -- '
                . 'isBufferDirty()\'s dev+ino identity check falls back to the '
                . 'pre-existing case-insensitive URI comparison here, which this test '
                . 'does not exercise.',
            );
        }
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
        self::assertTrue(Json::at($response, 'result', 'capabilities', 'codeActionProvider'));
        self::assertSame(
            ['name' => 'rector-warm-lsp', 'version' => '0.1.0-prototype'],
            Json::at($response, 'result', 'serverInfo'),
        );
        // #102: negative control for
        // testInitializeAdvertisesExecuteCommandProviderForAWorkspaceFixSource
        // below -- with no diagnostics source at all (this server), there
        // is nothing a fixWorkspace command could run, so it must not be
        // advertised.
        self::assertArrayNotHasKey('executeCommandProvider', Json::array($response, 'result', 'capabilities'));
    }

    /**
     * #102/#141: positive control -- a source that DOES implement
     * WorkspaceDiagnosticsSource, and a client that declared
     * `workspace.applyEdit`, makes `initialize` advertise the command. Paired
     * with the must-not-fire test directly below (same source, no
     * `applyEdit`): the docs (docs/lsp.md, docs/lsp-for-rector-maintainers.md)
     * always said the advertisement was gated on `workspace.applyEdit`, but
     * the code only ever checked the diagnostics source type -- this test
     * used to pass with `'capabilities' => []` (no applyEdit at all),
     * locking in that gap. #141 gates the advertisement on
     * `canApplyWorkspaceEdit` too, so this now needs the capability it
     * asserts is required.
     */
    public function testInitializeAdvertisesExecuteCommandProviderForAWorkspaceFixSource(): void
    {
        $server = new LspServer('0.1.0-prototype', self::fakeWorkspaceSource([]));

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => self::APPLY_EDIT_ONLY],
        ]);

        self::assertSame(
            ['commands' => ['rector-warm.fixWorkspace']],
            Json::at($responses, 0, 'result', 'capabilities', 'executeCommandProvider'),
        );
    }

    /**
     * #141 must-not-fire: paired with the positive control above (same
     * workspace-fix source). A client that never declared
     * `workspace.applyEdit` must not see `executeCommandProvider` advertised
     * at all -- running the command would only ever refuse for such a
     * client, and an advertised-but-always-refusing command is worse than
     * not advertising it (a real editor may grey out other UI on the
     * assumption an advertised command works).
     */
    public function testInitializeDoesNotAdvertiseExecuteCommandProviderWithoutApplyEditCapability(): void
    {
        $server = new LspServer('0.1.0-prototype', self::fakeWorkspaceSource([]));

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => []],
        ]);

        self::assertArrayNotHasKey('executeCommandProvider', Json::array($responses, 0, 'result', 'capabilities'));
    }

    /**
     * @param array<string, mixed> $capabilities
     */
    private static function initialize(LspServer $server, array $capabilities): void
    {
        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['processId' => null, 'rootUri' => null, 'capabilities' => $capabilities],
        ]);
    }

    private const WATCHED_FILES_DYNAMIC = [
        'workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => true]],
    ];

    public function testInitializedNotificationRegistersAConfigFileWatcher(): void
    {
        // #101: `initialized` used to produce no reply at all. Now it sends
        // a `client/registerCapability` REQUEST (server -> client) asking
        // to be told about changes to rector.php/composer.lock, so the
        // server can react to an edit made outside any didOpen/didSave --
        // the missing trigger the issue names. This is a request, not a
        // notification, so it carries an id the client is expected to
        // reply to (the reply itself needs no handling: nothing in this
        // server depends on its result -- see
        // testAResponseFromTheClientIsNeverAnswered).
        //
        // #105: only when the client declared it can take the registration
        // (`workspace.didChangeWatchedFiles.dynamicRegistration: true`).
        $server = new LspServer('0.1.0-prototype');
        self::initialize($server, self::WATCHED_FILES_DYNAMIC);

        $responses = $server->handle(['jsonrpc' => '2.0', 'method' => 'initialized']);

        self::assertCount(1, $responses);
        $request = $responses[0];
        self::assertSame('2.0', $request['jsonrpc']);
        self::assertSame('client/registerCapability', $request['method']);
        self::assertArrayHasKey('id', $request);

        $registrations = Json::array($request, 'params', 'registrations');
        self::assertCount(1, $registrations);
        self::assertSame('workspace/didChangeWatchedFiles', Json::at($registrations, 0, 'method'));

        $patterns = array_column(Json::array($registrations, 0, 'registerOptions', 'watchers'), 'globPattern');
        self::assertSame(['**/rector.php', '**/composer.lock'], $patterns);
    }

    public function testUnknownRequestGetsAMethodNotFoundError(): void
    {
        $server = new LspServer('0.1.0-prototype');

        $responses = $server->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'textDocument/hover']);

        self::assertSame(7, $responses[0]['id']);
        self::assertSame(-32601, Json::at($responses, 0, 'error', 'code'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function clientCapabilitiesWithoutDynamicWatchedFiles(): iterable
    {
        yield 'empty capabilities' => [[]];
        yield 'workspace without didChangeWatchedFiles' => [['workspace' => ['applyEdit' => true]]];
        yield 'dynamicRegistration false' => [['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => false]]]];
        yield 'didChangeWatchedFiles without dynamicRegistration' => [['workspace' => ['didChangeWatchedFiles' => []]]];
    }

    /**
     * #105: the LSP spec only lets a server register a capability
     * dynamically when the client declared `dynamicRegistration: true` for
     * it. Paired with testInitializedNotificationRegistersAConfigFileWatcher
     * (the must-fire half), so an `initialized` that silently produces
     * nothing for every client cannot pass both.
     *
     * @param array<string, mixed> $capabilities
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('clientCapabilitiesWithoutDynamicWatchedFiles')]
    public function testInitializedDoesNotRegisterWithoutTheClientCapability(array $capabilities): void
    {
        $server = new LspServer('0.1.0-prototype');
        self::initialize($server, $capabilities);

        self::assertSame([], $server->handle(['jsonrpc' => '2.0', 'method' => 'initialized']));
    }

    /**
     * #213: a `textDocument` that is not an object (a non-conforming
     * client) used to reach diagnoseDocument(array) as-is -- a TypeError out
     * of handle() that ended the server loop. It is now ignored, like every
     * other params member the spec does not allow. The positive control is
     * the well-formed didOpen right after it, on the same server.
     */
    public function testANonObjectTextDocumentIsIgnoredRatherThanCrashingTheServer(): void
    {
        $source = new class implements DiagnosticsSource {
            public int $calls = 0;

            public function diagnose(string $absolutePath): array
            {
                $this->calls++;

                return ['fixes' => []];
            }
        };
        $server = new LspServer('0.1.0-prototype', $source);
        self::initialize($server, []);

        foreach (['textDocument/didOpen', 'textDocument/didSave', 'textDocument/didClose'] as $method) {
            self::assertSame([], $server->handle([
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => ['textDocument' => 'file:///tmp/A.php'],
            ]));
        }
        self::assertSame(0, $source->calls);

        $opened = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/A.php', 'version' => 1, 'text' => '']],
        ]);
        self::assertSame(1, $source->calls);
        self::assertSame('textDocument/publishDiagnostics', Json::string($opened, 0, 'method'));
    }

    /**
     * #105, the other half of "not registered": with no watcher, a config
     * change still reaches diagnostics on the next didSave, because every
     * didSave calls the DiagnosticsSource again and the warm worker reloads
     * the config itself on that call (RectorRunner::configFileChanged(),
     * pinned in RectorRunnerTest and end to end in test_lsp_diagnostics.py).
     * This source stands in for that: its answer changes between the two
     * calls the way a reloaded config's does.
     */
    public function testWithoutTheWatcherTheNextSaveStillPublishesTheReloadedResult(): void
    {
        $source = new class implements DiagnosticsSource {
            public int $calls = 0;

            public function diagnose(string $absolutePath): array
            {
                $this->calls++;

                return $this->calls === 1
                    ? ['fixes' => [[
                        'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 2, 'character' => 0]],
                        'newText' => 'x',
                        'rectors' => ['BeforeConfigRector'],
                    ]]]
                    : ['fixes' => []];
            }
        };
        $server = new LspServer('0.1.0-prototype', $source);
        self::initialize($server, []);
        self::assertSame([], $server->handle(['jsonrpc' => '2.0', 'method' => 'initialized']));

        $uri = 'file:///tmp/A.php';
        $opened = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => $uri, 'version' => 1, 'text' => '']],
        ]);
        self::assertCount(1, Json::array($opened, 0, 'params', 'diagnostics'));

        $saved = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didSave',
            'params' => ['textDocument' => ['uri' => $uri]],
        ]);

        self::assertSame(2, $source->calls);
        self::assertSame('textDocument/publishDiagnostics', $saved[0]['method']);
        self::assertSame([], Json::at($saved, 0, 'params', 'diagnostics'));
    }

    /**
     * #105: a RESPONSE (id + result/error, no method) -- e.g. the client's
     * reply to this server's own client/registerCapability -- must never be
     * answered; JSON-RPC forbids replying to a response. Paired with
     * testUnknownRequestGetsAMethodNotFoundError (the must-still-fire half).
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function responseFrames(): iterable
    {
        yield 'result null' => [['jsonrpc' => '2.0', 'id' => 'rector-warm-lsp/config-watch', 'result' => null]];
        yield 'error' => [['jsonrpc' => '2.0', 'id' => 'rector-warm-lsp/config-watch', 'error' => ['code' => -32601, 'message' => 'nope']]];
        yield 'integer id' => [['jsonrpc' => '2.0', 'id' => 3, 'result' => ['ok' => true]]];
    }

    /**
     * @param array<string, mixed> $response
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('responseFrames')]
    public function testAResponseFromTheClientIsNeverAnswered(array $response): void
    {
        $server = new LspServer('0.1.0-prototype');

        self::assertSame([], $server->handle($response));
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
        $capturing = new class implements DiagnosticsSource {
            public ?string $seen = null;

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
            $capturing->seen = null;
            $server = new LspServer('1.0.0', $capturing);
            $server->handle([
                'jsonrpc' => '2.0',
                'method' => 'textDocument/didOpen',
                'params' => ['textDocument' => ['uri' => $uri, 'version' => 1]],
            ]);
            self::assertSame($expectedPath, $capturing->seen, $uri);
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
        $capturing = new class implements DiagnosticsSource {
            public ?string $seen = null;

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

        self::assertSame('\\\\server\\share\\A.php', $capturing->seen);
    }

    public function testDidOpenOnATwoSlashDriveLetterUriIsNotTreatedAsUnc(): void
    {
        // Self-review finding (independent Explore review pass): #99's UNC
        // fix folded ANY non-empty, non-localhost host into a `\\host\...`
        // UNC prefix -- but `file://c:/foo/bar.php` (a non-conformant but
        // real two-slash Windows drive-letter shape, RFC 8089 Appendix E)
        // puts the single-letter drive itself in parse_url()'s HOST, not
        // PATH. Folding that in as a UNC host produced the bogus
        // `\\c\foo\bar.php` instead of the intended `c:/foo/bar.php`. A
        // single-character host is a drive letter, never a real UNC server
        // name.
        $capturing = new class implements DiagnosticsSource {
            public ?string $seen = null;

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
            'params' => ['textDocument' => ['uri' => 'file://c:/foo/bar.php', 'version' => 1]],
        ]);

        self::assertSame('c:/foo/bar.php', $capturing->seen);
    }

    public function testDidOpenOnALocalhostAuthorityIsNotTreatedAsUnc(): void
    {
        // Negative control for the case above: `file://localhost/...` is
        // RFC 8089's spelling for an empty authority, not a UNC host -- it
        // must resolve exactly like the bare `file:///...` form, never grow
        // a `\\localhost\` prefix.
        $capturing = new class implements DiagnosticsSource {
            public ?string $seen = null;

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

        self::assertSame('/tmp/Sample.php', $capturing->seen);
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
        $uris = array_map(static fn(array $r): string => Json::string($r, 'params', 'uri'), $responses);
        sort($uris);
        self::assertSame(['file:///tmp/A.php', 'file:///tmp/B.php'], $uris);
        foreach ($responses as $notification) {
            self::assertSame('textDocument/publishDiagnostics', $notification['method']);
            self::assertSame('RuleAfterConfigChange', Json::at($notification, 'params', 'diagnostics', 0, 'message'));
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
        self::assertSame('file:///tmp/A.php', Json::at($responses, 0, 'params', 'uri'));
    }

    public function testWatchedRectorConfigChangeIsCaseInsensitiveInTheBasename(): void
    {
        // oss:auditor self-review finding: the basename comparison used
        // `===`, which is case-sensitive -- on a case-insensitive
        // filesystem (the default on Windows and on macOS, the two
        // platforms this whole issue is about) a client could report
        // `Rector.php`/`Composer.Lock`'s real on-disk casing and the guard
        // would silently miss it, returning [] identically to the
        // genuinely-unrelated-file case (testWatchedFileChangeTo...
        // below) -- an absence the caller cannot tell from "nothing to do".
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
            'params' => ['changes' => [['uri' => 'file:///tmp/Rector.PHP', 'type' => 2]]],
        ]);

        self::assertCount(1, $responses);
        self::assertSame('file:///tmp/A.php', Json::at($responses, 0, 'params', 'uri'));
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
        $fixes = [self::fix(3, 12, "fixed\n", \Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector::class)];
        $server = new LspServer('1.0.0', self::fakeSource($fixes));

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        self::assertCount(1, $responses);
        $notification = $responses[0];
        self::assertSame('textDocument/publishDiagnostics', $notification['method']);
        self::assertSame('file:///tmp/Sample.php', Json::at($notification, 'params', 'uri'));
        self::assertCount(1, Json::array($notification, 'params', 'diagnostics'));
        $diagnostic = Json::array($notification, 'params', 'diagnostics', 0);
        self::assertSame(Json::at($fixes, 0, 'range'), $diagnostic['range']);
        self::assertSame('rector', $diagnostic['source']);
        self::assertSame(\Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector::class, $diagnostic['message']);
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
        $diagnostics = Json::array($responses, 0, 'params', 'diagnostics');
        self::assertCount(1, $diagnostics);
        self::assertSame(1, Json::at($diagnostics, 0, 'severity'));
        self::assertSame('Syntax error, unexpected token', Json::at($diagnostics, 0, 'message'));
        self::assertSame(['line' => 6, 'character' => 0], Json::at($diagnostics, 0, 'range', 'start'));
        self::assertArrayNotHasKey('data', Json::array($diagnostics, 0));

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

        self::assertSame('Rector fix', Json::at($responses, 0, 'params', 'diagnostics', 0, 'message'));

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

        self::assertSame('Apply Rector: Rector fix', Json::at($codeActionResponses, 0, 'result', 0, 'title'));
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
        self::assertSame([], Json::at($responses, 0, 'params', 'diagnostics'));
    }

    public function testWatchedConfigChangeReDiagnosesTheMostRecentlyActiveDocumentFirst(): void
    {
        // #115: re-diagnosing every open document serially after a
        // rector.php change measured ~45s end-to-end with 17 documents open
        // on a real project, and whichever document the developer is
        // actively editing queues behind every document that merely
        // happened to open earlier. Re-diagnosing in most-recently-active
        // order gets that document a fresh publishDiagnostics first, even
        // though the total wall-clock across all documents is unchanged.
        $source = new class implements DiagnosticsSource {
            /** @var list<string> */
            public array $order = [];

            public function diagnose(string $absolutePath): array
            {
                $this->order[] = $absolutePath;

                return ['fixes' => []];
            }
        };

        $server = new LspServer('1.0.0', $source);
        foreach (['A', 'B', 'C'] as $name) {
            $server->handle([
                'jsonrpc' => '2.0',
                'method' => 'textDocument/didOpen',
                'params' => ['textDocument' => ['uri' => "file:///tmp/{$name}.php", 'version' => 1]],
            ]);
        }
        $source->order = []; // only the order triggered by the watched-file event matters below

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => ['changes' => [['uri' => 'file:///tmp/rector.php', 'type' => 2]]],
        ]);

        self::assertSame(['/tmp/C.php', '/tmp/B.php', '/tmp/A.php'], $source->order);
        self::assertCount(3, $responses);
        $uris = array_map(static fn(array $r): string => Json::string($r, 'params', 'uri'), $responses);
        self::assertSame(['file:///tmp/C.php', 'file:///tmp/B.php', 'file:///tmp/A.php'], $uris);
    }

    public function testASecondConfigChangeWithNoInterveningActivityKeepsTheSameOrder(): void
    {
        // Self-review finding (independent oss:auditor review pass):
        // diagnoseDocument() is also what watchedFilesChanged()'s own
        // re-diagnose loop calls for every open document. If that call
        // touched activity too, each re-diagnosis would re-append its own
        // URI and leave $activityOrder REVERSED afterward -- a SECOND
        // config change with no real didOpen/didSave in between would then
        // process documents in exactly the wrong order, silently undoing
        // the ordering fix for that second event. Two config-change events
        // back to back, with nothing real happening between them, must
        // re-diagnose in the SAME most-recently-active-first order both
        // times.
        $source = new class implements DiagnosticsSource {
            /** @var list<string> */
            public array $order = [];

            public function diagnose(string $absolutePath): array
            {
                $this->order[] = $absolutePath;

                return ['fixes' => []];
            }

            /**
             * The order recorded since the last call, then cleared -- a method
             * rather than a property write, so the server's own calls into
             * diagnose() in between are not hidden from static analysis.
             *
             * @return list<string>
             */
            public function takeOrder(): array
            {
                $order = $this->order;
                $this->order = [];

                return $order;
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
        $source->takeOrder();

        $configChange = [
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => ['changes' => [['uri' => 'file:///tmp/rector.php', 'type' => 2]]],
        ];
        $server->handle($configChange);
        $firstOrder = $source->takeOrder();

        $server->handle($configChange);
        $secondOrder = $source->takeOrder();

        self::assertSame(['/tmp/B.php', '/tmp/A.php'], $firstOrder);
        self::assertSame($firstOrder, $secondOrder);
    }

    public function testWatchedConfigChangeTreatsADidSaveAsRefreshingActivityOrder(): void
    {
        // Positive control: activity is not just "when was it opened" --
        // saving an older document again must move it back to the front,
        // since a save is exactly the signal that the developer is
        // currently working in it.
        $source = new class implements DiagnosticsSource {
            /** @var list<string> */
            public array $order = [];

            public function diagnose(string $absolutePath): array
            {
                $this->order[] = $absolutePath;

                return ['fixes' => []];
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
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didSave',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/A.php', 'version' => 2]],
        ]);
        $source->order = [];

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => ['changes' => [['uri' => 'file:///tmp/rector.php', 'type' => 2]]],
        ]);

        self::assertSame(['/tmp/A.php', '/tmp/B.php'], $source->order);
    }

    public function testWatchedConfigChangeDoesNotReDiagnoseAClosedDocument(): void
    {
        // Negative control for the two tests above: a document that was
        // closed must drop out of the activity order entirely, not merely
        // move to the back of it.
        $source = new class implements DiagnosticsSource {
            /** @var list<string> */
            public array $order = [];

            public function diagnose(string $absolutePath): array
            {
                $this->order[] = $absolutePath;

                return ['fixes' => []];
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
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didClose',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/B.php']],
        ]);
        $source->order = [];

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => ['changes' => [['uri' => 'file:///tmp/rector.php', 'type' => 2]]],
        ]);

        self::assertSame(['/tmp/A.php'], $source->order);
        self::assertCount(1, $responses);
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
        self::assertSame([], Json::at($responses, 0, 'params', 'diagnostics'));
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

        $actions = Json::array($responses, 0, 'result');
        self::assertCount(2, $actions);
        self::assertSame('Apply Rector: SimplifyIfReturnBoolRector', Json::at($actions, 0, 'title'));
        $edit = Json::array($actions, 0, 'edit', 'changes', 'file:///tmp/Sample.php', 0);
        self::assertSame(Json::at($fixes, 0, 'range'), $edit['range']);
        self::assertSame('fixed' . "\n", $edit['newText']);
        self::assertSame('Apply all Rector fixes', Json::at($actions, 1, 'title'));
    }

    /**
     * #216: codeAction's cached $fixesByUri (from diagnose(), potentially
     * session-served) only decides WHETHER to offer an action, never what
     * the edit itself says -- a source that implements EditDiagnosticsSource
     * has its edit text recomputed via diagnoseForEdit() instead. The two
     * fix sets below deliberately differ so a leftover use of the stale
     * cached text is caught here rather than passing by coincidence.
     */
    public function testCodeActionRecomputesTheEditWithTheSessionForcedOffWhenTheSourceSupportsIt(): void
    {
        $stale = [self::fix(3, 12, "stale\n", 'SimplifyIfReturnBoolRector')];
        $fresh = [self::fix(3, 12, "fresh\n", 'SimplifyIfReturnBoolRector')];
        $source = new class ($stale, $fresh) implements DiagnosticsSource, EditDiagnosticsSource {
            /**
             * @param list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}> $stale
             * @param list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}> $fresh
             */
            public function __construct(private readonly array $stale, private readonly array $fresh) {}

            public function diagnose(string $absolutePath): array
            {
                return ['fixes' => $this->stale];
            }

            /**
             * @return array{
             *   fixes: list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>,
             *   errors?: list<array{message: string, line: int}>,
             * }
             */
            public function diagnoseForEdit(string $absolutePath): array
            {
                return ['fixes' => $this->fresh];
            }

            /**
             * @return array{
             *   fixes: list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>,
             *   errors?: list<array{message: string, line: int}>,
             * }
             */
            public function diagnoseBufferForEdit(string $absolutePath, string $content): array
            {
                return ['fixes' => []];
            }
        };
        $server = new LspServer('1.0.0', $source);
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

        $actions = Json::array($responses, 0, 'result');
        self::assertCount(2, $actions);
        $edit = Json::array($actions, 0, 'edit', 'changes', 'file:///tmp/Sample.php', 0);
        self::assertSame('fresh' . "\n", $edit['newText'], 'the stale cached fix text must never reach the edit');
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

        $actions = Json::array($responses, 0, 'result');
        self::assertCount(2, $actions);
        self::assertSame('Apply Rector: SimplifyIfReturnBoolRector', Json::at($actions, 0, 'title'));
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

        $actions = Json::array($responses, 0, 'result');
        self::assertCount(1, $actions);
        self::assertSame('Apply all Rector fixes', Json::at($actions, 0, 'title'));
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

        $actions = Json::array($responses, 0, 'result');
        self::assertCount(2, $actions);
        self::assertSame('Apply Rector: NewlineAfterStatementRector', Json::at($actions, 0, 'title'));
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

        $actions = Json::array($responses, 0, 'result');
        self::assertCount(1, $actions);
        self::assertSame('Apply all Rector fixes', Json::at($actions, 0, 'title'));
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

            /** @param object{server: ?LspServer} $holder */
            public function __construct(private readonly object $holder) {}

            public function diagnose(string $absolutePath): array
            {
                if (!$this->raced) {
                    $this->raced = true;
                    $this->holder->server?->handle([
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

    /** #111: window/workDoneProgress around the first diagnose (cold boot). */
    public function testWorkDoneProgressIsSentAroundTheFirstDiagnoseWhenClientSupportsIt(): void
    {
        $server = new LspServer('1.0.0', self::fakeSource([]));

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => ['window' => ['workDoneProgress' => true]]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $methods = array_map(static fn(array $frame): ?string => Json::optionalString($frame, 'method'), $responses);

        self::assertSame(
            ['window/workDoneProgress/create', '$/progress', '$/progress', 'textDocument/publishDiagnostics'],
            $methods,
        );

        $token = Json::at($responses, 0, 'params', 'token');
        self::assertSame($token, Json::at($responses, 1, 'params', 'token'));
        self::assertSame($token, Json::at($responses, 2, 'params', 'token'));

        $begin = Json::array($responses, 1, 'params', 'value');
        self::assertSame('begin', $begin['kind']);
        self::assertSame('Rector: warming up', $begin['title']);
        self::assertSame('Rector: analysing Sample.php', $begin['message']);

        $end = Json::array($responses, 2, 'params', 'value');
        self::assertSame('end', $end['kind']);
    }

    /**
     * Self-review finding (oss:auditor pass): the progress message built
     * `basename($path)` directly, but a UNC uri (#99) makes `uriToPath()`
     * return a backslash-joined `\\host\share\...` path, and PHP's
     * `basename()` only treats `\` as a separator on native Windows --
     * on macOS/Linux this returned the WHOLE path, not the filename.
     * `isWatchedConfigFile()` already normalizes with
     * `str_replace('\\', '/', $path)` before its own `basename()` call for
     * exactly this reason; the progress message now does the same.
     */
    public function testProgressMessageShowsOnlyTheFilenameForAUncPath(): void
    {
        $server = new LspServer('1.0.0', self::fakeSource([]));

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => ['window' => ['workDoneProgress' => true]]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file://myserver/share/Sample.php', 'version' => 1]],
        ]);

        self::assertSame('Rector: analysing Sample.php', Json::at($responses, 1, 'params', 'value', 'message'));
    }

    /**
     * Self-review correction (Explore pass): this is a companion/negative
     * case, not a "positive control" -- it asserts the ABSENCE of an
     * effect under conditions where the effect was never expected to fire,
     * so it would pass unchanged even with the whole #111 feature deleted.
     * It still earns its place alongside the test above: together they
     * pin BOTH branches of the capability check, so a future edit that
     * makes progress fire unconditionally (dropping the capability gate
     * entirely) is caught here rather than only by the positive case.
     */
    public function testNoProgressIsSentWhenClientLacksTheCapability(): void
    {
        $server = new LspServer('1.0.0', self::fakeSource([]));

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => []],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        self::assertCount(1, $responses);
        self::assertSame('textDocument/publishDiagnostics', $responses[0]['method']);
    }

    /** #111: only the FIRST diagnose is a cold boot -- later ones must stay silent. */
    public function testProgressIsOnlySentAroundTheFirstDiagnoseNotLaterOnes(): void
    {
        $server = new LspServer('1.0.0', self::fakeSource([]));

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => ['window' => ['workDoneProgress' => true]]],
        ]);

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $second = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didSave',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 2]],
        ]);

        self::assertCount(1, $second);
        self::assertSame('textDocument/publishDiagnostics', $second[0]['method']);
    }

    /** #111: $/cancelRequest for a codeAction's own id must refuse that request. */
    public function testCodeActionIsRefusedAfterCancelRequestForTheSameId(): void
    {
        $server = new LspServer('1.0.0', self::fakeSource([
            self::fix(0, 1, "fixed\n", 'SomeRector'),
        ]));

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => '$/cancelRequest',
            'params' => ['id' => 42],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 42,
            'method' => 'textDocument/codeAction',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php']],
        ]);

        self::assertCount(1, $responses);
        self::assertArrayHasKey('error', $responses[0]);
        self::assertSame(-32800, Json::at($responses, 0, 'error', 'code'));
    }

    /**
     * Positive control: a cancelRequest for a DIFFERENT id must not refuse
     * an unrelated codeAction -- otherwise a single cancellation would
     * silently disable every future codeAction, indistinguishable from
     * "cancellation is correctly scoped to its own id".
     */
    public function testCancelRequestDoesNotAffectAnUnrelatedCodeActionId(): void
    {
        $server = new LspServer('1.0.0', self::fakeSource([
            self::fix(0, 1, "fixed\n", 'SomeRector'),
        ]));

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => '$/cancelRequest',
            'params' => ['id' => 42],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 43,
            'method' => 'textDocument/codeAction',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php']],
        ]);

        self::assertCount(1, $responses);
        self::assertArrayNotHasKey('error', $responses[0]);
        self::assertArrayHasKey('result', $responses[0]);
        self::assertNotEmpty($responses[0]['result']);
    }

    /**
     * PR #128 E2E review, blocking finding 1: progress must reach the
     * transport LIVE -- `create` and `begin` written BEFORE diagnose()
     * runs, not batched together with `end`/publishDiagnostics after it
     * finishes. A log shared between the frame writer and the fake
     * DiagnosticsSource is the only way to observe WHEN each thing
     * happened, not just what order handle()'s return value lists them in.
     */
    public function testProgressCreateAndBeginAreWrittenToTheTransportBeforeDiagnoseRuns(): void
    {
        /** @var list<string> */
        $entries = [];
        $addEntry = function (string $entry) use (&$entries): void {
            $entries[] = $entry;
        };

        $diagnostics = new class ($addEntry) implements DiagnosticsSource {
            /** @param \Closure(string): void $addEntry */
            public function __construct(private readonly \Closure $addEntry) {}

            public function diagnose(string $absolutePath): array
            {
                ($this->addEntry)('diagnose-called');

                return ['fixes' => []];
            }
        };

        $frameWriter = function (array $frame) use ($addEntry): void {
            $addEntry('wrote:' . Scalar::toString($frame['method'] ?? '?', 'frame method'));
        };

        $server = new LspServer('1.0.0', $diagnostics, frameWriter: $frameWriter);

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => ['window' => ['workDoneProgress' => true]]],
        ]);

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        self::assertSame([
            'wrote:window/workDoneProgress/create',
            'wrote:$/progress',
            'diagnose-called',
            'wrote:$/progress',
            'wrote:textDocument/publishDiagnostics',
        ], $entries);
    }

    /**
     * PR #128 E2E review, blocking finding 2: when the client's reply to
     * `create` is already available (non-blocking peek) and is an error,
     * neither `$/progress` begin nor end may be sent for that token.
     */
    public function testProgressBeginAndEndAreSuppressedWhenTheClientRefusesCreate(): void
    {
        $server = new LspServer(
            '1.0.0',
            self::fakeSource([]),
            tryReadAhead: static fn(): array => [
                'jsonrpc' => '2.0',
                'id' => 'rector-warm-lsp/progress-create',
                'error' => ['code' => -32800, 'message' => 'client declined this progress token'],
            ],
        );

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => ['window' => ['workDoneProgress' => true]]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $methods = array_map(static fn(array $frame): ?string => Json::optionalString($frame, 'method'), $responses);

        self::assertSame(
            ['window/workDoneProgress/create', 'textDocument/publishDiagnostics'],
            $methods,
        );
    }

    /**
     * Second E2E review round, PR #128: when $tryReadAhead is not wired at
     * all (every caller that cannot offer this capability), progress must
     * still fire normally -- this is the ONE case that still assumes
     * success without confirmation, because there is no mechanism to ask.
     * Every OTHER "we could not confirm" case below now skips instead.
     */
    public function testProgressFiresNormallyWhenNoReadAheadCapabilityIsWiredAtAll(): void
    {
        $server = new LspServer('1.0.0', self::fakeSource([]));

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => ['window' => ['workDoneProgress' => true]]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $methods = array_map(static fn(array $frame): ?string => Json::optionalString($frame, 'method'), $responses);

        self::assertSame(
            ['window/workDoneProgress/create', '$/progress', '$/progress', 'textDocument/publishDiagnostics'],
            $methods,
        );
    }

    /**
     * Second E2E review round, PR #128: the window elapsing with no reply
     * AT ALL must now skip progress, not assume success -- a client that
     * never acks `create` must never see a live $/progress for that token.
     * This is a real semantic change from the first PR #128 fix, which
     * treated "nothing seen yet" (a zero-timeout peek) as "proceed";
     * $tryReadAhead is now given a genuine window (isProgressCreateRefused's
     * own CREATE_REPLY_TIMEOUT_SECONDS), so "still nothing after waiting
     * for it" is a real timeout, not an instant, meaningless peek.
     */
    public function testProgressIsSkippedWhenTheCreateReplyNeverArrivesWithinTheWindow(): void
    {
        $server = new LspServer(
            '1.0.0',
            self::fakeSource([]),
            tryReadAhead: static fn(float $timeoutSeconds): ?array => null,
        );

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => ['window' => ['workDoneProgress' => true]]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $methods = array_map(static fn(array $frame): ?string => Json::optionalString($frame, 'method'), $responses);

        self::assertSame(
            ['window/workDoneProgress/create', 'textDocument/publishDiagnostics'],
            $methods,
        );
    }

    /**
     * Second E2E review round, PR #128: the read-ahead peek can legitimately
     * find a message that is NOT the create reply (e.g. the client sent
     * something else first) -- it must be handed back via
     * $pushBackMessage rather than silently dropped, but this round's
     * create reply is now unresolved (not "assumed fine" the way the
     * first PR #128 fix treated it), so progress is skipped too.
     *
     * Third self-review pass (oss:auditor, same round): the fake closure
     * returns the unrelated message once, then null -- modelling "nothing
     * else, ever, including the real reply, arrives within the window".
     * Confirms the RETRY loop still terminates and skips correctly when
     * the real reply genuinely never comes, not just when it is consumed
     * by a single call. See the recovery test right below for the case
     * where the real reply DOES arrive after the unrelated message.
     */
    public function testAnUnrelatedReadAheadMessageIsPushedBackAndProgressIsSkipped(): void
    {
        $pushedBack = [];
        $unrelated = ['jsonrpc' => '2.0', 'method' => 'textDocument/didSave', 'params' => ['textDocument' => ['uri' => 'file:///tmp/Other.php']]];
        $calls = new class {
            public int $count = 0;
        };

        $server = new LspServer(
            '1.0.0',
            self::fakeSource([]),
            tryReadAhead: static function (float $timeoutSeconds) use ($unrelated, $calls): ?array {
                $calls->count++;

                return $calls->count === 1 ? $unrelated : null;
            },
            pushBackMessage: function (array $message) use (&$pushedBack): void {
                $pushedBack[] = $message;
            },
        );

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => ['window' => ['workDoneProgress' => true]]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $methods = array_map(static fn(array $frame): ?string => Json::optionalString($frame, 'method'), $responses);

        self::assertSame(
            ['window/workDoneProgress/create', 'textDocument/publishDiagnostics'],
            $methods,
        );
        self::assertSame([$unrelated], $pushedBack);
    }

    /**
     * Third self-review pass (oss:auditor, same PR #128 round): a single
     * $tryReadAhead call used to consume the WHOLE window on the first
     * message it saw, even an unrelated one arriving moments before the
     * real reply -- skipping progress on an otherwise-healthy client that
     * happened to send something else first. This proves the retry loop
     * actually recovers: an unrelated message is set aside, and the real
     * (successful) reply arriving on the VERY NEXT call still lets
     * progress fire, with the unrelated message still pushed back rather
     * than lost.
     */
    public function testProgressFiresWhenTheRealReplyArrivesAfterAnUnrelatedMessage(): void
    {
        $pushedBack = [];
        $unrelated = ['jsonrpc' => '2.0', 'method' => 'textDocument/didSave', 'params' => ['textDocument' => ['uri' => 'file:///tmp/Other.php']]];
        $realReply = ['jsonrpc' => '2.0', 'id' => 'rector-warm-lsp/progress-create', 'result' => null];
        $calls = new class {
            public int $count = 0;
        };

        $server = new LspServer(
            '1.0.0',
            self::fakeSource([]),
            tryReadAhead: static function (float $timeoutSeconds) use ($unrelated, $realReply, $calls): array {
                $calls->count++;

                return $calls->count === 1 ? $unrelated : $realReply;
            },
            pushBackMessage: function (array $message) use (&$pushedBack): void {
                $pushedBack[] = $message;
            },
        );

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => ['window' => ['workDoneProgress' => true]]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $methods = array_map(static fn(array $frame): ?string => Json::optionalString($frame, 'method'), $responses);

        self::assertSame(
            ['window/workDoneProgress/create', '$/progress', '$/progress', 'textDocument/publishDiagnostics'],
            $methods,
        );
        self::assertSame([$unrelated], $pushedBack);
    }

    /**
     * PR #128 second E2E review (the blocking finding this round): a
     * delayed EXPLICIT ERROR reply must skip progress. This fake closure
     * only "sees" the reply if given a timeout at least as long as the
     * delay -- called with one shorter (the old, unfixed call shape,
     * which passed no timeout at all) it sees nothing.
     *
     * oss:auditor self-review correction (same round): this test's red
     * (against commit 75a63eb)-then-green transition is real, but it is
     * driven by the OLD "null means proceed" semantics this round also
     * changed, not by timeout-forwarding specifically -- both an
     * unforwarded timeout (null, old semantics: proceed) and a forwarded
     * one (the delayed error, new semantics: skip) happen to produce
     * DIFFERENT outcomes here only because of that semantics change, so
     * this test alone cannot tell "timeout forwarded correctly" apart from
     * "timeout not forwarded, but null now also skips". The sibling
     * positive control right after this one is what actually pins
     * timeout-forwarding: an unforwarded timeout there would see null (old
     * behaviour) and WRONGLY skip a reply that should fire, which is the
     * one case a forwarding regression cannot hide behind the semantics
     * change.
     */
    public function testProgressIsSkippedWhenTheDelayedCreateReplyIsAnError(): void
    {
        $replyDelaySeconds = 0.05;

        $server = new LspServer(
            '1.0.0',
            self::fakeSource([]),
            tryReadAhead: static function (float $timeoutSeconds = 0.0) use ($replyDelaySeconds): ?array {
                if ($timeoutSeconds < $replyDelaySeconds) {
                    // The old call shape (no timeout argument at all, or
                    // one shorter than the delay): the reply is not there
                    // YET, exactly what a zero-timeout peek would see.
                    return null;
                }

                usleep((int) ($replyDelaySeconds * 1_000_000));

                return [
                    'jsonrpc' => '2.0',
                    'id' => 'rector-warm-lsp/progress-create',
                    'error' => ['code' => -32800, 'message' => 'client declined this progress token'],
                ];
            },
        );

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => ['window' => ['workDoneProgress' => true]]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $methods = array_map(static fn(array $frame): ?string => Json::optionalString($frame, 'method'), $responses);

        self::assertSame(
            ['window/workDoneProgress/create', 'textDocument/publishDiagnostics'],
            $methods,
        );
    }

    /**
     * Positive control for the test above: the SAME delayed-reply closure,
     * but the delayed reply is a SUCCESS this time -- begin/end must still
     * fire once the wait genuinely confirms it, proving this is a real
     * wait-and-check, not a change that suppresses progress unconditionally.
     */
    public function testProgressFiresWhenTheDelayedCreateReplyIsASuccess(): void
    {
        $replyDelaySeconds = 0.05;

        $server = new LspServer(
            '1.0.0',
            self::fakeSource([]),
            tryReadAhead: static function (float $timeoutSeconds = 0.0) use ($replyDelaySeconds): ?array {
                if ($timeoutSeconds < $replyDelaySeconds) {
                    return null;
                }

                usleep((int) ($replyDelaySeconds * 1_000_000));

                return [
                    'jsonrpc' => '2.0',
                    'id' => 'rector-warm-lsp/progress-create',
                    'result' => null,
                ];
            },
        );

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => ['window' => ['workDoneProgress' => true]]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $methods = array_map(static fn(array $frame): ?string => Json::optionalString($frame, 'method'), $responses);

        self::assertSame(
            ['window/workDoneProgress/create', '$/progress', '$/progress', 'textDocument/publishDiagnostics'],
            $methods,
        );
    }

    private const APPLY_EDIT_ONLY = ['workspace' => ['applyEdit' => true]];

    /**
     * Second-pass self-review note (Explore, on the fix for the two
     * findings above): pathToUri()'s Windows drive-letter handling was
     * verified by hand during that fix but had no test of its own -- this
     * is pure string manipulation (str_replace/preg_match/explode), not an
     * OS API call, so it is deterministic on any host and worth pinning
     * directly rather than leaving it "reasoned, not observed" only.
     */
    public function testPathToUriKeepsAWindowsDriveLetterColonUnencoded(): void
    {
        $method = new \ReflectionMethod(LspServer::class, 'pathToUri');

        self::assertSame(
            'file:///C:/Users/me/A.php',
            $method->invoke(null, 'C:\\Users\\me\\A.php'),
        );
    }

    public function testPathToUriEncodesAnOrdinaryPosixPathSegment(): void
    {
        $method = new \ReflectionMethod(LspServer::class, 'pathToUri');

        self::assertSame(
            'file:///home/user/foo%20bar.php',
            $method->invoke(null, '/home/user/foo bar.php'),
        );
    }

    public function testExecuteCommandRefusesAnUnknownCommand(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceSource([]));
        self::initialize($server, self::APPLY_EDIT_ONLY);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'some.other.command'],
        ]);

        self::assertCount(1, $responses);
        self::assertSame(5, $responses[0]['id']);
        self::assertArrayHasKey('error', $responses[0]);
        self::assertArrayNotHasKey('result', $responses[0]);
    }

    /**
     * #102 "refuse with a visible error, never a silent no-op": a client
     * that never declared workspace.applyEdit gets a JSON-RPC error, not a
     * quietly-empty result -- there would be nowhere to send the fix.
     */
    public function testExecuteCommandRefusesWithoutClientApplyEditSupport(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceSource([
            '/proj/Sample.php' => [self::fix(0, 1, "<?php\n", 'SomeRector')],
        ]));
        self::initialize($server, []); // no workspace.applyEdit declared

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        self::assertCount(1, $responses);
        self::assertSame(6, $responses[0]['id']);
        self::assertArrayHasKey('error', $responses[0]);
    }

    /**
     * Positive control for the refusal above: the SAME source and command,
     * but the client DOES declare workspace.applyEdit -- the command must
     * actually go through and reach the client as an outbound
     * `workspace/applyEdit` request, proving the refusal above is really
     * about the missing capability and not e.g. a broken command name.
     */
    public function testExecuteCommandAppliesFixesAcrossEveryChangedFileWhenClientSupportsApplyEdit(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceSource([
            '/proj/A.php' => [self::fix(0, 1, "<?php\nclass A {}\n", 'RectorA')],
            '/proj/B.php' => [self::fix(2, 3, "<?php\nclass B {}\n", 'RectorB')],
        ]));
        self::initialize($server, [
            'workspace' => ['applyEdit' => true, 'workspaceEdit' => ['documentChanges' => true]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        $applyEdit = null;
        $result = null;
        foreach ($responses as $frame) {
            if (($frame['method'] ?? null) === 'workspace/applyEdit') {
                $applyEdit = $frame;
            }
            if (array_key_exists('id', $frame) && $frame['id'] === 7) {
                $result = $frame;
            }
        }

        self::assertNotNull($applyEdit, 'expected an outbound workspace/applyEdit request');
        self::assertNotNull($result, 'expected a response to the executeCommand request itself');
        self::assertArrayNotHasKey('error', $result);

        $uris = array_column(
            array_column(Json::array($applyEdit, 'params', 'edit', 'documentChanges'), 'textDocument'),
            'uri',
        );
        sort($uris);
        self::assertSame(['file:///proj/A.php', 'file:///proj/B.php'], $uris);
    }

    /**
     * Same fix content, but the client never declared
     * workspace.workspaceEdit.documentChanges -- the edit must fall back to
     * the plain `changes` map, exactly like codeAction's own
     * canUseDocumentChanges branch.
     */
    public function testExecuteCommandUsesPlainChangesMapWithoutDocumentChangesSupport(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceSource([
            '/proj/A.php' => [self::fix(0, 1, "<?php\nclass A {}\n", 'RectorA')],
        ]));
        self::initialize($server, self::APPLY_EDIT_ONLY);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        $applyEdit = null;
        foreach ($responses as $frame) {
            if (($frame['method'] ?? null) === 'workspace/applyEdit') {
                $applyEdit = $frame;
            }
        }

        self::assertNotNull($applyEdit);
        self::assertArrayHasKey('file:///proj/A.php', Json::array($applyEdit, 'params', 'edit', 'changes'));
        self::assertArrayNotHasKey('documentChanges', Json::array($applyEdit, 'params', 'edit'));
    }

    /**
     * Negative control paired with the test above: nothing to fix means no
     * workspace/applyEdit is sent at all -- but the command still answers
     * (not a silent hang), since "nothing changed" is a legitimate outcome,
     * unlike the missing-capability refusal.
     */
    public function testExecuteCommandSendsNoApplyEditWhenNothingToFix(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceSource([]));
        self::initialize($server, self::APPLY_EDIT_ONLY);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        $methods = array_map(static fn(array $frame): ?string => Json::optionalString($frame, 'method'), $responses);
        self::assertNotContains('workspace/applyEdit', $methods);
        $lastKey = array_key_last($responses);
        if ($lastKey === null) {
            self::fail('expected at least one response frame');
        }
        $lastResponse = $responses[$lastKey];
        self::assertSame(9, $lastResponse['id']);
        self::assertArrayNotHasKey('error', $lastResponse);
    }

    /**
     * Self-review finding (both the Explore and oss:auditor review passes,
     * independently): an empty `files` map is not always "nothing to fix"
     * -- diagnoseWorkspace() also returns it for a genuine failure
     * (RectorDiagnosticsSource::interpretWorkspace()'s own two failure
     * branches), distinguished only by a non-empty `errors`. Positive
     * control, paired with the negative-control test directly above (same
     * empty `files`, but `errors: []` there): a non-empty `errors`
     * alongside empty `files` must surface as a JSON-RPC error, never the
     * same silent "nothing changed" success.
     */
    public function testExecuteCommandFailsRatherThanSilentlySucceedingWhenDiagnoseWorkspaceReportsAnError(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceSource([], [
            ['message' => 'rector_process: path is outside the configured working directory.', 'line' => 0],
        ]));
        self::initialize($server, self::APPLY_EDIT_ONLY);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        self::assertCount(1, $responses);
        self::assertSame(12, $responses[0]['id']);
        self::assertArrayHasKey('error', $responses[0]);
        self::assertStringContainsString(
            'outside the configured working directory',
            Json::string($responses, 0, 'error', 'message'),
        );
    }

    /**
     * #141: a file that fails (e.g. a syntax error) alongside others that
     * succeed is skipped, not refused -- the successful fixes are still
     * applied, exactly like a cold `rector process` continues past one
     * broken file (see executeCommand()'s own docblock). But that used to
     * answer plain success with no indication anything was skipped. Now a
     * `window/showMessage` (Warning) names the failing file(s) alongside
     * the still-applied edit.
     */
    public function testExecuteCommandWarnsAboutFilesThatFailedWhileStillApplyingTheRest(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceSource(
            ['/proj/A.php' => [self::fix(0, 1, "<?php\nclass A {}\n", 'RectorA')]],
            [['message' => 'Syntax error, unexpected token', 'line' => 7, 'file' => '/proj/Bad.php']],
        ));
        self::initialize($server, [
            'workspace' => ['applyEdit' => true, 'workspaceEdit' => ['documentChanges' => true]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 30,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        $applyEdit = null;
        $showMessage = null;
        foreach ($responses as $frame) {
            if (($frame['method'] ?? null) === 'workspace/applyEdit') {
                $applyEdit = $frame;
            }
            if (($frame['method'] ?? null) === 'window/showMessage') {
                $showMessage = $frame;
            }
        }

        self::assertNotNull($applyEdit, 'the other file must still be fixed, not refused wholesale');
        self::assertSame(
            ['file:///proj/A.php'],
            array_column(array_column(Json::array($applyEdit, 'params', 'edit', 'documentChanges'), 'textDocument'), 'uri'),
        );

        self::assertNotNull($showMessage, 'the failing file must be named in a window/showMessage warning');
        self::assertSame(2, Json::at($showMessage, 'params', 'type'), 'window/showMessage type 2 is Warning');
        self::assertStringContainsString('/proj/Bad.php', Json::string($showMessage, 'params', 'message'));
        self::assertStringContainsString('Syntax error, unexpected token', Json::string($showMessage, 'params', 'message'));
    }

    /**
     * Negative control for the test above: no errors at all means no
     * `window/showMessage` is sent -- the warning is tied to an actual
     * failure, not sent unconditionally alongside every fix.
     */
    public function testExecuteCommandSendsNoShowMessageWhenNothingFailed(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceSource([
            '/proj/A.php' => [self::fix(0, 1, "<?php\nclass A {}\n", 'RectorA')],
        ]));
        self::initialize($server, self::APPLY_EDIT_ONLY);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 31,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        $methods = array_map(static fn(array $frame): ?string => Json::optionalString($frame, 'method'), $responses);
        self::assertNotContains('window/showMessage', $methods);
    }

    /**
     * #102 "report progress with $/progress": its own token
     * (`rector-warm-lsp/fix-workspace`), distinct from #111's cold-boot
     * one, so a client watching for the cold-boot token specifically never
     * sees this progress mixed into it.
     */
    public function testExecuteCommandReportsProgressOnItsOwnToken(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceSource([
            '/proj/A.php' => [self::fix(0, 1, "<?php\n", 'SomeRector')],
        ]));
        self::initialize($server, [
            'workspace' => ['applyEdit' => true],
            'window' => ['workDoneProgress' => true],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        $tokens = [];
        foreach ($responses as $frame) {
            if (($frame['method'] ?? null) === '$/progress' || ($frame['method'] ?? null) === 'window/workDoneProgress/create') {
                $tokens[] = Json::at($frame, 'params', 'token');
            }
        }

        self::assertNotEmpty($tokens);
        foreach ($tokens as $token) {
            self::assertSame('rector-warm-lsp/fix-workspace', $token);
        }
    }

    /**
     * Negative control for the progress test above: a client that never
     * declared window.workDoneProgress gets no progress frames at all for
     * fixWorkspace, the same as #111's existing cold-boot behaviour.
     */
    public function testExecuteCommandSendsNoProgressWithoutWorkDoneProgressCapability(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceSource([
            '/proj/A.php' => [self::fix(0, 1, "<?php\n", 'SomeRector')],
        ]));
        self::initialize($server, self::APPLY_EDIT_ONLY);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        $methods = array_map(static fn(array $frame): ?string => Json::optionalString($frame, 'method'), $responses);
        self::assertNotContains('$/progress', $methods);
        self::assertNotContains('window/workDoneProgress/create', $methods);
    }

    /**
     * #140: fixWorkspace's edit is computed from disk. A file the server
     * knows has an unsaved (dirty) buffer -- textDocument/didChange sent,
     * never saved -- must never be overwritten with that disk-derived
     * content: doing so would silently discard the unsaved edits, and
     * "version": null on the OptionalVersionedTextDocumentIdentifier gives
     * the client no way to detect the mismatch itself. Paired with the
     * positive control below (a file that is open but NOT dirty still gets
     * fixed) per CLAUDE.md's own rule that a must-not-fire assertion needs
     * a must-fire sibling.
     */
    public function testExecuteCommandSkipsAFileWithAnUnsavedDirtyBuffer(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceAndBufferSource([
            '/proj/A.php' => [self::fix(0, 1, "<?php\nclass A {}\n", 'RectorA')],
        ]));
        self::initialize($server, [
            'workspace' => ['applyEdit' => true, 'workspaceEdit' => ['documentChanges' => true]],
        ]);

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///proj/A.php', 'version' => 1]],
        ]);
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => ['uri' => 'file:///proj/A.php', 'version' => 2],
                'contentChanges' => [['text' => "<?php\nclass A { public function unsaved(): void {} }\n"]],
            ],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 20,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        $applyEdit = null;
        $result = null;
        foreach ($responses as $frame) {
            if (($frame['method'] ?? null) === 'workspace/applyEdit') {
                $applyEdit = $frame;
            }
            if (array_key_exists('id', $frame) && $frame['id'] === 20) {
                $result = $frame;
            }
        }

        self::assertNull(
            $applyEdit,
            'the only fix in this workspace is for a dirty buffer -- no workspace/applyEdit must be sent at all',
        );
        self::assertNotNull($result, 'expected a response to the executeCommand request itself');
        self::assertArrayNotHasKey('error', $result);
        self::assertSame(['skippedDirtyBuffers' => ['file:///proj/A.php']], $result['result']);
    }

    /**
     * Positive control for the test above: a file that IS open, but has no
     * unsaved buffer content (no didChange since didOpen), is not at risk
     * of the buffer being overwritten and must still get its fix -- proving
     * the skip above triggers on dirtiness, not on merely being open.
     */
    public function testExecuteCommandStillFixesAFileThatIsOpenButNotDirty(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceAndBufferSource([
            '/proj/A.php' => [self::fix(0, 1, "<?php\nclass A {}\n", 'RectorA')],
        ]));
        self::initialize($server, [
            'workspace' => ['applyEdit' => true, 'workspaceEdit' => ['documentChanges' => true]],
        ]);

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///proj/A.php', 'version' => 1]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 21,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        $applyEdit = null;
        $result = null;
        foreach ($responses as $frame) {
            if (($frame['method'] ?? null) === 'workspace/applyEdit') {
                $applyEdit = $frame;
            }
            if (array_key_exists('id', $frame) && $frame['id'] === 21) {
                $result = $frame;
            }
        }

        self::assertNotNull($applyEdit, 'a file that is open but not dirty is not at risk and must still be fixed');
        self::assertSame(
            ['file:///proj/A.php'],
            array_column(array_column(Json::array($applyEdit, 'params', 'edit', 'documentChanges'), 'textDocument'), 'uri'),
        );
        self::assertNotNull($result);
        self::assertNull($result['result']);
    }

    /**
     * Both cases in the same run: the dirty file is skipped and reported,
     * the clean file is fixed normally -- proving the skip is per-file
     * rather than an all-or-nothing refusal of the whole command.
     */
    public function testExecuteCommandFixesCleanFilesAndSkipsDirtyOnesInTheSameRun(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceAndBufferSource([
            '/proj/A.php' => [self::fix(0, 1, "<?php\nclass A {}\n", 'RectorA')],
            '/proj/B.php' => [self::fix(0, 1, "<?php\nclass B {}\n", 'RectorB')],
        ]));
        self::initialize($server, [
            'workspace' => ['applyEdit' => true, 'workspaceEdit' => ['documentChanges' => true]],
        ]);

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///proj/A.php', 'version' => 1]],
        ]);
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => ['uri' => 'file:///proj/A.php', 'version' => 2],
                'contentChanges' => [['text' => "<?php\nclass A { public function unsaved(): void {} }\n"]],
            ],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 22,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        $applyEdit = null;
        $result = null;
        foreach ($responses as $frame) {
            if (($frame['method'] ?? null) === 'workspace/applyEdit') {
                $applyEdit = $frame;
            }
            if (array_key_exists('id', $frame) && $frame['id'] === 22) {
                $result = $frame;
            }
        }

        self::assertNotNull($applyEdit);
        self::assertSame(
            ['file:///proj/B.php'],
            array_column(array_column(Json::array($applyEdit, 'params', 'edit', 'documentChanges'), 'textDocument'), 'uri'),
        );
        self::assertNotNull($result);
        self::assertSame(['skippedDirtyBuffers' => ['file:///proj/A.php']], $result['result']);
    }

    /**
     * Self-review finding (both the Explore and oss:auditor review passes,
     * independently): the dirty-buffer skip must not rely on exact string
     * equality between the URI reconstructed from the on-disk path
     * (pathToUri($absolutePath), whatever case Rector's own file
     * enumeration returned) and the URI the client actually sent on
     * didChange ($this->buffers's own key) -- on a case-insensitive
     * filesystem (macOS default, Windows) those two can differ only in
     * case, and a naive `isset()` would silently miss the dirty buffer,
     * reopening #140 itself. Here the workspace source reports the fix
     * under an upper-cased path while the client opened and dirtied the
     * lower-cased one.
     */
    public function testExecuteCommandSkipsADirtyBufferEvenWhenUriCasingDiffersFromDisk(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceAndBufferSource([
            '/proj/A.PHP' => [self::fix(0, 1, "<?php\nclass A {}\n", 'RectorA')],
        ]));
        self::initialize($server, [
            'workspace' => ['applyEdit' => true, 'workspaceEdit' => ['documentChanges' => true]],
        ]);

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///proj/A.php', 'version' => 1]],
        ]);
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => ['uri' => 'file:///proj/A.php', 'version' => 2],
                'contentChanges' => [['text' => "<?php\nclass A { public function unsaved(): void {} }\n"]],
            ],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 23,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        $applyEdit = null;
        $result = null;
        foreach ($responses as $frame) {
            if (($frame['method'] ?? null) === 'workspace/applyEdit') {
                $applyEdit = $frame;
            }
            if (array_key_exists('id', $frame) && $frame['id'] === 23) {
                $result = $frame;
            }
        }

        self::assertNull(
            $applyEdit,
            'the only fix in this workspace is for a dirty buffer, reported under a differently-cased '
            . 'URI -- no workspace/applyEdit must be sent',
        );
        self::assertNotNull($result);
        self::assertSame(['skippedDirtyBuffers' => ['file:///proj/A.PHP']], $result['result']);
    }

    /**
     * Round-2 self-review finding (oss:auditor, second pass over the fix
     * above): the case-insensitive fallback must not, by itself, conflate
     * two genuinely DIFFERENT files that merely share a case-folded name.
     * On a case-SENSITIVE filesystem (this repo's own ubuntu-latest CI
     * leg) `A.php` and `a.php` can coexist as distinct real files; a dirty
     * buffer for `a.php` must never cause `A.php`'s own, unrelated fix to
     * be silently skipped. Uses real files on disk (via realpath()) rather
     * than the fake fixture paths the other tests use, because this is
     * exactly the disambiguation realpath() exists to provide -- skipped
     * outright on a filesystem where the two names collide into one file
     * (this repo's own dev machines, macOS default), since the scenario
     * this test pins cannot be constructed there at all.
     */
    public function testExecuteCommandDoesNotConflateTwoDistinctFilesSharingACaseFoldedName(): void
    {
        $dir = sys_get_temp_dir() . '/lsp-case-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $upper = $dir . '/A.php';
        $lower = $dir . '/a.php';
        file_put_contents($upper, "<?php\n// A\n");
        file_put_contents($lower, "<?php\n// a\n");

        // Same disambiguation isBufferDirty() itself uses: dev+ino, not
        // realpath(). realpath() on a case-insensitive-but-preserving
        // filesystem (macOS/APFS) just echoes back whichever case was
        // passed in -- it does NOT collapse to the same string for two
        // differently-cased opens of the same file, so comparing realpath()
        // strings here would (wrongly) never detect the collision and this
        // test would silently run its "distinct files" assertions against
        // what is actually a single file on such a filesystem.
        $upperStat = is_file($upper) ? stat($upper) : false;
        $lowerStat = is_file($lower) ? stat($lower) : false;
        $isSameFile = $upperStat !== false && $lowerStat !== false
            && $upperStat['dev'] === $lowerStat['dev'] && $upperStat['ino'] === $lowerStat['ino'];

        if ($upperStat === false || $lowerStat === false || $isSameFile) {
            @unlink($upper);
            @unlink($lower);
            @rmdir($dir);
            self::markTestSkipped(
                'This filesystem is case-insensitive -- A.php and a.php collide into one file, '
                . 'so the two-distinct-files scenario this test pins cannot be constructed here.',
            );
        }

        try {
            $server = new LspServer('1.0.0', self::fakeWorkspaceAndBufferSource([
                $upper => [self::fix(0, 1, "<?php\n// A fixed\n", 'RectorA')],
            ]));
            self::initialize($server, [
                'workspace' => ['applyEdit' => true, 'workspaceEdit' => ['documentChanges' => true]],
            ]);

            // Dirty the DIFFERENT, lowercase file -- A.php itself is never
            // opened or changed, so it has no dirty buffer of its own.
            $lowerUri = 'file://' . $lower;
            $server->handle([
                'jsonrpc' => '2.0',
                'method' => 'textDocument/didOpen',
                'params' => ['textDocument' => ['uri' => $lowerUri, 'version' => 1]],
            ]);
            $server->handle([
                'jsonrpc' => '2.0',
                'method' => 'textDocument/didChange',
                'params' => [
                    'textDocument' => ['uri' => $lowerUri, 'version' => 2],
                    'contentChanges' => [['text' => "<?php\n// a, unsaved\n"]],
                ],
            ]);

            $responses = $server->handle([
                'jsonrpc' => '2.0',
                'id' => 24,
                'method' => 'workspace/executeCommand',
                'params' => ['command' => 'rector-warm.fixWorkspace'],
            ]);
        } finally {
            unlink($upper);
            unlink($lower);
            rmdir($dir);
        }

        $applyEdit = null;
        $result = null;
        foreach ($responses as $frame) {
            if (($frame['method'] ?? null) === 'workspace/applyEdit') {
                $applyEdit = $frame;
            }
            if (array_key_exists('id', $frame) && $frame['id'] === 24) {
                $result = $frame;
            }
        }

        self::assertNotNull(
            $applyEdit,
            "A.php's own fix must still be sent -- it has no dirty buffer of its own, "
            . 'only a distinct, differently-cased file does',
        );
        $uris = array_column(
            array_column(Json::array($applyEdit, 'params', 'edit', 'documentChanges'), 'textDocument'),
            'uri',
        );
        self::assertSame(['file://' . $upper], $uris);
        self::assertNotNull($result);
        self::assertNull($result['result']);
    }

    /**
     * #147: `isBufferDirty()` gated its dev+ino stat comparison behind
     * `strcasecmp($bufferUri, $uri) !== 0` -- comparing the raw URI
     * strings before either side is canonicalised. On macOS `/tmp` is
     * itself a symlink to `/private/tmp`, so a buffer opened through
     * `/tmp/...` and a fix computed for the real `/private/tmp/...` path
     * (or vice versa) never reach the strcasecmp gate at all: the stat
     * comparison this method exists to run never executes, and the
     * disk-derived fix is applied over the unsaved buffer -- exactly the
     * #140 bug, just reached through a symlinked path instead of a
     * case difference.
     *
     * Uses a real symlink under sys_get_temp_dir(), skipped when this
     * platform cannot create one (e.g. an unprivileged Windows account).
     */
    public function testExecuteCommandSkipsADirtyBufferOpenedThroughASymlinkedPath(): void
    {
        self::skipIfInodesAreUnreliable();

        $base = sys_get_temp_dir() . '/lsp-symlink-test-' . bin2hex(random_bytes(4));
        $real = $base . '/real';
        $link = $base . '/link';
        mkdir($real, 0777, true);
        $target = $real . '/A.php';
        file_put_contents($target, "<?php\n// A\n");

        if (!@symlink($real, $link)) {
            @unlink($target);
            @rmdir($real);
            @rmdir($base);
            self::markTestSkipped('This platform could not create a symlink.');
        }

        try {
            $server = new LspServer('1.0.0', self::fakeWorkspaceAndBufferSource([
                $target => [self::fix(0, 1, "<?php\n// A fixed\n", 'RectorA')],
            ]));
            self::initialize($server, [
                'workspace' => ['applyEdit' => true, 'workspaceEdit' => ['documentChanges' => true]],
            ]);

            // Dirtied through the SYMLINKED path -- a different URI string
            // from the disk-derived one the workspace scan will report.
            $symlinkedUri = self::filePathToTestUri($link . '/A.php');
            $server->handle([
                'jsonrpc' => '2.0',
                'method' => 'textDocument/didOpen',
                'params' => ['textDocument' => ['uri' => $symlinkedUri, 'version' => 1]],
            ]);
            $server->handle([
                'jsonrpc' => '2.0',
                'method' => 'textDocument/didChange',
                'params' => [
                    'textDocument' => ['uri' => $symlinkedUri, 'version' => 2],
                    'contentChanges' => [['text' => "<?php\n// A, unsaved\n"]],
                ],
            ]);

            $responses = $server->handle([
                'jsonrpc' => '2.0',
                'id' => 30,
                'method' => 'workspace/executeCommand',
                'params' => ['command' => 'rector-warm.fixWorkspace'],
            ]);
        } finally {
            // #147 round-2 self-review finding, from the same windows-latest
            // CI run as the URI fix above: PHP's unlink() on Windows cannot
            // remove a directory symlink (rmdir() is required there
            // instead; POSIX is the other way around -- rmdir() refuses a
            // symlink even when it points at a directory). The @-silenced
            // unlink() above used to no-op on Windows, leaving $link's
            // directory entry behind after $real was removed and turning
            // rmdir($base) into a "Directory not empty" warning on every
            // run. Trying both, in either order, closes it on both
            // platforms without branching on PHP_OS_FAMILY.
            if (!@unlink($link)) {
                @rmdir($link);
            }
            unlink($target);
            rmdir($real);
            rmdir($base);
        }

        $applyEdit = null;
        $result = null;
        foreach ($responses as $frame) {
            if (($frame['method'] ?? null) === 'workspace/applyEdit') {
                $applyEdit = $frame;
            }
            if (array_key_exists('id', $frame) && $frame['id'] === 30) {
                $result = $frame;
            }
        }

        self::assertNull(
            $applyEdit,
            'the only fix in this workspace is for a dirty buffer opened through a symlinked '
            . 'path -- no workspace/applyEdit must be sent',
        );
        self::assertNotNull($result);
        self::assertSame(['skippedDirtyBuffers' => [self::filePathToTestUri($target)]], $result['result']);
    }

    /**
     * #147: positive control for the symlink test above -- the same file,
     * opened through the symlinked path but never dirtied, must still be
     * fixed normally. Pins that the fix does not turn into "any buffer
     * opened through a symlink is always skipped".
     */
    public function testExecuteCommandStillFixesAFileOpenedThroughASymlinkedPathWhenNotDirty(): void
    {
        $base = sys_get_temp_dir() . '/lsp-symlink-test-' . bin2hex(random_bytes(4));
        $real = $base . '/real';
        $link = $base . '/link';
        mkdir($real, 0777, true);
        $target = $real . '/A.php';
        file_put_contents($target, "<?php\n// A\n");

        if (!@symlink($real, $link)) {
            @unlink($target);
            @rmdir($real);
            @rmdir($base);
            self::markTestSkipped('This platform could not create a symlink.');
        }

        try {
            $server = new LspServer('1.0.0', self::fakeWorkspaceAndBufferSource([
                $target => [self::fix(0, 1, "<?php\n// A fixed\n", 'RectorA')],
            ]));
            self::initialize($server, [
                'workspace' => ['applyEdit' => true, 'workspaceEdit' => ['documentChanges' => true]],
            ]);

            // Opened through the symlinked path, but no didChange -- not dirty.
            $symlinkedUri = self::filePathToTestUri($link . '/A.php');
            $server->handle([
                'jsonrpc' => '2.0',
                'method' => 'textDocument/didOpen',
                'params' => ['textDocument' => ['uri' => $symlinkedUri, 'version' => 1]],
            ]);

            $responses = $server->handle([
                'jsonrpc' => '2.0',
                'id' => 31,
                'method' => 'workspace/executeCommand',
                'params' => ['command' => 'rector-warm.fixWorkspace'],
            ]);
        } finally {
            // #147 round-2 self-review finding, from the same windows-latest
            // CI run as the URI fix above: PHP's unlink() on Windows cannot
            // remove a directory symlink (rmdir() is required there
            // instead; POSIX is the other way around -- rmdir() refuses a
            // symlink even when it points at a directory). The @-silenced
            // unlink() above used to no-op on Windows, leaving $link's
            // directory entry behind after $real was removed and turning
            // rmdir($base) into a "Directory not empty" warning on every
            // run. Trying both, in either order, closes it on both
            // platforms without branching on PHP_OS_FAMILY.
            if (!@unlink($link)) {
                @rmdir($link);
            }
            unlink($target);
            rmdir($real);
            rmdir($base);
        }

        $applyEdit = null;
        $result = null;
        foreach ($responses as $frame) {
            if (($frame['method'] ?? null) === 'workspace/applyEdit') {
                $applyEdit = $frame;
            }
            if (array_key_exists('id', $frame) && $frame['id'] === 31) {
                $result = $frame;
            }
        }

        self::assertNotNull($applyEdit, 'the file has no dirty buffer -- its fix must still be sent');
        $uris = array_column(
            array_column(Json::array($applyEdit, 'params', 'edit', 'documentChanges'), 'textDocument'),
            'uri',
        );
        self::assertSame([self::filePathToTestUri($target)], $uris);
        self::assertNotNull($result);
        self::assertNull($result['result']);
    }

    /**
     * #147 (merged #150): the same `strcasecmp`-on-raw-URI gate also
     * misses a non-canonical percent-encoding of an otherwise identical
     * path -- a literal space in the buffer's own URI vs `%20` (or a
     * different hex case) in the disk-derived URI never compare equal as
     * raw strings, even though `uriToPath()` decodes both to the same
     * filesystem path.
     */
    public function testExecuteCommandSkipsADirtyBufferWhenUriPercentEncodingDiffersFromDisk(): void
    {
        self::skipIfInodesAreUnreliable();

        $dir = sys_get_temp_dir() . '/lsp-percent-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $target = $dir . '/has space.php';
        file_put_contents($target, "<?php\n// A\n");

        try {
            $server = new LspServer('1.0.0', self::fakeWorkspaceAndBufferSource([
                $target => [self::fix(0, 1, "<?php\n// A fixed\n", 'RectorA')],
            ]));
            self::initialize($server, [
                'workspace' => ['applyEdit' => true, 'workspaceEdit' => ['documentChanges' => true]],
            ]);

            // Dirtied via a literal-space URI (not percent-encoded) --
            // pathToUri() would have produced 'file://' . $dir . '/has%20space.php'
            // for the disk-derived side.
            $literalUri = self::filePathToTestUri($dir) . '/has space.php';
            $server->handle([
                'jsonrpc' => '2.0',
                'method' => 'textDocument/didOpen',
                'params' => ['textDocument' => ['uri' => $literalUri, 'version' => 1]],
            ]);
            $server->handle([
                'jsonrpc' => '2.0',
                'method' => 'textDocument/didChange',
                'params' => [
                    'textDocument' => ['uri' => $literalUri, 'version' => 2],
                    'contentChanges' => [['text' => "<?php\n// A, unsaved\n"]],
                ],
            ]);

            $responses = $server->handle([
                'jsonrpc' => '2.0',
                'id' => 32,
                'method' => 'workspace/executeCommand',
                'params' => ['command' => 'rector-warm.fixWorkspace'],
            ]);
        } finally {
            unlink($target);
            rmdir($dir);
        }

        $applyEdit = null;
        $result = null;
        foreach ($responses as $frame) {
            if (($frame['method'] ?? null) === 'workspace/applyEdit') {
                $applyEdit = $frame;
            }
            if (array_key_exists('id', $frame) && $frame['id'] === 32) {
                $result = $frame;
            }
        }

        self::assertNull(
            $applyEdit,
            'the only fix in this workspace is for a dirty buffer whose URI differs from the '
            . 'disk-derived one only in percent-encoding -- no workspace/applyEdit must be sent',
        );
        self::assertNotNull($result);
        self::assertSame(['skippedDirtyBuffers' => [self::filePathToTestUri($dir) . '/has%20space.php']], $result['result']);
    }

    /**
     * #147 (merged #150): positive control for the percent-encoding test
     * above -- the same file, opened via the literal-space URI but never
     * dirtied, must still be fixed normally.
     */
    public function testExecuteCommandStillFixesAFileWhenUriPercentEncodingDiffersFromDiskAndNotDirty(): void
    {
        $dir = sys_get_temp_dir() . '/lsp-percent-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $target = $dir . '/has space.php';
        file_put_contents($target, "<?php\n// A\n");

        try {
            $server = new LspServer('1.0.0', self::fakeWorkspaceAndBufferSource([
                $target => [self::fix(0, 1, "<?php\n// A fixed\n", 'RectorA')],
            ]));
            self::initialize($server, [
                'workspace' => ['applyEdit' => true, 'workspaceEdit' => ['documentChanges' => true]],
            ]);

            $literalUri = self::filePathToTestUri($dir) . '/has space.php';
            $server->handle([
                'jsonrpc' => '2.0',
                'method' => 'textDocument/didOpen',
                'params' => ['textDocument' => ['uri' => $literalUri, 'version' => 1]],
            ]);

            $responses = $server->handle([
                'jsonrpc' => '2.0',
                'id' => 33,
                'method' => 'workspace/executeCommand',
                'params' => ['command' => 'rector-warm.fixWorkspace'],
            ]);
        } finally {
            unlink($target);
            rmdir($dir);
        }

        $applyEdit = null;
        $result = null;
        foreach ($responses as $frame) {
            if (($frame['method'] ?? null) === 'workspace/applyEdit') {
                $applyEdit = $frame;
            }
            if (array_key_exists('id', $frame) && $frame['id'] === 33) {
                $result = $frame;
            }
        }

        self::assertNotNull($applyEdit, 'the file has no dirty buffer -- its fix must still be sent');
        $uris = array_column(
            array_column(Json::array($applyEdit, 'params', 'edit', 'documentChanges'), 'textDocument'),
            'uri',
        );
        self::assertSame([self::filePathToTestUri($dir) . '/has%20space.php'], $uris);
        self::assertNotNull($result);
        self::assertNull($result['result']);
    }

    /**
     * #245 coverage: takeReadyDiagnostics() drops a held buffer result once
     * the document's version has moved on since runDueDiagnostics()
     * snapshotted it -- mirrors testAStaleResultIsDiscardedWhenTheVersionChangedMidCall
     * above, but on the buffer/debounce path. runDueDiagnostics() captures
     * the version BEFORE calling diagnoseBuffer() and only stores it into
     * $readyResults AFTER that call returns; a diagnoseBuffer() that
     * (synchronously, by re-entering handle()) causes a NEWER version to be
     * recorded for the same uri leaves takeReadyDiagnostics() holding a
     * result for a version that is no longer current.
     */
    public function testAReadyBufferResultForAStaleVersionIsDroppedWhenTheVersionMovedOnMidRun(): void
    {
        $holder = new class {
            public ?LspServer $server = null;
        };
        $racy = new class ($holder) implements BufferDiagnosticsSource {
            /** @param object{server: ?LspServer} $holder */
            public function __construct(private readonly object $holder) {}

            public function diagnose(string $absolutePath): array
            {
                return ['fixes' => []];
            }

            public function diagnoseBuffer(string $absolutePath, string $content): array
            {
                $this->holder->server?->handle([
                    'jsonrpc' => '2.0',
                    'method' => 'textDocument/didSave',
                    'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 2]],
                ]);

                return ['fixes' => []];
            }
        };
        $server = new LspServer('1.0.0', $racy);
        $holder->server = $server;

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1],
                'contentChanges' => [['text' => "<?php\n"]],
            ],
        ]);

        $server->runDueDiagnostics(1.0e12);

        self::assertSame([], $server->takeReadyDiagnostics());
    }

    /**
     * #245 coverage: changeDocument() refuses (returns []) when the
     * configured diagnostics source does not implement
     * BufferDiagnosticsSource at all -- there is nowhere to hold an
     * unsaved-buffer diagnosis, so textDocument/didChange is a no-op.
     */
    public function testDidChangeIsANoOpWithoutABufferCapableSource(): void
    {
        $server = new LspServer('1.0.0', self::fakeSource([]));

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1],
                'contentChanges' => [['text' => "<?php\n"]],
            ],
        ]);

        self::assertSame([], $responses);
        self::assertNull($server->nextDiagnosticsDeadline());
    }

    /**
     * #245 coverage: `initialize` with no `capabilities` key at all (a
     * conforming-but-minimal client, or one that omitted it) must treat
     * every per-feature capability check as unsupported rather than
     * crashing on a missing array -- clientSupportsDocumentChanges(),
     * clientSupportsApplyEdit(), clientSupportsDynamicWatchedFiles() and
     * clientSupportsWorkDoneProgress() all short-circuit to false on a
     * non-array $capabilities.
     */
    public function testInitializeTreatsMissingCapabilitiesAsUnsupported(): void
    {
        $server = new LspServer('1.0.0', self::fakeWorkspaceAndBufferSource([
            '/proj/Sample.php' => [self::fix(0, 1, "<?php\n", 'SomeRector')],
        ]));

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['processId' => null, 'rootUri' => null],
        ]);

        self::assertArrayNotHasKey('executeCommandProvider', Json::array($responses, 0, 'result', 'capabilities'));

        // Positive control: workspace.applyEdit declared explicitly still
        // works normally, proving the no-capabilities case above is really
        // "treated as unsupported", not a crash this call just happened to
        // survive.
        $withApplyEdit = new LspServer('1.0.0', self::fakeWorkspaceAndBufferSource([
            '/proj/Sample.php' => [self::fix(0, 1, "<?php\n", 'SomeRector')],
        ]));
        $withResponses = $withApplyEdit->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => self::APPLY_EDIT_ONLY],
        ]);
        self::assertArrayHasKey('executeCommandProvider', Json::array($withResponses, 0, 'result', 'capabilities'));
    }

    /**
     * #245 coverage: diagnoseDocument() (didOpen/didSave) refuses (returns
     * []) when the textDocument params carry no string uri -- there is
     * nothing to diagnose.
     */
    public function testDidOpenWithAMissingUriIsANoOp(): void
    {
        $server = new LspServer('1.0.0', self::fakeSource([]));

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['version' => 1]],
        ]);

        self::assertSame([], $responses);
    }

    /**
     * #245 coverage: isProgressCreateRefused()'s retry loop re-checks the
     * deadline at the TOP of each iteration (not only after a null read),
     * so the window can elapse while $tryReadAhead keeps returning real
     * (unrelated) messages, never null. Each fake call sleeps long enough
     * that a handful of iterations exhausts the 0.2s window from inside
     * the loop's own deadline check, pinning the branch
     * testAnUnrelatedReadAheadMessageIsPushedBackAndProgressIsSkipped above
     * does not reach (that one terminates via the $message === null branch
     * instead).
     */
    public function testProgressTimesOutWhileStillReceivingUnrelatedMessages(): void
    {
        $pushedBack = [];
        $unrelated = ['jsonrpc' => '2.0', 'method' => 'textDocument/didSave', 'params' => ['textDocument' => ['uri' => 'file:///tmp/Other.php']]];

        $server = new LspServer(
            '1.0.0',
            self::fakeSource([]),
            tryReadAhead: static function (float $timeoutSeconds) use ($unrelated): array {
                usleep(60_000);

                return $unrelated;
            },
            pushBackMessage: function (array $message) use (&$pushedBack): void {
                $pushedBack[] = $message;
            },
        );

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => ['window' => ['workDoneProgress' => true]]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $methods = array_map(static fn(array $frame): ?string => Json::optionalString($frame, 'method'), $responses);

        self::assertSame(
            ['window/workDoneProgress/create', 'textDocument/publishDiagnostics'],
            $methods,
        );
        self::assertNotEmpty($pushedBack, 'every unrelated message consumed while waiting must still be pushed back');
        self::assertSame($unrelated, $pushedBack[0]);
    }

    /**
     * #245 coverage: clearDocument() (didClose) refuses (returns []) when
     * the textDocument params carry no string uri.
     */
    public function testDidCloseWithAMissingUriIsANoOp(): void
    {
        $server = new LspServer('1.0.0', self::fakeSource([]));

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didClose',
            'params' => ['textDocument' => []],
        ]);

        self::assertSame([], $responses);
    }

    /**
     * #245 coverage: codeAction() refuses with an empty result (never a
     * JSON-RPC error) when the textDocument params carry no string uri.
     */
    public function testCodeActionWithAMissingUriReturnsNoActions(): void
    {
        $server = new LspServer('1.0.0', self::fakeSource([]));

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'textDocument/codeAction',
            'params' => [
                'textDocument' => [],
                'context' => ['diagnostics' => []],
            ],
        ]);

        self::assertSame([], $responses[0]['result']);
    }

    /**
     * #245 coverage: codeAction()'s #216 recompute-before-edit guard offers
     * nothing when the freshly recomputed fixes come back empty, even
     * though the cached (possibly session-served) $fixesByUri was
     * non-empty -- the stale cached text must never be the only thing
     * standing between an empty recompute and a WorkspaceEdit.
     */
    public function testCodeActionOffersNothingWhenTheFreshRecomputeReturnsNoFixes(): void
    {
        $cached = [self::fix(3, 12, "stale\n", 'SimplifyIfReturnBoolRector')];
        $source = new class ($cached) implements DiagnosticsSource, EditDiagnosticsSource {
            /** @param list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}> $cached */
            public function __construct(private readonly array $cached) {}

            public function diagnose(string $absolutePath): array
            {
                return ['fixes' => $this->cached];
            }

            public function diagnoseForEdit(string $absolutePath): array
            {
                return ['fixes' => []];
            }

            public function diagnoseBufferForEdit(string $absolutePath, string $content): array
            {
                return ['fixes' => []];
            }
        };
        $server = new LspServer('1.0.0', $source);
        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => ['textDocument' => ['uri' => 'file:///tmp/Sample.php', 'version' => 1]],
        ]);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 20,
            'method' => 'textDocument/codeAction',
            'params' => [
                'textDocument' => ['uri' => 'file:///tmp/Sample.php'],
                'context' => ['diagnostics' => []],
            ],
        ]);

        self::assertSame([], $responses[0]['result']);
    }

    /**
     * #245 coverage: executeCommand() refuses rector-warm.fixWorkspace with
     * a visible JSON-RPC error when the configured diagnostics source does
     * not implement WorkspaceDiagnosticsSource at all -- there is nothing a
     * workspace-wide fix could run against.
     */
    public function testExecuteCommandRefusesFixWorkspaceWithoutAWorkspaceFixSource(): void
    {
        $server = new LspServer('1.0.0', self::fakeSource([]));
        self::initialize($server, self::APPLY_EDIT_ONLY);

        $responses = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'workspace/executeCommand',
            'params' => ['command' => 'rector-warm.fixWorkspace'],
        ]);

        self::assertCount(1, $responses);
        self::assertSame(12, $responses[0]['id']);
        self::assertArrayHasKey('error', $responses[0]);
        self::assertSame(-32803, Json::at($responses, 0, 'error', 'code'));
        self::assertStringContainsString('no workspace-fix source configured', Json::string($responses, 0, 'error', 'message'));
    }

    /**
     * #245 coverage: parseRange() returns null (treated exactly like a
     * missing range -- every fix offered, none filtered by overlap) when
     * `range` is present and is an array, but `start`/`end` are malformed
     * (here: a non-int `line`) -- distinct from the missing-range case
     * already covered elsewhere, which never reaches parseRange()'s
     * array-shape check at all.
     */
    public function testCodeActionTreatsAMalformedRangeAsMissing(): void
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
            'id' => 21,
            'method' => 'textDocument/codeAction',
            'params' => [
                'textDocument' => ['uri' => 'file:///tmp/Sample.php'],
                'range' => ['start' => ['line' => 'not-an-int', 'character' => 0], 'end' => ['line' => 12, 'character' => 0]],
                'context' => ['diagnostics' => []],
            ],
        ]);

        $actions = Json::array($responses, 0, 'result');
        self::assertCount(2, $actions);
        self::assertSame('Apply Rector: SimplifyIfReturnBoolRector', Json::at($actions, 0, 'title'));
    }
}
