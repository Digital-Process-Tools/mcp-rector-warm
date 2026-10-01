<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Support;

/**
 * Isolates the real MCP/LSP protocol stdout from a Rector rule or project
 * file that writes to STDOUT/STDERR/php://stdout directly during analysis
 * (#194).
 *
 * The forked analysing child (RectorRunner::forkAndExecute()'s grandchild,
 * and the #185 session child) must keep the STDIN/STDOUT/STDERR *constants*
 * open and valid -- closing them broke PHPStan's own stub reflection (#192).
 * silenceChildOutput() buffers everything PHP's own output layer would write,
 * but a raw fwrite(STDOUT, ...) or file_put_contents('php://stdout', ...)
 * bypasses that layer and lands straight on the real OS file descriptor --
 * which, without this class, IS the daemon's real protocol pipe, because
 * pcntl_fork() duplicates the whole process image including its fds.
 *
 * PHP has no dup2(): a descriptor cannot be moved without closing it, and the
 * constants must stay open. So this does it at the process level instead,
 * once, before the daemon ever forks anything: re-exec the whole script as a
 * child process whose fd 1 is the null device from the moment it starts, with
 * the real protocol stream handed to it as fd 3. Every later pcntl_fork()
 * grandchild then inherits a harmless fd 1 -- a stray direct write lands on
 * the null device, not on the client's stream -- while the STDIN/STDOUT/
 * STDERR constants stay open and valid for PHPStan exactly as before.
 *
 * Gated to platforms where this can actually help (POSIX, with pcntl): on
 * Windows there is no pcntl extension at all, so RectorRunner::canFork()
 * (function_exists('pcntl_fork')) is always false and the daemon never forks
 * a grandchild in the first place -- the bug this class exists for cannot
 * occur there, and php://fd/N plus /dev/null are not POSIX-portable concepts
 * to begin with.
 *
 * Every failure path here falls back to running UNISOLATED rather than
 * refusing to start: bin/mcp-rector-warm's own history records a prior
 * attempt at fd surgery (closing and reopening fd 1 in place) that made a
 * real container's boot die silently, exit 255, nothing on stdout or stderr.
 * A daemon that starts unisolated (status quo, the bug this class fixes) is
 * strictly better than one that refuses to start at all (CLAUDE.md rule 1:
 * never, on any path, a crash or a hang).
 */
final class ProtocolStdoutIsolator
{
    /** Set (to any truthy value) in the re-exec'd child's own environment. */
    public const ENV_ISOLATED = 'MCP_RECTOR_WARM_FD_ISOLATED';

    /** Escape hatch: set to skip re-exec entirely and keep the old behaviour. */
    public const ENV_DISABLE = 'MCP_RECTOR_WARM_NO_FD_ISOLATION';

    /**
     * Whether this process should attempt the re-exec. Pure (no I/O beyond
     * reading its own environment/platform), so unit-testable directly
     * against injected values rather than only through the ambient process.
     */
    public static function shouldIsolate(
        bool $isolatedAlready,
        bool $disabled,
        bool $isPosix,
        bool $hasPcntl,
        bool $hasProcOpen,
        bool $hasPosixKill,
    ): bool {
        if ($isolatedAlready || $disabled) {
            return false;
        }

        return $isPosix && $hasPcntl && $hasProcOpen && $hasPosixKill;
    }

    /** Convenience wrapper over shouldIsolate() reading the real environment. */
    public static function shouldIsolateHere(): bool
    {
        return self::shouldIsolate(
            isolatedAlready: self::truthyEnv(self::ENV_ISOLATED),
            disabled: self::truthyEnv(self::ENV_DISABLE),
            isPosix: \DIRECTORY_SEPARATOR === '/',
            hasPcntl: \function_exists('pcntl_fork')
                && \function_exists('pcntl_waitpid')
                && \function_exists('pcntl_signal')
                && \function_exists('pcntl_signal_dispatch')
                && \function_exists('pcntl_async_signals'),
            hasProcOpen: \function_exists('proc_open'),
            hasPosixKill: \function_exists('posix_kill'),
        );
    }

    public static function isAlreadyIsolatedChild(): bool
    {
        return self::truthyEnv(self::ENV_ISOLATED);
    }

    private static function truthyEnv(string $name): bool
    {
        $value = \getenv($name);
        if ($value === false) {
            return false;
        }

        return !\in_array(\strtolower(\trim($value)), ['', '0', 'off', 'false', 'no'], true);
    }

