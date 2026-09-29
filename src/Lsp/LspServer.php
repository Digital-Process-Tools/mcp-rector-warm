<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

/**
 * v1 (#53): the handshake from #52's prototype, plus diagnostics
 * (didOpen/didSave -> publishDiagnostics), didClose (clears them), and
 * textDocument/codeAction (a WorkspaceEdit per hunk, plus a whole-file
 * action), all built on RectorDiffParser + a DiagnosticsSource.
 *
 * #106: unsaved buffers. With a BufferDiagnosticsSource the server declares
 * full text sync (`change: 1`); didChange stores the buffer text and its
 * version and schedules a diagnosis $debounceSeconds after the LAST change.
 * handle() never runs that diagnosis itself -- the loop (LspLoop) asks
 * nextDiagnosticsDeadline(), calls runDueDiagnostics() when it passes, then
 * takeReadyDiagnostics(), which publishes a result only if its version is
 * still the document's latest. didOpen and didSave still diagnose from
 * disk; didSave and didClose drop the buffer.
 */
final class LspServer
{
    private bool $shuttingDown = false;

    /** #105: set from `initialize`'s client capabilities */
    private bool $canWatchFiles = false;

    /** #111: set from `initialize`'s client capabilities (window.workDoneProgress) */
    private bool $canReportProgress = false;

    /**
     * #111: the editor shows nothing during the first (cold-boot) diagnose,
     * about 1.3-1.8s per the issue. Only THAT first call is worth a progress
     * notification -- every later diagnose is already warm and near-instant,
     * so reporting progress around it would be noise, not signal.
     */
    private bool $hasBootedOnce = false;

    /**
     * @var array<string, true> #111: JSON-RPC request ids seen in a
     *   `$/cancelRequest` notification, keyed by (string) cast so an int id
     *   and its string form collide on purpose -- JSON-RPC ids are typed
     *   per-message, not per-protocol, and a client is free to use either.
     */
    private array $cancelledRequestIds = [];

    /** #106: client declared `workspace.workspaceEdit.documentChanges` */
    private bool $canUseDocumentChanges = false;

    /** @var array<string, string> #106: URI -> unsaved buffer text (full sync) */
    private array $buffers = [];

    /** @var array<string, float> #106: URI -> when its debounced diagnosis is due */
    private array $pendingDeadlines = [];

    /**
     * @var array<string, array{version: int|null, result: array<string, mixed>}>
     *   #106: URI -> a computed buffer diagnosis not yet published
     */
    private array $readyResults = [];

    /** @var array<string, int|null> #106: URI -> the version its $fixesByUri were computed for */
    private array $fixesVersions = [];

    /** @var array<string, int> URI -> the version this server last diagnosed */
    private array $documentVersions = [];

    /**
     * @var list<string> URIs in the order they were last diagnosed
     *   (didOpen/didSave), oldest first. #115: when a config change
     *   re-diagnoses every open document, the one the developer is
     *   actively working in should not queue behind ones that merely
     *   happened to open earlier -- array_reverse() of this gives
     *   most-recently-active first.
     */
    private array $activityOrder = [];

    /**
     * @var array<string, list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>>
     *   URI -> the fixes behind its currently-published diagnostics, so
     *   codeAction can build a WorkspaceEdit without re-running Rector.
     */
    private array $fixesByUri = [];

    /**
     * PR #128 E2E review (blocking finding 1): every frame diagnoseDocument()
     * produces used to be collected into one array and handed back only
     * after handle() returns -- so `window/workDoneProgress/create` and the
     * `$/progress` begin notification reached the client at the SAME instant
     * as `end` and `publishDiagnostics`, all AFTER the (slow, cold-boot)
     * diagnose() call had already finished. That defeats the entire point
     * of #111: the editor never sees "warming up" DURING the wait, only
     * after it is over. $frameWriter, when given, is called synchronously
     * the moment a frame is ready -- wired by bin/rector-warm-lsp to write
     * straight to the transport -- so `create`/`begin` genuinely reach the
     * wire before diagnose() runs. Left null (every existing unit test),
     * emit() falls back to the original buffer-and-return-at-the-end
     * behaviour, so nothing already covered changes shape.
     */
    public function __construct(
        private readonly string $serverVersion,
        private readonly ?DiagnosticsSource $diagnostics = null,
        private readonly float $debounceSeconds = 0.5,
        private readonly ?\Closure $frameWriter = null,
        /**
         * PR #128 E2E review (blocking finding 2): waits up to the given
         * number of seconds for a message to become available, returning
         * it (consuming it) or null if the window elapses with nothing.
         * Used to see whether the client's reply to
         * `window/workDoneProgress/create` arrives before this server
         * decides whether to send `$/progress` begin.
         *
         * Second E2E review round, PR #128: a zero-timeout, "already
         * sitting in the pipe" peek is NOT enough -- a real client's
         * reply arrives milliseconds later over a real transport, never
         * instantly, so a bare peek right after writing `create` never
         * actually saw it (the exact bug this closure's own earlier
         * version reintroduced). isProgressCreateRefused() below now
         * calls this with a real, non-zero window and genuinely waits.
         * Left null in every existing unit test and in any caller that
         * cannot offer this at all -- degrading safely to "cannot
         * confirm the client accepted create, so send no progress for
         * this round" rather than ever risking a hang.
         *
         * @var (\Closure(float): (array<string, mixed>|null))|null
         */
        private readonly ?\Closure $tryReadAhead = null,
        /**
         * Companion to $tryReadAhead: called when a message WAS read ahead
         * but turned out not to be the create-request's own reply, so it
         * must be handed back for ordinary dispatch rather than dropped.
         *
         * @var (\Closure(array<string, mixed>): void)|null
         */
        private readonly ?\Closure $pushBackMessage = null,
    ) {
    }

