<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Support;

/**
 * #112: kill every process a timed-out call spawned, not only the one this code
 * spawned directly.
 *
 * The process a deadline kills is rarely the only one doing the work. Rector's
 * parallel mode (its own config.php turns it on by default; only --debug turns it
 * off) runs the analysis in worker processes that the process we kill started
 * through proc_open(), and a project's rector.php or bootstrap file can start
 * processes of its own. SIGKILL (or TerminateProcess() on Windows) on the direct
 * child leaves all of those running as orphans: CI's runner cleanup reported
 * "Terminate orphan process: ... (php)" on macOS and Windows, and on Windows the
 * orphan's working directory handle kept the call's temp dir from being removed.
 *
 * Best effort by design: when the process table cannot be read (no proc_open(),
 * no `ps`/`taskkill`), this does nothing and the caller's own kill of the direct
 * child still happens, which is exactly the behaviour before #112.
 */
final class ProcessTree
{
    /** Rounds of "list, then stop what is new" before giving up on a tree that keeps growing. */
    private const MAX_FREEZE_ROUNDS = 10;

    /**
     * SIGKILL $rootPid and every process below it (TerminateProcess() for the
     * whole tree on Windows). Never reaps: $rootPid is the caller's own child,
     * and the caller still waits on it (pcntl_waitpid(), proc_close()) exactly as
     * it did before #112. The descendants are not the caller's children; once
     * their parents die they are reparented and reaped by init/launchd.
     */
    public static function killTree(int $rootPid): void
    {
        if ($rootPid <= 0) {
            return;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            // /T walks the tree by parent pid, so it must run while the root is
            // still alive -- before the caller's proc_terminate(), never after.
            self::run(['taskkill', '/T', '/F', '/PID', (string) $rootPid]);

            return;
        }

        // Freeze first, then kill: a process that is still running can spawn a
        // new child between the moment the table is read and the moment it is
        // killed, and that child would escape. A stopped process spawns nothing,
        // so re-reading the table until no new descendant appears converges.
        self::signal([$rootPid], 'STOP');
        $frozen = [];
        for ($round = 0; $round < self::MAX_FREEZE_ROUNDS; $round++) {
            $new = \array_values(\array_diff(self::descendantsOf($rootPid), $frozen));
            if ($new === []) {
                break;
            }
            self::signal($new, 'STOP');
            $frozen = \array_merge($frozen, $new);
        }
        self::signal(\array_merge($frozen, [$rootPid]), 'KILL');
    }

    /**
     * Every pid below $rootPid in the current process table (children,
     * grandchildren, ...), never $rootPid itself.
     *
     * @return list<int>
     */
    public static function descendantsOf(int $rootPid): array
    {
        $table = self::run(['ps', '-A', '-o', 'pid=', '-o', 'ppid=']);
        if ($table === null) {
            return [];
        }
        $childrenOf = [];
        foreach (\preg_split('/\R/', $table) ?: [] as $line) {
            if (\preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $line, $m) === 1) {
                $childrenOf[(int) $m[2]][] = (int) $m[1];
            }
        }
        $descendants = [];
        $frontier = [$rootPid];
        while ($frontier !== []) {
            $current = \array_pop($frontier);
            foreach ($childrenOf[$current] ?? [] as $child) {
                if ($child === $rootPid || isset($descendants[$child])) {
                    continue;
                }
                $descendants[$child] = true;
                $frontier[] = $child;
            }
        }

        return \array_keys($descendants);
    }

    /**
     * @param list<int> $pids
     * @param 'STOP'|'KILL' $name
     */
    private static function signal(array $pids, string $name): void
    {
        if ($pids === []) {
            return;
        }
        // SIGSTOP's number differs across POSIX systems (19 on Linux, 17 on
        // macOS), so only its named constant (pcntl) is trusted; SIGKILL is 9
        // everywhere. Without either, fall back to kill(1), which takes names.
        $number = \defined('SIG' . $name) ? (int) \constant('SIG' . $name) : ($name === 'KILL' ? 9 : null);
        if ($number !== null && \function_exists('posix_kill')) {
            foreach ($pids as $pid) {
                @\posix_kill($pid, $number);
            }

            return;
        }
        self::run(\array_merge(['kill', '-' . $name], \array_map('strval', $pids)));
    }

    /**
     * Run $command without a shell and return its stdout, or null when it could
     * not be run at all.
     *
     * @param list<string> $command
     */
    private static function run(array $command): ?string
    {
        if (!\function_exists('proc_open')) {
            return null;
        }
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @\proc_open($command, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (!\is_resource($process)) {
            return null;
        }
        $out = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $exit = \proc_close($process);

        return $out === false || ($exit === 127 && $out === '') ? null : $out;
    }
}
