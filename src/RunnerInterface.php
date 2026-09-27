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
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    public function run(array $argv): array;

    public function isWarm(): bool;

    /**
     * The call deadline (in seconds) that run() enforces by killing the analysis
     * process, or 0 when no deadline is enforced (--call-timeout=0, unlimited).
     * RectorTool uses this to refuse a non-dry-run call while a deadline is
     * active (#72): the kill sites in RectorRunner send SIGKILL / signal 9
     * unconditionally, which can land mid-write on a target file Rector is
     * currently rewriting via file_put_contents-style truncate-then-write,
     * leaving that file truncated with no copy of its original content
     * anywhere. A dry-run call never writes, so it stays safe to kill.
     */
    public function getCallTimeoutSeconds(): int;

    /**
     * Drop the warm container so the next run() boots a fresh one. Used to recover
     * from warm-state corruption (stale PHPStan scope/reflection across edits).
     */
    public function reboot(): void;
}