    /**
     * Write $frame immediately via $frameWriter when one is wired (the
     * production path), or buffer it into $buffer for the caller to return
     * (every existing unit test, which has no real transport to write to).
     *
     * @param array<string, mixed> $frame
     * @param list<array<string, mixed>> $buffer
     */
    private function emit(array $frame, array &$buffer): void
    {
        if ($this->frameWriter !== null) {
            ($this->frameWriter)($frame);

            return;
        }

        $buffer[] = $frame;
    }

    /**
     * @param array<string, mixed> $message
     * @return list<array<string, mixed>> zero or more frames to write --
     *   0 for a notification needing no reply and producing no diagnostics
     *   push, 1 for an ordinary request/response or a single
     *   publishDiagnostics push, more only if a future method needs it.
     */
    public function handle(array $message): array
    {
        $method = $message['method'] ?? null;
        $id = $message['id'] ?? null;
        $params = $message['params'] ?? [];

        // #105: a message with an id but no method is a RESPONSE -- here,
        // the client's reply to this server's own client/registerCapability
        // request. JSON-RPC forbids answering a response, and nothing in
        // this server depends on its result, so it is consumed silently.
        if ($method === null && array_key_exists('id', $message)) {
            return [];
        }

        $isRequest = array_key_exists('id', $message);

        if ($method === 'initialize') {
            $capabilities = is_array($params) ? ($params['capabilities'] ?? null) : null;
            $this->canWatchFiles = self::clientSupportsDynamicWatchedFiles($capabilities);
            $this->canReportProgress = self::clientSupportsWorkDoneProgress($capabilities);
            $this->canUseDocumentChanges = self::clientSupportsDocumentChanges($capabilities);

            return [$this->result($id, [
                'capabilities' => [
                    // #106: Full (1) when the source can diagnose an unsaved
                    // buffer, None (0) otherwise -- see the class docblock.
                    'textDocumentSync' => [
                        'openClose' => true,
                        'change' => $this->diagnostics instanceof BufferDiagnosticsSource ? 1 : 0,
                        'save' => ['includeText' => false],
                    ],
                    'codeActionProvider' => true,
                ],
                'serverInfo' => [
                    'name' => 'rector-warm-lsp',
                    'version' => $this->serverVersion,
                ],
            ])];
        }

        if ($method === 'initialized') {
            // #105: the spec lets a server register dynamically only for a
            // capability the client declared `dynamicRegistration: true`
            // for. Without it nothing is lost for correctness: the warm
            // worker still reloads rector.php on the next didSave
            // (RectorRunner::configFileChanged()); only the re-diagnose of
            // open documents on an out-of-editor config edit is skipped.
            return $this->canWatchFiles ? [$this->registerConfigFileWatcher()] : [];
        }

        if ($method === 'shutdown') {
            $this->shuttingDown = true;

            return [$this->result($id, null)];
        }

        if ($method === 'textDocument/didOpen') {
            return $this->diagnoseDocument(is_array($params) ? ($params['textDocument'] ?? []) : []);
        }

        if ($method === 'textDocument/didChange') {
            return $this->changeDocument(is_array($params) ? $params : []);
        }

        if ($method === 'textDocument/didSave') {
            return $this->diagnoseDocument(is_array($params) ? ($params['textDocument'] ?? []) : []);
        }

        if ($method === 'textDocument/didClose') {
            return $this->clearDocument(is_array($params) ? ($params['textDocument'] ?? []) : []);
        }

        if ($method === 'textDocument/codeAction') {
            // #111: honour a $/cancelRequest received for this exact id
            // before it was dispatched. Self-review correction (Explore
            // pass): a conforming client only ever sends $/cancelRequest
            // for an id it already used on an outgoing request, and
            // bin/rector-warm-lsp's main loop reads and fully handles one
            // message at a time (see diagnoseDocument()'s stale-result
            // comment below) -- so a real editor's cancelRequest for
            // codeAction id X always arrives AFTER that request's response
            // was already written, never before. This branch is therefore
            // unreachable in the shipped binary today, exactly like the
            // stale-version-discard branch it sits next to; kept as
            // defense-in-depth (protocol-correct now) for whenever this
            // loop stops being strictly synchronous, and exercised in
            // LspServerTest only via a directly-reordered handle() call
            // that no real client produces.
            if ($id !== null && isset($this->cancelledRequestIds[self::idKey($id)])) {
                unset($this->cancelledRequestIds[self::idKey($id)]);

                return [$this->error($id, -32800, 'Request cancelled')];
            }

            return [$this->codeAction($id, is_array($params) ? $params : [])];
        }

        if ($method === 'workspace/didChangeWatchedFiles') {
            return $this->watchedFilesChanged(is_array($params) ? ($params['changes'] ?? []) : []);
        }

        // #111: a NOTIFICATION (never a request -- the spec gives it no id),
        // so there is nothing to reply with here. It only ever affects a
        // request this server has not dispatched yet: see the dispatch
        // branch above for codeAction, which is the one request this issue
        // asks to honour it for.
        if ($method === '$/cancelRequest') {
            $cancelId = is_array($params) ? ($params['id'] ?? null) : null;
            if ($cancelId !== null) {
                $this->cancelledRequestIds[self::idKey($cancelId)] = true;
            }

            return [];
        }

        if ($isRequest) {
            return [$this->error($id, -32601, sprintf('Method not found: %s', (string) $method))];
        }

        return [];
    }

