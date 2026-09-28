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
            return [];
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
        if ($a['start']['line'] === $a['end']['line']) {
            // $a is a zero-width (cursor) request range -- a real editor's
            // "no selection, act on this line" codeAction request. Ordinary
            // half-open interval overlap (s1 < e2 && s2 < e1) treats an empty
            // interval as never overlapping anything, which silently drops
            // the quick fix whenever the cursor sits exactly on the fix's
            // FIRST line (the most common place to invoke it from). Test
            // point-in-half-open-range instead: [b.start, b.end).
            return $a['start']['line'] >= $b['start']['line'] && $a['start']['line'] < $b['end']['line'];
        }

        return $a['start']['line'] < $b['end']['line'] && $b['start']['line'] < $a['end']['line'];
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
     */
    private static function uriToPath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : $uri;

        if (preg_match('#^/[A-Za-z]:#', $path) === 1) {
            $path = substr($path, 1);
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
