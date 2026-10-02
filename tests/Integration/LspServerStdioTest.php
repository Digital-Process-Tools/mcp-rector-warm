<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * #245: bin/rector-warm-lsp had 0% CI-measured line coverage -- nothing in
 * the suite spawned it as its own subprocess, so pcov's instrumentation
 * never saw it run (the coverage job's auto_prepend_file/MCP_RECTOR_WARM_
 * COVERAGE_DIR mechanism is purely environmental: it instruments every
 * php invocation that inherits the parent process's PHP_INI_SCAN_DIR, see
 * tests/coverage/prepend.php, and this test's proc_open() calls do that for
 * free, exactly like ServerStdioTest already does for bin/mcp-rector-warm).
 *
 * Drives a minimal real LSP session over stdio -- initialize, didOpen,
 * codeAction, shutdown, exit -- against the existing tests/Fixtures/
 * lsp-project fixture, mirroring ServerStdioTest's proc_open()/
 * PHP_BINARY-prepend pattern (see invoke() there for the #97 Windows
 * rationale), but framed as LSP's Content-Length headers rather than MCP's
 * newline-delimited JSON (see src/Lsp/StdioLspTransport::read()/write()).
 */
final class LspServerStdioTest extends TestCase
{
    private static string $bin;
    private static string $fixtureDir;
    private static string $fixableFile;
    private static string $cleanFile;

    public static function setUpBeforeClass(): void
    {
        self::$bin = dirname(__DIR__, 2) . '/bin/rector-warm-lsp';
        self::$fixtureDir = realpath(dirname(__DIR__) . '/Fixtures/lsp-project') ?: '';
        self::$fixableFile = self::$fixtureDir . '/src/Fixable.php';
        self::$cleanFile = self::$fixtureDir . '/src/Clean.php';

        if (!is_file(self::$bin)) {
            self::markTestSkipped('bin/rector-warm-lsp missing');
        }
        if (!is_file(self::$fixableFile) || !is_file(self::$cleanFile)) {
            self::markTestSkipped('tests/Fixtures/lsp-project fixture missing');
        }
    }

    public function testInitializeDidOpenCodeActionShutdownExitOffersAFixOnAFixableFile(): void
    {
        $actions = $this->runSession(self::$fixableFile);

        self::assertNotEmpty($actions, 'expected at least one code action for a fixable file');
        self::assertContains('Apply all Rector fixes', array_column($actions, 'title'));
    }

    /**
     * Self-review finding: this negative assertion has no positive control
     * of its own -- nothing here proves the harness can detect a codeAction
     * call that WRONGLY returns []. The positive control is the sibling
     * test above, through the identical runSession()/readFrame() plumbing
     * against the paired Fixable.php fixture: if that mechanism silently
     * degraded to always answering [], testInitializeDidOpen...OffersAFix
     * fails loudly (assertNotEmpty) before this test's assertSame([], ...)
     * could ever pass for the wrong reason.
     */
    public function testCodeActionIsEmptyForACleanFile(): void
    {
        self::assertSame([], $this->runSession(self::$cleanFile), 'a clean file should offer no quick fixes');
    }

    /**
     * Runs one full initialize/didOpen/codeAction/shutdown/exit session
     * against a fresh bin/rector-warm-lsp subprocess for $file, and returns
     * the codeAction response's `result` (the list of offered actions).
     *
     * @return list<array<string, mixed>>
     */
    private function runSession(string $file): array
    {
        $uri = self::pathToFileUri($file);
        $cmd = [
            PHP_BINARY,
            self::$bin,
            '--working-dir=' . self::$fixtureDir,
            '--config=' . self::$fixtureDir . '/rector.php',
        ];

        $stderr = sys_get_temp_dir() . '/rector_warm_lsp_stderr_' . bin2hex(random_bytes(6));
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        $stdin = $pipes[0];
        $stdout = $pipes[1];
        stream_set_timeout($stdout, 120);

        try {
            $this->writeFrame($stdin, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'processId' => null,
                    'rootUri' => self::pathToFileUri(self::$fixtureDir),
                    'capabilities' => new \stdClass(),
                ],
            ]);
            $init = $this->readFrame($stdout, 1, $stderr);
            self::assertArrayHasKey('result', $init, 'initialize failed: ' . $this->stderrTail($stderr));
            self::assertSame('rector-warm-lsp', $init['result']['serverInfo']['name']);