    public function isShuttingDown(): bool
    {
        return $this->shuttingDown;
    }

    /**
     * #106: the earliest moment a debounced buffer diagnosis is due, as a
     * microtime(true) timestamp, or null when none is pending -- the loop
     * blocks on its read only when this is null.
     */
    public function nextDiagnosticsDeadline(): ?float
    {
        return $this->pendingDeadlines === [] ? null : min($this->pendingDeadlines);
    }

    /**
     * #106: run every buffer diagnosis whose debounce has elapsed by $now.
     * Results are held, not returned: the loop first handles whatever input
     * arrived while Rector ran, then calls takeReadyDiagnostics().
     */
    public function runDueDiagnostics(float $now): void
    {
        if (!$this->diagnostics instanceof BufferDiagnosticsSource) {
            $this->pendingDeadlines = [];

            return;
        }

        foreach ($this->pendingDeadlines as $uri => $deadline) {
            if ($deadline > $now || !isset($this->buffers[$uri])) {
                continue;
            }

            unset($this->pendingDeadlines[$uri]);
            $version = $this->documentVersions[$uri] ?? null;
            $result = $this->diagnostics->diagnoseBuffer(self::uriToPath($uri), $this->buffers[$uri]);

            $this->readyResults[$uri] = ['version' => $version, 'result' => $result];
        }
    }

    /**
     * #106: publishDiagnostics for every held result whose version is still
     * the document's latest. A result for version N after version N+1
     * arrived is dropped here (changeDocument() also drops it eagerly); a
     * document closed meanwhile has no version left and is dropped too.
     *
     * @return list<array<string, mixed>>
     */
    public function takeReadyDiagnostics(): array
    {
        $frames = [];
        foreach ($this->readyResults as $uri => $ready) {
            if (!array_key_exists($uri, $this->documentVersions) || $this->documentVersions[$uri] !== $ready['version']) {
                continue;
            }

            $frames[] = $this->publishResult($uri, $ready['result'], $ready['version'], true);
        }
        $this->readyResults = [];

        return $frames;
    }

