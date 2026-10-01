<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

use RuntimeException;

/**
 * The server's main loop, moved out of bin/rector-warm-lsp for #106 so it can
 * be tested. Still strictly synchronous -- one message at a time, no threads,
 * no forks -- with one addition: while a debounced buffer diagnosis is
 * pending, the read waits with a timeout (StdioLspTransport::waitForInput())
 * instead of blocking, and runs the diagnosis when the timeout expires.
 *
 * After a diagnosis run, every message that arrived WHILE Rector was running
 * is handled before the result is published. That is what makes "publish
 * only the latest version" real in a synchronous server: a didChange for
 * version N+1 that landed during version N's run bumps the document version
 * first, and version N's result is then dropped by
 * LspServer::takeReadyDiagnostics().
 */
final readonly class LspLoop
{
    public function __construct(
        private StdioLspTransport $transport,
        private LspServer $server,
    ) {}

    /**
     * @return int the process exit code: 0 after shutdown+exit, 1 otherwise
     */
    public function run(): int
    {
        while (true) {
            $deadline = $this->server->nextDiagnosticsDeadline();

            if ($deadline !== null && !$this->transport->waitForInput($deadline - microtime(true))) {
                $this->server->runDueDiagnostics(microtime(true));
                $exit = $this->drainPendingInput();
                $this->writeAll($this->server->takeReadyDiagnostics());

                if ($exit !== null) {
                    return $exit;
                }

                continue;
            }

            $exit = $this->handleNextMessage();
            if ($exit !== null) {
                return $exit;
            }
        }
    }

    private function drainPendingInput(): ?int
    {
        while ($this->transport->waitForInput(0.0)) {
            $exit = $this->handleNextMessage();
            if ($exit !== null) {
                return $exit;
            }
        }

        return null;
    }

    /**
     * @return int|null an exit code when the loop must stop, null to go on
     */
    private function handleNextMessage(): ?int
    {
        try {
            $message = $this->transport->read();
        } catch (RuntimeException $e) {
            fwrite(STDERR, "rector-warm-lsp: {$e->getMessage()}\n");

            return 1;
        }

        if ($message === null || ($message['method'] ?? null) === 'exit') {
            return $this->server->isShuttingDown() ? 0 : 1;
        }

        $this->writeAll($this->server->handle($message));

        return null;
    }

    /**
     * @param list<array<string, mixed>> $frames
     */
    private function writeAll(array $frames): void
    {
        foreach ($frames as $frame) {
            $this->transport->write($frame);
        }
    }
}
