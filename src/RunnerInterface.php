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
     * Drop the warm container so the next run() boots a fresh one. Used to recover
     * from warm-state corruption (stale PHPStan scope/reflection across edits).
     */
    public function reboot(): void;
}