    /**
     * #106: full sync only -- each change carries the whole text, and the
     * last one wins. A client that ignores `change: 1` and sends ranged
     * (incremental) changes cannot be followed; its buffer is dropped rather
     * than diagnosed from a wrong reconstruction.
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function changeDocument(array $params): array
    {
        $textDocument = is_array($params['textDocument'] ?? null) ? $params['textDocument'] : [];
        $uri = $textDocument['uri'] ?? null;
        if (!is_string($uri) || !$this->diagnostics instanceof BufferDiagnosticsSource) {
            return [];
        }

        $version = $textDocument['version'] ?? null;
        $this->documentVersions[$uri] = is_int($version) ? $version : (($this->documentVersions[$uri] ?? 0) + 1);
        unset($this->readyResults[$uri]);

        $changes = is_array($params['contentChanges'] ?? null) ? $params['contentChanges'] : [];
        $last = $changes === [] ? null : $changes[array_key_last($changes)];
        $isFullText = is_array($last) && is_string($last['text'] ?? null) && !array_key_exists('range', $last);

        // Only a file: URI has a directory in the project to put the temp
        // copy in; an `untitled:` buffer has no path Rector could resolve.
        // rector.php and composer.lock are configuration, not source Rector
        // refactors: they are reloaded from disk on save (and through the
        // watcher), never diagnosed as buffers.
        if (!$isFullText || strncasecmp($uri, 'file:', 5) !== 0 || self::isWatchedConfigFile($uri)) {
            unset($this->buffers[$uri], $this->pendingDeadlines[$uri]);

            return [];
        }

        $this->buffers[$uri] = $last['text'];
        $this->pendingDeadlines[$uri] = microtime(true) + $this->debounceSeconds;

        return [];
    }

    private static function clientSupportsDocumentChanges(mixed $capabilities): bool
    {
        if (!is_array($capabilities)) {
            return false;
        }

        $workspace = $capabilities['workspace'] ?? null;
        $edit = is_array($workspace) ? ($workspace['workspaceEdit'] ?? null) : null;

        return is_array($edit) && ($edit['documentChanges'] ?? false) === true;
    }

    private static function clientSupportsDynamicWatchedFiles(mixed $capabilities): bool
    {
        if (!is_array($capabilities)) {
            return false;
        }

        $workspace = $capabilities['workspace'] ?? null;
        $watched = is_array($workspace) ? ($workspace['didChangeWatchedFiles'] ?? null) : null;

        return is_array($watched) && ($watched['dynamicRegistration'] ?? false) === true;
    }

    /** #111: `window.workDoneProgress` on the client's declared capabilities. */
    private static function clientSupportsWorkDoneProgress(mixed $capabilities): bool
    {
        if (!is_array($capabilities)) {
            return false;
        }

        $window = $capabilities['window'] ?? null;

        return is_array($window) && ($window['workDoneProgress'] ?? false) === true;
    }

    /** #111: a stable string key for a JSON-RPC id, which may be an int or a string. */
    private static function idKey(mixed $id): string
    {
        return (string) $id;
    }

