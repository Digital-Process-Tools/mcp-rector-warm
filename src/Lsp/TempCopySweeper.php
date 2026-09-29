<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

/**
 * #106: removes `.rector-warm-<pid>` directories left behind by a server that
 * died mid-run (kill -9 skips RectorDiagnosticsSource's `finally`). A
 * directory is removed only when its pid is not this process and is known
 * not to be alive; a live pid is another server working in the same project,
 * and an unknown answer keeps the directory.
 */
final class TempCopySweeper
{
    /** Directories the startup walk never enters: large, and not ours. */
    private const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', '.git', '.hg', '.svn', '.idea'];

    /**
     * The startup sweep: walks $root at most $maxDepth levels deep, skipping
     * vendor/, node_modules/ and VCS directories, and stops after
     * $maxDirectories directories so a huge tree cannot stall startup.
     * Anything the walk misses is swept by sweepDirectory() the next time a
     * file next to it is diagnosed.
     *
     * @return list<string> the directories removed
     */
    public static function sweepTree(string $root, int $maxDepth = 8, int $maxDirectories = 20_000): array
    {
        $removed = [];
        $queue = [[rtrim($root, '/\\'), 0]];
        $visited = 0;

        while ($queue !== [] && $visited < $maxDirectories) {
            [$directory, $depth] = array_shift($queue);
            $visited++;

            $entries = @scandir($directory);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (!is_dir($path) || is_link($path)) {
                    continue;
                }

                $pid = self::pidOf($entry);
                if ($pid !== null) {
                    if (self::removeIfStale($path, $pid)) {
                        $removed[] = $path;
                    }
                    continue;
                }

                if ($depth + 1 < $maxDepth && !in_array($entry, self::SKIPPED_DIRECTORIES, true)) {
                    $queue[] = [$path, $depth + 1];
                }
            }
        }

        return $removed;
    }

    /**
     * The per-run sweep: only $directory's own `.rector-warm-<pid>` children.
     */
    public static function sweepDirectory(string $directory): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . RectorDiagnosticsSource::TEMP_DIRECTORY_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $path) {
            $pid = self::pidOf(basename($path));
            if ($pid !== null) {
                self::removeIfStale($path, $pid);
            }
        }
    }

    private static function pidOf(string $name): ?int
    {
        $prefix = RectorDiagnosticsSource::TEMP_DIRECTORY_PREFIX;
        if (!str_starts_with($name, $prefix)) {
            return null;
        }

        $digits = substr($name, strlen($prefix));

        return ctype_digit($digits) ? (int) $digits : null;
    }

    private static function removeIfStale(string $path, int $pid): bool
    {
        // #142: the shared sink both sweepTree() (which also checks this
        // itself, before ever reaching here) and sweepDirectory() (which
        // does not -- its glob(..., GLOB_ONLYDIR) follows a symlink) funnel
        // into. Refusing here closes both routes at once: a symlink can
        // point anywhere, and deleting through it means deleting outside
        // the workspace.
        if (is_link($path) || $pid === getmypid() || self::isAlive($pid) !== false) {
            return false;
        }

        // Only ever one level: the copy of one buffer, named like its
        // original. Anything unexpected inside (a subdirectory) is left.
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($path . DIRECTORY_SEPARATOR . $entry)) {
                @unlink($path . DIRECTORY_SEPARATOR . $entry);
            }
        }

        return @rmdir($path);
    }

    /**
     * true = alive, false = known dead, null = cannot tell (kept).
     */
    private static function isAlive(int $pid): ?bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            if (posix_kill($pid, 0)) {
                return true;
            }

            // EPERM: the process exists but belongs to someone else.
            return function_exists('posix_get_last_error') && posix_get_last_error() === 1 ? true : false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = @shell_exec(sprintf('tasklist /FI "PID eq %d" /NH /FO CSV 2>NUL', $pid));
            if (!is_string($output)) {
                return null;
            }

            return str_contains($output, sprintf('"%d"', $pid));
        }

        if (is_dir('/proc/self')) {
            return is_dir('/proc/' . $pid);
        }

        return null;
    }
}