    /**
     * Re-exec $scriptPath as a child process with fd 1 on the null device and
     * the real protocol stdout handed to it as fd 3, then block until it
     * exits, relaying SIGTERM/SIGINT/SIGHUP to it, and exit with its code.
     *
     * Never returns on success (it calls exit()). Returns false if isolation
     * could not even be launched, so the caller falls through to running
     * unisolated -- see the class docblock on why that is the required
     * fallback, never a thrown error.
     */
    public static function reexecIsolated(string $scriptPath, array $argv): bool
    {
        if (!\is_resource(\STDOUT) || !\is_resource(\STDIN) || !\is_resource(\STDERR)) {
            return false;
        }

        // Reuses RectorRunner's own proven -d-flag reconstruction (#125/#156)
        // rather than a naive local_value-vs-global_value diff: the CLI SAPI
        // folds a real -d flag into BOTH values from process start, so that
        // naive comparison silently drops every override the daemon was
        // actually started with (confirmed empirically, see that method's
        // own docblock) -- exactly the ini settings a re-exec here must not
        // lose (e.g. the E2E suite's own disable_functions/
        // default_socket_timeout fixtures).
        $iniFlags = \Dpt\McpRectorWarm\RectorRunner::collectIniOverrideArgs();
        $command = \array_merge([\PHP_BINARY], $iniFlags, [$scriptPath], \array_slice($argv, 1));

        $descriptorspec = [
            0 => \STDIN,
            1 => ['file', self::nullDevice(), 'w'],
            2 => \STDERR,
            3 => \STDOUT,
        ];

        $env = [];
        foreach ($_SERVER as $key => $value) {
            if (\is_string($value)) {
                $env[$key] = $value;
            }
        }
        foreach ($_ENV as $key => $value) {
            if (\is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env[self::ENV_ISOLATED] = '1';

        $pipes = [];
        $process = @\proc_open($command, $descriptorspec, $pipes, null, $env);
        if (!\is_resource($process)) {
            // fd 2 (STDERR) is handed through to this process unmodified at
            // this point -- an operator watching the daemon's real stderr
            // must be able to tell "isolation never engaged" apart from
            // "isolation tried and silently fell back", or a mysteriously
            // silent daemon has nothing to go on.
            @\fwrite(\STDERR, "mcp-rector-warm: #194 fd isolation could not start "
                . "(proc_open() failed); continuing unisolated\n");

            return false;
        }

        $status = \proc_get_status($process);
        $childPid = $status['pid'];

        // Relay termination to the child: an MCP/LSP host that kills THIS
        // (the re-exec wrapper's) pid specifically must not leave the real
        // daemon orphaned -- a leaked process is exactly the class of bug
        // CLAUDE.md rule 1 ranks above the stdout corruption this fixes.
        \pcntl_async_signals(true);
        $relay = static function (int $signo) use ($childPid): void {
            @\posix_kill($childPid, $signo);
        };
        foreach ([\SIGTERM, \SIGINT, \SIGHUP] as $signal) {
            \pcntl_signal($signal, $relay);
        }

        $exitStatus = null;
        while (true) {
            \pcntl_signal_dispatch();
            $waited = \pcntl_waitpid($childPid, $exitStatus, \WNOHANG);
            if ($waited === $childPid) {
                break;
            }
            if ($waited === -1) {
                // waitpid() itself failed (e.g. ECHILD) -- $exitStatus was
                // never filled in. Fall through to proc_close()'s own wait
                // below rather than treating this the same as a clean exit:
                // its return IS the real termination status PHP's own
                // proc_open bookkeeping observed, independent of our own
                // polling loop having missed it.
                break;
            }
            \usleep(20_000);
        }
        $procCloseStatus = \proc_close($process);

        if (\is_int($exitStatus) && \pcntl_wifexited($exitStatus)) {
            $exitCode = \pcntl_wexitstatus($exitStatus);
        } elseif ($procCloseStatus >= 0) {
            $exitCode = $procCloseStatus;
        } else {
            $exitCode = 1;
        }
        exit($exitCode);
    }

    /**
     * The stream this process should write protocol bytes to: fd 3 if this IS
     * the re-exec'd isolated child, otherwise the real STDOUT unchanged.
     */
    public static function protocolStream()
    {
        if (self::isAlreadyIsolatedChild()) {
            $fd3 = @\fopen('php://fd/3', 'wb');
            if (\is_resource($fd3)) {
                return $fd3;
            }
            // fd 3 should always be open here (we put it there ourselves),
            // but if something stripped it, STDOUT is now the null device we
            // redirected it to -- the daemon goes silent rather than corrupt
            // or crash, and whatever supervises it sees a dead protocol and
            // restarts it, same as any other wedged daemon. Logged to the
            // real STDERR (handed through to this process unmodified) so an
            // operator can tell this apart from isolation never having run.
            @\fwrite(\STDERR, "mcp-rector-warm: #194 fd isolation's own fd 3 "
                . "is gone; the protocol stream is now silent (fd 1 is the "
                . "null device)\n");
        }

        return \STDOUT;
    }

    private static function nullDevice(): string
    {
        return \DIRECTORY_SEPARATOR === '/' ? '/dev/null' : 'NUL';
    }
}