            $this->writeFrame($stdin, [
                'jsonrpc' => '2.0',
                'method' => 'textDocument/didOpen',
                'params' => [
                    'textDocument' => [
                        'uri' => $uri,
                        'languageId' => 'php',
                        'version' => 1,
                        'text' => (string) file_get_contents($file),
                    ],
                ],
            ]);

            $this->writeFrame($stdin, [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'textDocument/codeAction',
                'params' => [
                    'textDocument' => ['uri' => $uri],
                    // Wide enough to overlap any hunk Rector could report
                    // on either fixture file -- see codeAction()'s own
                    // rangesOverlap() filtering.
                    'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 999, 'character' => 0]],
                    'context' => ['diagnostics' => []],
                ],
            ]);
            // didOpen's own publishDiagnostics notification (no `id`) may
            // arrive before the codeAction response -- readFrame() skips
            // any frame whose id does not match, same as
            // ServerStdioTest::readResponse() does for newline-JSON.
            $codeAction = $this->readFrame($stdout, 2, $stderr);
            self::assertArrayHasKey('result', $codeAction, 'codeAction failed: ' . $this->stderrTail($stderr));

            $this->writeFrame($stdin, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'shutdown']);
            $shutdown = $this->readFrame($stdout, 3, $stderr);
            self::assertArrayHasKey('result', $shutdown, 'shutdown failed: ' . $this->stderrTail($stderr));

            // Notification -- no response, no id to wait for.
            $this->writeFrame($stdin, ['jsonrpc' => '2.0', 'method' => 'exit']);

            /** @var list<array<string, mixed>> $actions */
            $actions = $codeAction['result'];

            return $actions;
        } finally {
            fclose($stdin);
            fclose($stdout);
            $exitCode = proc_close($proc);
            self::assertSame(
                0,
                $exitCode,
                'rector-warm-lsp should exit 0 after shutdown+exit: ' . $this->stderrTail($stderr),
            );
            @unlink($stderr);
        }
    }

    /**
     * @param resource              $stdin
     * @param array<string, mixed>  $message
     */
    private function writeFrame($stdin, array $message): void
    {
        $body = json_encode($message, JSON_UNESCAPED_SLASHES);
        self::assertNotFalse($body);
        fwrite($stdin, 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);
        fflush($stdin);
    }

    /**
     * Blocks reading Content-Length-framed JSON-RPC until the response (or
     * notification -- skipped) matching $expectedId arrives, same framing
     * src/Lsp/StdioLspTransport::read() produces on the server side.
     *
     * @param resource $stdout
     * @return array<string, mixed>
     */
    private function readFrame($stdout, int $expectedId, string $stderrPath): array
    {
        while (true) {
            $header = '';
            while (($line = fgets($stdout)) !== false) {
                if (trim($line) === '') {
                    break;
                }
                $header .= $line;
            }
            if ($line === false) {
                self::fail("no response for id={$expectedId}: stream closed. " . $this->stderrTail($stderrPath));
            }
            if (preg_match('/Content-Length:\s*(\d+)/i', $header, $m) !== 1) {
                self::fail("malformed frame header '{$header}'. " . $this->stderrTail($stderrPath));
            }

            $length = (int) $m[1];
            $body = '';
            while (strlen($body) < $length && $length - strlen($body) > 0) {
                $chunk = fread($stdout, $length - strlen($body));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
            }

            $decoded = json_decode($body, true);
            if (is_array($decoded) && ($decoded['id'] ?? null) === $expectedId) {
                return $decoded;
            }
        }
    }

    private function stderrTail(string $path): string
    {
        $contents = @file_get_contents($path);

        return ($contents === false || $contents === '') ? '' : ' | server stderr: ' . substr($contents, -1500);
    }

    /**
     * file:// URI for an absolute path. Reasoned, not observed on Windows
     * (no Windows available here) -- mirrors LspServer::uriToPath()'s own
     * documented handling of the `/[A-Za-z]:` drive-letter shape in
     * reverse, so a URI this test builds round-trips through the
     * production code that will decode it.
     */
    private static function pathToFileUri(string $path): string
    {
        $normalised = str_replace('\\', '/', $path);

        return preg_match('#^[A-Za-z]:#', $normalised) === 1
            ? 'file:///' . $normalised
            : 'file://' . $normalised;
    }
}
