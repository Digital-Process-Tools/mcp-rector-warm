<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Tests\Support\Json;
use Dpt\McpRectorWarm\Lsp\BufferDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\EditDiagnosticsSource;
use Dpt\McpRectorWarm\Lsp\LspServer;
use Dpt\McpRectorWarm\Support\Scalar;
use PHPUnit\Framework\TestCase;

/**
 * #206: a named (not anonymous) test double for LspServerBufferTest::source()
 * so that $calls -- not part of the BufferDiagnosticsSource interface -- has
 * a real, statically-known type instead of relying on PHPStan widening an
 * anonymous class back to the interface it implements.
 *
 * A buffer-capable fake: content containing "fixable" yields one fix, content
 * containing "broken" yields one error, anything else is clean. Records
 * every call so a test can assert on what ran.
 */
final class LspServerBufferTestFakeSource implements BufferDiagnosticsSource
{
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
}

/**
 * #216: same as LspServerBufferTestFakeSource, but also implements
 * EditDiagnosticsSource so codeAction()'s recompute-before-edit guard has a
 * buffer-path positive control: diagnoseBuffer() (the cached/stale path) and
 * diagnoseBufferForEdit() (the forced-no-session/fresh path) deliberately
 * return different text, the same shape LspServerTest's disk-path test uses.
 */
final class LspServerBufferTestFakeEditSource implements BufferDiagnosticsSource, EditDiagnosticsSource
{
    public function diagnose(string $absolutePath): array
    {
        return ['fixes' => []];
    }

    /**
     * @return array{
     *   fixes: list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>,
     *   errors?: list<array{message: string, line: int}>,
     * }
     */
    public function diagnoseForEdit(string $absolutePath): array
    {
        return ['fixes' => []];
    }

    public function diagnoseBuffer(string $absolutePath, string $content): array
    {
        return ['fixes' => [[
            'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 2, 'character' => 0]],
            'newText' => "stale\n",
            'rectors' => ['SimplifyIfReturnBoolRector'],
        ]]];
    }

