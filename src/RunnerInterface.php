<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm;

/**
 * Contract for the warm Rector runner. Extracted so RectorTool can be driven by
 * a test double — in particular to exercise the reboot-and-retry recovery path
 * without booting a real Rector container.
 */
interface RunnerInterface
{
    /**
     * @param list<string> $argv Rector CLI args including the binary name as $argv[0].
     * @param bool $dryRun Must reflect the SAME call's actual dryRun flag (#72
     *   correction). The kill sites this drives (killAndReap()'s SIGKILL, the
     *   worker backstop, runCold()'s proc_terminate(..., 9)) send an
     *   unconditional signal, which can land mid-write on a file Rector is
     *   truncating-then-writing (file_put_contents-style) -- a dry-run call
     *   never writes, so it stays safe to kill at the deadline; a
     *   dryRun:false call must never be killed by --call-timeout at all, so
     *   the deadline is not enforced for it (it can hang indefinitely if
     *   genuinely wedged -- the pre-#58 status quo for a write call).
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    public function run(array $argv, bool $dryRun = true): array;

    public function isWarm(): bool;

    /**
     * The call deadline (in seconds) that run() enforces -- for a dryRun:true
     * (analysis-only) call only -- by killing the analysis process, or 0 when
     * no deadline is enforced at all (--call-timeout=0, unlimited). A
     * dryRun:false (write) call is never bound by this deadline: #72
     * corrected an earlier design that refused every dryRun:false call
     * outright whenever this was nonzero, in favour of simply never killing
     * a call that can write (see run()'s own $dryRun doc above).
     */
    public function getCallTimeoutSeconds(): int;

    /**
     * Drop the warm container so the next run() boots a fresh one. Used to recover
     * from warm-state corruption (stale PHPStan scope/reflection across edits).
     */
    public function reboot(): void;
}
