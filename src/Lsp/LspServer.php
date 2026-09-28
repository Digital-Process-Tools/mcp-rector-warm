<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

/**
 * v1 (#53): the handshake from #52's prototype, plus diagnostics
 * (didOpen/didSave -> publishDiagnostics), didClose (clears them), and
 * textDocument/codeAction (a WorkspaceEdit per hunk, plus a whole-file
 * action), all built on RectorDiffParser + a DiagnosticsSource. Rector reads
 * from disk, so text sync stays `None`: an unsaved buffer's edits are never
 * seen (out of v1 scope, same as the issue's "unsaved buffers" exclusion).
 */
final class LspServer
{
    private bool $shuttingDown = false;

    /** @var array<string, int> URI -> the version this server last diagnosed */
    private array $documentVersions = [];

    /**
     * @var array<string, list<array{range: array{start: array{line:int,character:int}, end: array{line:int,character:int}}, newText: string, rectors: list<string>}>>
     *   URI -> the fixes behind its currently-published diagnostics, so
     *   codeAction can build a WorkspaceEdit without re-running Rector.
     */
    private array $fixesByUri = [];

    public function __construct(
        private readonly string $serverVersion,
        private readonly ?DiagnosticsSource $diagnostics = null,
    ) {
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
        $isRequest = array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        $params = $message['params'] ?? [];

        if ($method === 'initialize') {
            return [$this->result($id, [
                'capabilities' => [
                    // Disk-based: no textDocument/didChange handling, so
                    // `change` stays None (0) -- see the class docblock.
                    'textDocumentSync' => [
                        'openClose' => true,
                        'change' => 0,
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
            return [$this->registerConfigFileWatcher()];
        }

        if ($method === 'shutdown') {
            $this->shuttingDown = true;

            return [$this->result($id, null)];
        }

        if ($method === 'textDocument/didOpen') {
            return $this->diagnoseDocument(is_array($params) ? ($params['textDocument'] ?? []) : []);
        }

        if ($method === 'textDocument/didSave') {
            return $this->diagnoseDocument(is_array($params) ? ($params['textDocument'] ?? []) : []);
        }

        if ($method === 'textDocument/didClose') {
            return $this->clearDocument(is_array($params) ? ($params['textDocument'] ?? []) : []);
        }

        if ($method === 'textDocument/codeAction') {
            return [$this->codeAction($id, is_array($params) ? $params : [])];
        }

        if ($method === 'workspace/didChangeWatchedFiles') {
            return $this->watchedFilesChanged(is_array($params) ? ($params['changes'] ?? []) : []);
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

        $frames = [];
        foreach (array_keys($this->documentVersions) as $uri) {
            $frames = array_merge($frames, $this->diagnoseDocument([
                'uri' => $uri,
                'version' => $this->documentVersions[$uri],
            ]));
        }

        return $frames;
    }

    private static function isWatchedConfigFile(string $uri): bool
    {
        $path = self::uriToPath($uri);
        $basename = basename(str_replace('\\', '/', $path));

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
     * @return list<array<string, mixed>>
     */
    private function diagnoseDocument(array $textDocument): array
    {
        $uri = $textDocument['uri'] ?? null;
        if (!is_string($uri) || $this->diagnostics === null) {
            return [];
        }

        $version = $textDocument['version'] ?? ($this->documentVersions[$uri] ?? 0);
        $this->documentVersions[$uri] = $version;

        $path = self::uriToPath($uri);
        $result = $this->diagnostics->diagnose($path);

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

        $fixes = $result['fixes'] ?? [];
        $this->fixesByUri[$uri] = $fixes;

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

        return [$this->publishDiagnostics($uri, $diagnostics)];
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

        unset($this->fixesByUri[$uri], $this->documentVersions[$uri]);

        return [$this->publishDiagnostics($uri, [])];
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

        $actions = [];
        foreach ($fixes as $fix) {
            if ($range !== null && !self::rangesOverlap($range, $fix['range'])) {
                continue;
            }

            $actions[] = [
                'title' => 'Apply Rector: ' . self::fixLabel($fix['rectors']),
                'kind' => 'quickfix',
                'edit' => ['changes' => [$uri => [['range' => $fix['range'], 'newText' => $fix['newText']]]]],
            ];
        }

        $actions[] = [
            'title' => 'Apply all Rector fixes',
            'kind' => 'source.fixAll.rector',
            'edit' => ['changes' => [$uri => array_map(
                static fn (array $fix): array => ['range' => $fix['range'], 'newText' => $fix['newText']],
                $fixes,
            )]],
        ];

        return $this->result($id, $actions);
    }

    /**
     * @param list<array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function publishDiagnostics(string $uri, array $diagnostics): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => 'textDocument/publishDiagnostics',
            'params' => ['uri' => $uri, 'diagnostics' => $diagnostics],
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