    /**
     * @return array{
     *   fixes: list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>,
     *   errors?: list<array{message: string, line: int}>,
     * }
     */
    public function diagnoseBufferForEdit(string $absolutePath, string $content): array
    {
        return ['fixes' => [[
            'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 2, 'character' => 0]],
            'newText' => "fresh\n",
            'rectors' => ['SimplifyIfReturnBoolRector'],
        ]]];
    }
}

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

    private static function source(): LspServerBufferTestFakeSource
    {
        return new LspServerBufferTestFakeSource();
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
     * @return array<mixed>
     */
    private static function codeAction(LspServer $server): array
    {
        return Json::array($server->handle([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'textDocument/codeAction',
            'params' => [
                'textDocument' => ['uri' => self::URI],
                'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 0]],
                'context' => ['diagnostics' => []],
            ],
        ]), 0, 'result');
    }

    public function testInitializeAdvertisesFullSyncWhenTheSourceCanDiagnoseBuffers(): void
    {
        $server = new LspServer('1.0.0', self::source());

        $response = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => []]]);

        self::assertSame(1, Json::at($response, 0, 'result', 'capabilities', 'textDocumentSync', 'change'));
    }

    public function testInitializeKeepsSyncNoneWhenTheSourceCannotDiagnoseBuffers(): void
    {
        $server = new LspServer('1.0.0');

        $response = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => []]]);

        self::assertSame(0, Json::at($response, 0, 'result', 'capabilities', 'textDocumentSync', 'change'));
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
        self::assertSame(self::URI, Json::at($frames, 0, 'params', 'uri'));
        self::assertSame(2, Json::at($frames, 0, 'params', 'version'));
        self::assertCount(1, Json::array($frames, 0, 'params', 'diagnostics'));
        self::assertSame('SimplifyIfReturnBoolRector', Json::at($frames, 0, 'params', 'diagnostics', 0, 'message'));
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
        self::assertSame(3, Json::at($server->takeReadyDiagnostics(), 0, 'params', 'version'));
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
        self::assertSame(2, Json::at($frames, 0, 'params', 'version'));
        self::assertSame([], Json::at($frames, 0, 'params', 'diagnostics'));
    }

    public function testABufferChangedToCleanContentPublishesAnEmptyList(): void
    {
        $server = new LspServer('1.0.0', self::source());

        self::change($server, 1, "<?php\nfixable\n");
        $server->runDueDiagnostics(self::LATER);
        self::assertCount(1, Json::array($server->takeReadyDiagnostics(), 0, 'params', 'diagnostics'));

        self::change($server, 2, "<?php\nclean\n");
        $server->runDueDiagnostics(self::LATER);
        $frames = $server->takeReadyDiagnostics();

        self::assertCount(1, $frames);
        self::assertSame([], Json::at($frames, 0, 'params', 'diagnostics'));
    }

    public function testABrokenBufferPublishesTheErrorDiagnostic(): void
    {
        $server = new LspServer('1.0.0', self::source());

        self::change($server, 1, "<?php\nbroken\n");
        $server->runDueDiagnostics(self::LATER);
        $diagnostics = Json::array($server->takeReadyDiagnostics(), 0, 'params', 'diagnostics');

        self::assertCount(1, $diagnostics);
        self::assertSame(1, Json::at($diagnostics, 0, 'severity'));
        self::assertSame(2, Json::at($diagnostics, 0, 'range', 'start', 'line'));
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
        self::assertSame("fixed\n", Json::at($actions, 0, 'edit', 'changes', self::URI, 0, 'newText'));

        // Must not fire: the buffer is now at version 2, so ranges computed
        // for version 1 would land on the wrong text.
        self::change($server, 2, "<?php\n\nfixable\n");
        self::assertSame([], self::codeAction($server));
    }

    /**
     * #216, auditor self-review finding (buffer branch had no positive
     * control): codeAction()'s recompute-before-edit guard has two branches
     * -- diagnoseBufferForEdit() for an open buffer, diagnoseForEdit() for
     * disk (already covered in LspServerTest). This pins the buffer one:
     * diagnoseBuffer() and diagnoseBufferForEdit() deliberately return
     * different text, so a regression back to the cached (stale) buffer fix
     * would fail this test.
     */
    public function testCodeActionOnAnOpenBufferRecomputesTheEditWithTheSessionForcedOff(): void
    {
        $server = new LspServer('1.0.0', new LspServerBufferTestFakeEditSource());

        self::change($server, 1, "<?php\nfixable\n");
        $server->runDueDiagnostics(self::LATER);
        $server->takeReadyDiagnostics();

        $actions = self::codeAction($server);
        self::assertNotSame([], $actions);
        self::assertSame('fresh' . "\n", Json::at($actions, 0, 'edit', 'changes', self::URI, 0, 'newText'));
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

        $edit = Json::array(self::codeAction($server), 0, 'edit');
        self::assertArrayNotHasKey('changes', $edit);
        self::assertSame(['uri' => self::URI, 'version' => 4], Json::at($edit, 'documentChanges', 0, 'textDocument'));
        self::assertSame("fixed\n", Json::at($edit, 'documentChanges', 0, 'edits', 0, 'newText'));
    }

    public function testDidCloseDropsThePendingBuffer(): void
    {
        $source = self::source();
        $server = new LspServer('1.0.0', $source);

        self::change($server, 1, "<?php\nfixable\n");
        $closed = $server->handle(['jsonrpc' => '2.0', 'method' => 'textDocument/didClose', 'params' => ['textDocument' => ['uri' => self::URI]]]);
        self::assertSame([], Json::at($closed, 0, 'params', 'diagnostics'));

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

    /**
     * @return list<array<string, mixed>>
     */
    private static function watchedChange(LspServer $server, string $uri): array
    {
        return $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => ['changes' => [['uri' => $uri, 'type' => 2]]],
        ]);
    }

    public function testAWatchedEventForTheTempCopyOfRectorPhpDoesNotRequeueBuffers(): void
    {
        // Must not fire (#106 E2E finding, reload loop): the temp copy of an
        // unsaved rector.php lives at <dir>/.rector-warm-<pid>/rector.php,
        // which a client watching **/rector.php reports as a config change.
        // Treating it as one re-queued every buffer, including rector.php's,
        // which wrote a new temp copy, and so on forever.
        $source = self::source();
        $server = new LspServer('1.0.0', $source);
        self::change($server, 1, "<?php\nfixable\n");
        $server->runDueDiagnostics(self::LATER);
        $server->takeReadyDiagnostics();

        self::assertSame([], self::watchedChange($server, 'file:///tmp/.rector-warm-4242/rector.php'));
        self::assertNull($server->nextDiagnosticsDeadline());
        $server->runDueDiagnostics(self::LATER);
        self::assertCount(1, $source->calls);

        // Positive control: the real rector.php re-queues the buffer.
        self::watchedChange($server, 'file:///tmp/rector.php');
        self::assertNotNull($server->nextDiagnosticsDeadline());
        $server->runDueDiagnostics(self::LATER);
        self::assertCount(2, $source->calls);
    }

    public function testAWatchedConfigChangeDoesNotDiagnoseADocumentThatOnlyEverHadAnIneligibleDidChange(): void
    {
        // Must not fire (#106 rebase finding): a didChange for an untitled:
        // buffer is never buffer-eligible (changeDocument()'s own
        // file:/full-sync/isWatchedConfigFile guard) -- but that guard runs
        // AFTER documentVersions[$uri] is already written, so the ineligible
        // URI still leaves a documentVersions entry with no buffer and no
        // activityOrder entry (no didOpen/didSave either). The
        // watchedFilesChanged() union added for the rebase (see its own
        // comment) must not turn that leftover entry into a spurious
        // diagnose() call on the next real config change.
        $source = self::source();
        $server = new LspServer('1.0.0', $source);

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => ['uri' => 'untitled:Untitled-1', 'version' => 1],
                'contentChanges' => [['text' => "<?php\nfixable\n"]],
            ],
        ]);

        // Positive control paired with the must-not-fire case above: a
        // real, currently-open buffer document must still get requeued by
        // the same config change -- proving the exclusion is targeted, not
        // a broadcast "the loop no longer runs" bug.
        self::change($server, 1, "<?php\nfixable\n");

        self::watchedChange($server, 'file:///tmp/rector.php');
        self::assertNotNull($server->nextDiagnosticsDeadline());
        $server->runDueDiagnostics(self::LATER);

        self::assertSame(
            [['kind' => 'buffer', 'path' => '/tmp/Sample.php', 'content' => "<?php\nfixable\n"]],
            $source->calls,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function configFileUris(): iterable
    {
        yield 'rector.php' => ['file:///tmp/rector.php'];
        yield 'composer.lock' => ['file:///tmp/composer.lock'];
        yield 'differently cased' => ['file:///tmp/Rector.PHP'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('configFileUris')]
    public function testAnUnsavedConfigFileIsNotBufferDiagnosed(string $uri): void
    {
        // Must not fire: rector.php and composer.lock are configuration, not
        // source Rector refactors; diagnosing their buffers is what wrote the
        // temp copy the watcher then reported. The must-fire control is
        // testADidChangeWithFixableContentPublishesADiagnosticWithoutAnySave.
        $source = self::source();
        $server = new LspServer('1.0.0', $source);

        $server->handle([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => ['uri' => $uri, 'version' => 2],
                'contentChanges' => [['text' => "<?php\nfixable\n"]],
            ],
        ]);

        self::assertNull($server->nextDiagnosticsDeadline());
        $server->runDueDiagnostics(self::LATER);
        self::assertSame([], $source->calls);
    }

    /**
     * #131: follow-up from #111/#128 -- runDueDiagnostics() is reached when
     * a client sends textDocument/didChange without ever having sent
     * didOpen for that document (spec-legal). That debounced run is just as
     * much a cold-boot "first diagnose" as diagnoseDocument()'s own, so it
     * must report progress and flip hasBootedOnce too. A shared log between
     * the frame writer and the fake diagnostics source is the only way to
     * observe that create/begin are written LIVE, before diagnoseBuffer()
     * runs -- exactly the pattern
     * testProgressCreateAndBeginAreWrittenToTheTransportBeforeDiagnoseRuns
     * (LspServerTest) uses for the didOpen path.
     */
    public function testRunDueDiagnosticsReportsColdBootProgressWithNoPrecedingDidOpen(): void
    {
        /** @var list<string> */
        $entries = [];
        $addEntry = function (string $entry) use (&$entries): void {
            $entries[] = $entry;
        };

        $source = new class ($addEntry) implements BufferDiagnosticsSource {
            /** @param \Closure(string): void $addEntry */
            public function __construct(private readonly \Closure $addEntry) {}

            public function diagnose(string $absolutePath): array
            {
                return ['fixes' => []];
            }

            public function diagnoseBuffer(string $absolutePath, string $content): array
            {
                ($this->addEntry)('diagnose-called');

                return ['fixes' => []];
            }
        };

        $frameWriter = function (array $frame) use ($addEntry): void {
            $addEntry('wrote:' . Scalar::toString($frame['method'] ?? '?', 'frame method'));
        };

        $server = new LspServer('1.0.0', $source, frameWriter: $frameWriter);

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['capabilities' => ['window' => ['workDoneProgress' => true]]],
        ]);

        // Must fire: no didOpen is ever sent -- didChange is this
        // document's only touch, and this is the server's very first
        // diagnose overall.
        self::change($server, 1, "<?php\nfixable\n");
        $server->runDueDiagnostics(self::LATER);

        self::assertSame([
            'wrote:window/workDoneProgress/create',
            'wrote:$/progress',
            'diagnose-called',
            'wrote:$/progress',
        ], $entries);
    }

    /**
     * #245 coverage: runDueDiagnostics() guards its whole body on the
     * configured source actually being buffer-capable, clearing
     * $pendingDeadlines (rather than leaving stale entries behind) and
     * returning before touching anything else. A non-buffer source can
     * never legitimately accumulate a pending deadline through the public
     * surface (changeDocument() itself refuses to schedule one without a
     * BufferDiagnosticsSource -- see LspServerTest::
     * testDidChangeIsANoOpWithoutABufferCapableSource), so this test seeds
     * the private state directly to pin the guard's own clearing effect
     * rather than merely executing it as a no-op.
     */
    public function testRunDueDiagnosticsClearsPendingDeadlinesWhenTheSourceCannotDiagnoseBuffers(): void
    {
        $server = new LspServer('1.0.0');

        $property = new \ReflectionProperty(LspServer::class, 'pendingDeadlines');
        $property->setValue($server, [self::URI => 0.0]);

        self::assertNotNull($server->nextDiagnosticsDeadline());

        $server->runDueDiagnostics(microtime(true));

        self::assertNull($server->nextDiagnosticsDeadline());
        self::assertSame([], $server->takeReadyDiagnostics());
    }
}