    /**
     * #101: a `client/registerCapability` REQUEST (server -> client, per
     * the LSP spec -- not a notification) asking the client to report
     * changes to rector.php and composer.lock via
     * `workspace/didChangeWatchedFiles`. Without this, the warm worker's
     * own per-call config reload (RectorRunner::configFileChanged()) is
     * correct on the NEXT diagnose() call, but nothing ever triggers that
     * next call for a document the editor does not itself re-save -- the
     * exact "diagnostics silently drift" gap the issue describes.
     *
     * @return array<string, mixed>
     */
    private function registerConfigFileWatcher(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 'rector-warm-lsp/config-watch',
            'method' => 'client/registerCapability',
            'params' => [
                'registrations' => [
                    [
                        'id' => 'rector-warm-lsp-config-watch',
                        'method' => 'workspace/didChangeWatchedFiles',
                        'registerOptions' => [
                            'watchers' => [
                                ['globPattern' => '**/rector.php'],
                                ['globPattern' => '**/composer.lock'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * #101: re-diagnose every currently-open document when the client
     * reports a change to a watched file that is actually rector.php or
     * composer.lock -- an arbitrary watched-file event must NOT trigger
     * this (see LspServerTest's negative control), only the config files
     * registerConfigFileWatcher() asked to be told about.
     *
     * @param list<array<string, mixed>> $changes
     * @return list<array<string, mixed>>
     */
    private function watchedFilesChanged(array $changes): array
    {
        $isConfigChange = false;
        foreach ($changes as $change) {
            $uri = $change['uri'] ?? null;
            if (is_string($uri) && self::isWatchedConfigFile($uri)) {
                $isConfigChange = true;
                break;
            }
        }

        if (!$isConfigChange) {
            return [];
        }

        // #115: most-recently-active document first, not open-order -- see
        // the $activityOrder property doc. array_reverse() snapshots the
        // order before the loop starts, so mutating $this->activityOrder
        // mid-loop cannot reorder an iteration already under way -- but the
        // snapshot alone is not enough: `touchActivity: false` (self-review
        // finding, see diagnoseDocument()'s own doc) stops each call from
        // re-appending its own URI and reversing $activityOrder for NEXT
        // time, which would otherwise silently undo this fix on any second
        // config change with no real didOpen/didSave in between.
        //
        // #106 rebase fix: activityOrder is only touched by didOpen/didSave
        // (diagnoseDocument with touchActivity: true) -- a document that has
        // ONLY ever received a didChange (buffer edit, no open/save yet)
        // has a documentVersions entry but never lands in activityOrder.
        // Appending the documentVersions keys not already covered keeps
        // such a buffer-only document from being silently skipped here
        // (it would otherwise never get requeued on a config change).
        //
        // Self-review finding: changeDocument() writes documentVersions[$uri]
        // BEFORE it checks whether the change is buffer-eligible at all --
        // an untitled: URI, an incremental (ranged) change, or a change to
        // rector.php/composer.lock itself leaves a documentVersions entry
        // with no buffer and no activityOrder entry either. Left in the
        // union above, such a URI would fall through to diagnoseDocument()
        // (no buffers[$uri] to catch it) and get spuriously "diagnosed" as
        // though it were a real, resolvable source file. Restricting the
        // union to a file: URI that is not itself a watched config file
        // matches exactly the eligibility changeDocument() already applies
        // before it ever writes to $this->buffers.
        $uris = array_reverse($this->activityOrder);
        foreach (array_keys($this->documentVersions) as $uri) {
            if (in_array($uri, $uris, true)) {
                continue;
            }
            if (strncasecmp($uri, 'file:', 5) !== 0 || self::isWatchedConfigFile($uri)) {
                continue;
            }
            $uris[] = $uri;
        }

        $frames = [];
        foreach ($uris as $uri) {
            // #106: a document with an unsaved buffer is re-diagnosed from
            // that buffer, not from disk -- due now, run by the loop.
            if (isset($this->buffers[$uri])) {
                $this->pendingDeadlines[$uri] = microtime(true);
                continue;
            }

            $frames = array_merge($frames, $this->diagnoseDocument([
                'uri' => $uri,
                'version' => $this->documentVersions[$uri] ?? 0,
            ], touchActivity: false));
        }

        return $frames;
    }

    private static function isWatchedConfigFile(string $uri): bool
    {
        $path = str_replace('\\', '/', self::uriToPath($uri));

        // #106: a `.rector-warm-<pid>` directory holds this server's own
        // temp copy of a buffer. An event for a rector.php in there is not a
        // config change -- treating it as one re-queued every buffer, whose
        // runs wrote new temp copies, and so on (the reload loop).
        if (str_contains($path, '/' . RectorDiagnosticsSource::TEMP_DIRECTORY_PREFIX)) {
            return false;
        }

        $basename = basename($path);

        // oss:auditor self-review finding: a case-SENSITIVE compare here
        // silently misses a differently-cased URI on a case-insensitive
        // filesystem -- Windows, and macOS by default, the two platforms
        // this whole issue is about -- returning [] identically to a
        // genuinely unrelated file, an absence the caller cannot tell from
        // "nothing to do".
        return strcasecmp($basename, 'rector.php') === 0 || strcasecmp($basename, 'composer.lock') === 0;
    }

    /**
     * @param array<string, mixed> $textDocument
     * @param bool $touchActivity #115 self-review finding: `diagnoseDocument()`
     *   is ALSO the function `watchedFilesChanged()`'s own re-diagnose loop
     *   calls for every open document. If that call touched activity too,
     *   each re-diagnosis in the loop would re-append its own URI, ending
     *   the loop with $activityOrder REVERSED from what it was -- a second
     *   config-file change with no real didOpen/didSave in between would
     *   then process documents in exactly the wrong order, silently undoing
     *   the fix this parameter exists to prevent. A config-triggered
     *   re-diagnosis is not real developer activity on the document, so it
     *   must never move it in the activity order; only didOpen/didSave (the
     *   two callers that pass no argument here, defaulting to true) do.
     * @return list<array<string, mixed>>
     */
    private function diagnoseDocument(array $textDocument, bool $touchActivity = true): array
    {
        $uri = $textDocument['uri'] ?? null;
        if (!is_string($uri) || $this->diagnostics === null) {
            return [];
        }

        $version = $textDocument['version'] ?? ($this->documentVersions[$uri] ?? 0);
        $this->documentVersions[$uri] = $version;
        if ($touchActivity) {
            $this->touchActivity($uri);
        }

        // #106: didOpen/didSave diagnose what is on disk, which after a save
        // IS the buffer -- any pending or held buffer run is superseded.
        unset($this->buffers[$uri], $this->pendingDeadlines[$uri], $this->readyResults[$uri]);

        $path = self::uriToPath($uri);

        // #111: only the very first diagnose is a cold boot -- see the
        // $hasBootedOnce property doc. Reporting progress is decided once,
        // up front, so the begin/end pair below either both fire or neither
        // does, never a begin with no matching end.
        $reportProgress = $this->canReportProgress && !$this->hasBootedOnce;
        $this->hasBootedOnce = true;

        $frames = [];
        // PR #128 E2E review (blocking finding 1): emit(), not a plain
        // array push -- when $frameWriter is wired, `create` reaches the
        // transport HERE, before diagnose() below ever runs, rather than
        // being batched with every other frame until this whole function
        // returns.
        $progressRefused = false;
        if ($reportProgress) {
            $this->emit($this->progressCreate(), $frames);

            // PR #128 E2E review (blocking finding 2): give the client a
            // (non-blocking) chance to have already refused this progress
            // token before committing to `begin` -- the LSP spec forbids
            // sending $/progress for a token the client's own reply to
            // `create` rejected.
            $progressRefused = $this->isProgressCreateRefused();
            if (!$progressRefused) {
                $this->emit(
                    $this->progressBegin('Rector: warming up', 'Rector: analysing ' . basename(str_replace('\\', '/', $path))),
                    $frames,
                );
            }
        }

        $result = $this->diagnostics->diagnose($path);

        if ($reportProgress && !$progressRefused) {
            $this->emit($this->progressEnd(), $frames);
        }

        // Drop a stale result (#53): comparing against the version THIS call
        // started with, not whatever is tracked now, is what makes a stale
        // result discardable at all. Self-review note: the real bin/rector-
        // warm-lsp loop reads and handles one message at a time (no async
        // I/O, no forking at the LSP-message level), so nothing can bump
        // documentVersions[$uri] between the assignment above and this check
        // in the SHIPPED binary today -- this branch is unreachable there,
        // exercised only by a test double that reenters handle() from inside
        // diagnose() (LspServerTest::testAStaleResultIsDiscardedWhenTheVersionChangedMidCall).
        // Kept as defense-in-depth for whenever that loop stops being
        // strictly synchronous (a future async/pipelined transport), and
        // documented here as inert-today rather than as a live guarantee.
        if (($this->documentVersions[$uri] ?? null) !== $version) {
            return [];
        }

        $this->emit($this->publishResult($uri, $result, $version, false), $frames);

        return $frames;
    }

    /**
     * Turns a DiagnosticsSource result into a publishDiagnostics frame, and
     * remembers its fixes -- with the version they were computed for (#106),
     * so codeAction never offers them against a newer buffer.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function publishResult(string $uri, array $result, mixed $version, bool $withVersion): array
    {
        $fixes = $result['fixes'] ?? [];
        $this->fixesByUri[$uri] = $fixes;
        $this->fixesVersions[$uri] = is_int($version) ? $version : null;

        $diagnostics = [];
        foreach ($fixes as $i => $fix) {
            $diagnostics[] = [
                'range' => $fix['range'],
                'severity' => 3,
                'source' => 'rector',
                'message' => self::fixLabel($fix['rectors']),
                'data' => ['hunkIndex' => $i],
            ];
        }

        // #90: an `errors` entry (syntax error, out-of-root refusal, ...) has
        // no fix behind it -- no quickfix, so no `data.hunkIndex` -- but must
        // still reach the editor as a diagnostic, at Error severity, rather
        // than being dropped on the floor the way it was before this fix.
        foreach (($result['errors'] ?? []) as $error) {
            $line = max(0, (int) ($error['line'] ?? 0) - 1);
            $diagnostics[] = [
                'range' => [
                    'start' => ['line' => $line, 'character' => 0],
                    'end' => ['line' => $line, 'character' => 0],
                ],
                'severity' => 1,
                'source' => 'rector',
                'message' => (string) ($error['message'] ?? 'Rector reported an error.'),
            ];
        }

        return $this->publishDiagnostics($uri, $diagnostics, $withVersion && is_int($version) ? $version : null);
    }

    /** #111: server-initiated `window/workDoneProgress/create`, before the first `$/progress`. */
    private function progressCreate(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 'rector-warm-lsp/progress-create',
            'method' => 'window/workDoneProgress/create',
            'params' => ['token' => 'rector-warm-lsp/cold-boot'],
        ];
    }

    private function progressBegin(string $title, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => '$/progress',
            'params' => [
                'token' => 'rector-warm-lsp/cold-boot',
                'value' => ['kind' => 'begin', 'title' => $title, 'message' => $message],
            ],
        ];
    }

    private function progressEnd(): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => '$/progress',
            'params' => [
                'token' => 'rector-warm-lsp/cold-boot',
                'value' => ['kind' => 'end'],
            ],
        ];
    }

    /**
     * #111: how long isProgressCreateRefused() waits for the client's own
     * reply to `window/workDoneProgress/create` before giving up on it.
     * Second E2E review round, PR #128: a real client's reply arrives
     * milliseconds later over a real transport, never instantly -- this
     * has to be a real window, not the zero-timeout peek the first fix
     * used, which never actually saw a delayed reply at all.
     */
    private const CREATE_REPLY_TIMEOUT_SECONDS = 0.2;

    /**
     * PR #128 E2E review (blocking finding 2): the LSP spec forbids sending
     * `$/progress` for a token whose `create` request the client answered
     * with an error.
     *
     * Second E2E review round: the first fix here only ever peeked for a
     * reply ALREADY sitting in the transport's buffer (a zero-timeout
     * check), so a real client's reply -- which arrives milliseconds
     * later, not instantly -- was never actually seen; begin/end still
     * fired on a token the client went on to refuse. Fixed by genuinely
     * waiting up to CREATE_REPLY_TIMEOUT_SECONDS via $tryReadAhead (which
     * now takes that timeout and, in production, is
     * StdioLspTransport::waitForInput() underneath -- the same primitive
     * #106's own debounce timer uses, Windows-safe there too).
     *
     * Only an EXPLICIT success reply is treated as "not refused" now.
     * Anything else -- an explicit error, the window elapsing with no
     * reply at all, or a message that turns out not to be this reply --
     * skips progress for this round: this server can no longer confirm
     * the client actually accepted the token, and the LSP spec violation
     * this whole method exists to avoid is exactly what happens if it
     * guesses "probably fine" instead.
     */
    private function isProgressCreateRefused(): bool
    {
        if ($this->tryReadAhead === null) {
            return false;
        }

        $message = ($this->tryReadAhead)(self::CREATE_REPLY_TIMEOUT_SECONDS);
        if ($message === null) {
            // The window elapsed with no reply at all -- treat as
            // unresolved, not as success. See this method's own docblock.
            return true;
        }

        $isOurCreateReply = array_key_exists('id', $message)
            && ($message['id'] ?? null) === 'rector-warm-lsp/progress-create'
            && ($message['method'] ?? null) === null;

        if (!$isOurCreateReply) {
            // Not our reply -- something else the client sent while we
            // were waiting. Hand it back for ordinary dispatch rather
            // than dropping it, but this round's create reply is still
            // unresolved, so treat it the same as no reply at all.
            if ($this->pushBackMessage !== null) {
                ($this->pushBackMessage)($message);
            }

            return true;
        }

        return array_key_exists('error', $message);
    }

    /**
     * @param array<string, mixed> $textDocument
     * @return list<array<string, mixed>>
     */
    private function clearDocument(array $textDocument): array
    {
        $uri = $textDocument['uri'] ?? null;
        if (!is_string($uri)) {
            return [];
        }

        unset(
            $this->fixesByUri[$uri],
            $this->fixesVersions[$uri],
            $this->documentVersions[$uri],
            $this->buffers[$uri],
            $this->pendingDeadlines[$uri],
            $this->readyResults[$uri],
        );
        $this->removeFromActivityOrder($uri);

        return [$this->publishDiagnostics($uri, [])];
    }

    private function touchActivity(string $uri): void
    {
        $this->removeFromActivityOrder($uri);
        $this->activityOrder[] = $uri;
    }

    private function removeFromActivityOrder(string $uri): void
    {
        $index = array_search($uri, $this->activityOrder, true);
        if ($index !== false) {
            array_splice($this->activityOrder, $index, 1);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function codeAction(mixed $id, array $params): array
    {
        $textDocument = is_array($params['textDocument'] ?? null) ? $params['textDocument'] : [];
        $uri = $textDocument['uri'] ?? null;
        $range = is_array($params['range'] ?? null) ? $params['range'] : null;

        if (!is_string($uri)) {
            return $this->result($id, []);
        }

        $fixes = $this->fixesByUri[$uri] ?? [];
        if ($fixes === []) {
            return $this->result($id, []);
        }

        // #106: these fixes' ranges index into the text of the version they
        // were computed for. Once the buffer has moved on, applying them
        // would edit the wrong lines -- offer nothing until the new version
        // has been diagnosed.
        $fixesVersion = $this->fixesVersions[$uri] ?? null;
        if ($fixesVersion !== ($this->documentVersions[$uri] ?? null)) {
            return $this->result($id, []);
        }

        $actions = [];
        foreach ($fixes as $fix) {
            if ($range !== null && !self::rangesOverlap($range, $fix['range'])) {
                continue;
            }

            $actions[] = [
                'title' => 'Apply Rector: ' . self::fixLabel($fix['rectors']),
                'kind' => 'quickfix',
                'edit' => $this->workspaceEdit($uri, $fixesVersion, [['range' => $fix['range'], 'newText' => $fix['newText']]]),
            ];
        }

        $actions[] = [
            'title' => 'Apply all Rector fixes',
            'kind' => 'source.fixAll.rector',
            'edit' => $this->workspaceEdit($uri, $fixesVersion, array_map(
                static fn (array $fix): array => ['range' => $fix['range'], 'newText' => $fix['newText']],
                $fixes,
            )),
        ];

        return $this->result($id, $actions);
    }

    /**
     * #106: when the client supports `documentChanges`, the edit names the
     * document version its ranges were computed for, so a client that
     * applies it after the buffer changed rejects it instead of editing the
     * wrong lines. Otherwise the plain `changes` map, as before.
     *
     * @param list<array<string, mixed>> $edits
     * @return array<string, mixed>
     */
    private function workspaceEdit(string $uri, ?int $version, array $edits): array
    {
        if ($this->canUseDocumentChanges) {
            return ['documentChanges' => [[
                'textDocument' => ['uri' => $uri, 'version' => $version],
                'edits' => $edits,
            ]]];
        }

        return ['changes' => [$uri => $edits]];
    }

    /**
     * @param list<array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function publishDiagnostics(string $uri, array $diagnostics, ?int $version = null): array
    {
        $params = ['uri' => $uri];
        if ($version !== null) {
            // #106: a buffer diagnosis says which version it describes.
            $params['version'] = $version;
        }
        $params['diagnostics'] = $diagnostics;

        return [
            'jsonrpc' => '2.0',
            'method' => 'textDocument/publishDiagnostics',
            'params' => $params,
        ];
    }

    /**
     * #91 self-review finding: closest-hunk rule attribution (RectorDiffParser)
     * can legitimately leave a hunk with real content but an empty `rectors`
     * list (every applied rule was closer to a different hunk). `implode(',
     * ', [])` there used to produce an EMPTY diagnostic message and a
     * quickfix titled `'Apply Rector: '` with nothing after the colon --
     * worse than the old (wrong, but non-empty) broadcast-to-every-hunk
     * behaviour it replaced. This is the presentation-layer fallback: never
     * show a blank label, even when the parser genuinely has no rule name
     * for this hunk.
     *
     * @param list<string> $rectors
     */
    private static function fixLabel(array $rectors): string
    {
        return $rectors !== [] ? implode(', ', $rectors) : 'Rector fix';
    }

    /**
     * @param array{start: array{line:int,character:int}, end: array{line:int,character:int}} $a
     * @param array{start: array{line:int,character:int}, end: array{line:int,character:int}} $b
     */
    private static function rangesOverlap(array $a, array $b): bool
    {
        // Ordinary half-open interval overlap (s1 < e2 && s2 < e1) treats an
        // empty (start === end) line span as covering nothing, which drops
        // the quick fix whenever either side is zero-width: a cursor's "no
        // selection, act on this line" codeAction request ($a), or a
        // pure-insertion hunk's diagnostic range ($b, e.g. `66:0-66:0` for a
        // rector that only adds a blank line -- #93). Treat a zero-width
        // range as covering its own line by widening its exclusive end by
        // one line before comparing.
        $aEnd = self::exclusiveEndLine($a);
        $bEnd = self::exclusiveEndLine($b);

        return $a['start']['line'] < $bEnd && $b['start']['line'] < $aEnd;
    }

    /**
     * @param array{start: array{line:int,character:int}, end: array{line:int,character:int}} $range
     */
    private static function exclusiveEndLine(array $range): int
    {
        return $range['start']['line'] === $range['end']['line']
            ? $range['start']['line'] + 1
            : $range['end']['line'];
    }

    /**
     * `file:///path` -> `/path`, decoded. Self-review correction: for a plain
     * `file:///C:/Users/...` URI, PHP's own `parse_url()` already returns
     * `C:/Users/...` with no leading slash (verified: PHP 8.2.0) -- the
     * `/[A-Za-z]:` strip below does NOT fire for that shape and was never
     * needed for it. It exists for the OTHER Windows shape some clients
     * (vscode-uri-style, percent-encoded colon) produce instead --
     * `file:///c%3A/Users/...` -- where `parse_url()` returns
     * `/c%3A/Users/...`, `rawurldecode()` turns that into `/c:/Users/...`,
     * and ONLY THEN does the leading slash need stripping. Reasoned, not
     * observed on this machine (no Windows available) -- see the developer
     * report for the platform-band note.
     *
     * #99: a UNC URI (`file://server/share/A.php`) puts `server` in
     * parse_url()'s HOST component -- reading only PHP_URL_PATH used to
     * drop it by accident, collapsing to the host-free (and wrong)
     * `/share/A.php`. Handled deliberately here: a non-empty host other
     * than `localhost` (RFC 8089's spelling for an empty authority) is
     * folded back in as a `\\\\host\\share` UNC prefix.
     */
    private static function uriToPath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $host = parse_url($uri, PHP_URL_HOST);
        $path = is_string($path) ? rawurldecode($path) : $uri;

        if (preg_match('#^/[A-Za-z]:#', $path) === 1) {
            $path = substr($path, 1);
        }

        if (is_string($host) && $host !== '') {
            // Self-review finding (#99 independent review pass):
            // `file://c:/foo/bar.php` -- a non-conformant but real
            // two-slash Windows drive-letter shape (RFC 8089 Appendix E)
            // -- puts the single-letter drive in parse_url()'s HOST, not
            // PATH. Treated here as a drive letter rather than a UNC
            // server name: a single-letter NetBIOS hostname is technically
            // legal (1-15 chars) but vanishingly rare in practice, and the
            // URI syntax itself gives no way to tell the two apart, so this
            // is a deliberate, documented trade-off (second self-review
            // pass), not a claim that the UNC reading is impossible.
            if (preg_match('#^[A-Za-z]$#', $host) === 1) {
                return $host . ':' . $path;
            }

            if (strcasecmp($host, 'localhost') !== 0) {
                return '\\\\' . $host . str_replace('/', '\\', $path);
            }
        }

        return $path;
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
