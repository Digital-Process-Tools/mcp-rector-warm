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
     *
     * $excludePids (#134): pids to leave alone even if the process table shows
     * them as $rootPid's own descendants. This exists for a caller that IS
     * itself one of $rootPid's children (bin/rector-warm-orphan-watchdog.php,
     * spawned by the very worker process it watches) -- without an exclusion,
     * the freeze loop below would enumerate that caller as a descendant of its
     * own target and SIGSTOP it mid-call, before it ever reaches the final
     * SIGKILL, deadlocking the very kill it is in the middle of performing
     * (reproduced empirically: the freeze loop's own debug trace stopped dead
     * between finding the watchdog's pid as a "new" descendant and the next
     * round, with no further progress ever logged).
     *
     * @param list<int> $excludePids
     */
    public static function killTree(int $rootPid, array $excludePids = []): void
    {
        if ($rootPid <= 0) {
            return;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            // /T walks the tree by parent pid, so it must run while the root is
            // still alive -- before the caller's proc_terminate(), never after.
            // $excludePids is NOT honoured here: taskkill /T takes no per-pid
            // exclusion, so a caller that IS itself a descendant of $rootPid (the
            // watchdog is) gets killed along with the rest of the tree on this
            // branch. Unlike the POSIX path's STOP-then-enumerate freeze loop,
            // this is not a deadlock risk (taskkill does not suspend anything
            // mid-scan), only an abrupt exit of a process that was about to call
            // exit(0) on its own right after this -- harmless in that caller's
            // one current use, but a real gap if a future caller relies on
            // surviving its own tree-kill on Windows.
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
            $new = \array_values(\array_diff(self::descendantsOf($rootPid), $frozen, $excludePids));
            if ($new === []) {
                break;
            }
            self::signal($new, 'STOP');
            $frozen = \array_merge($frozen, $new);
        }
        self::signal(\array_values(\array_diff(\array_merge($frozen, [$rootPid]), $excludePids)), 'KILL');
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
     * #159: whether the most recent probe run() attempted (from isAlive() or
     * parentOf(), this process only) actually managed to execute an external
     * command at all, distinguishing "the environment cannot run our probe
     * commands (`ps`/`tasklist`)" from "the probe ran fine and told us the
     * pid/parent is simply gone" -- the two reasons isAlive()/parentOf()
     * themselves collapse into a single null (see their own docblocks). Null
     * until at least one probe has been attempted in this process; a caller
     * treating that startup null the same as "cannot tell" is the same
     * best-effort posture ProcessTree already asks for everywhere else.
     */
    private static ?bool $lastProbeRanOk = null;

    public static function lastProbeRanOk(): ?bool
    {
        return self::$lastProbeRanOk;
    }

    /**
     * Whether $pid is a live process: true, false, or null when that cannot be told
     * (no posix, no proc_open, no tasklist/ps). A caller deciding to give up on a
     * peer must treat null as "alive" -- this is a best-effort probe, never proof.
     */
    public static function isAlive(int $pid): ?bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $out = self::run(['tasklist', '/FI', 'PID eq ' . $pid, '/NH', '/FO', 'CSV']);

            return $out === null ? null : \str_contains($out, '"' . $pid . '"');
        }
        if (\function_exists('posix_kill')) {
            // Signal 0 probes without sending; EPERM (1) still means it exists.
            return @\posix_kill($pid, 0) || \posix_get_last_error() === 1;
        }
        $out = self::run(['ps', '-o', 'pid=', '-p', (string) $pid]);

        return $out === null ? null : \trim($out) !== '';
    }

    /**
     * $pid's own parent pid right now, read from the process table (POSIX only --
     * Windows has no equivalent reparenting-to-init concept to read), or null when
     * that cannot be told (no proc_open()/`ps`, or $pid itself no longer exists).
     *
     * Unlike isAlive($otherPid) probed from a THIRD process, this is not fooled by
     * a zombie: reparenting to init/launchd happens the instant a process's real
     * parent dies -- before anyone reaps it -- so a caller watching $pid from
     * outside (neither $pid's parent nor grandparent) can still notice its parent
     * dying immediately, the same guarantee posix_getppid() gives a process
     * checking its OWN parent (see RectorRunner::serveProcessWorker()'s and
     * forkAndExecute()'s own #127 comments for the zombie caveat this sidesteps).
     *
     * #159: the null return covers TWO different reasons -- $pid is simply
     * gone, or `ps` itself could not be run at all -- and does not tell them
     * apart; a caller that needs to (bin/rector-warm-orphan-watchdog.php does,
     * to make the second one observable) reads lastProbeRanOk() right after.
     */
    public static function parentOf(int $pid): ?int
    {
        if (\PHP_OS_FAMILY === 'Windows' || $pid <= 0) {
            return null;
        }
        $out = self::run(['ps', '-o', 'ppid=', '-p', (string) $pid]);
        if ($out === null) {
            return null;
        }
        $trimmed = \trim($out);

        return $trimmed === '' ? null : (int) $trimmed;
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
            self::$lastProbeRanOk = false;

            return null;
        }
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @\proc_open($command, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (!\is_resource($process)) {
            self::$lastProbeRanOk = false;

            return null;
        }
        $out = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $exit = \proc_close($process);

        $couldNotRun = $out === false || ($exit === 127 && $out === '');
        self::$lastProbeRanOk = !$couldNotRun;

        return $couldNotRun ? null : $out;
    }
}
