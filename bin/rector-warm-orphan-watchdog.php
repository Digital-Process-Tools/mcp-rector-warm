<?php

declare(strict_types=1);

// #134: the no-pcntl standby worker (RectorRunner::serveProcessWorker(), spawned by
// bin/rector-warm-worker.php) calls the real analysis (execute()) synchronously, in
// process -- there is no fork, no tick point inside Rector/PHPStan's own compiled
// code, and no pcntl alarm on this path (that is the whole reason it exists) to
// interrupt it mid-call. So nothing notices the daemon dying while a call is in
// flight the way forkAndExecute()'s in-call orphan poll does on the pcntl path
// (#127) -- the worker's own idle-wait poll (also #127/#108) only runs BEFORE a
// call arrives, never during one.
//
// This script is that poll, moved OUTSIDE the process actually running the call:
// serveProcessWorker() spawns it (proc_open -- the one primitive this whole no-pcntl
// path already depends on) right before calling execute(), and it does nothing but
// watch. If the daemon it was told to watch dies, it kills the worker's whole
// process tree so an analysis nobody is waiting on any more does not keep running
// until its own --call-timeout or forever. It exits quietly, doing nothing, once
// the worker itself is gone for any other reason (the call finished normally) --
// the worker also tries to stop this process once execute() returns; this is only
// the other direction, in case that cleanup itself never runs (e.g. the worker is
// killed before it gets there).
//
// argv: <daemonPid> <workerPid> <pollIntervalSeconds>
foreach ([
    __DIR__ . '/../vendor/autoload.php',       // local dev: composer install in this repo
    __DIR__ . '/../../../autoload.php',         // composer global / project-local require
] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use Dpt\McpRectorWarm\Support\ProcessTree;

$daemonPid = isset($argv[1]) ? (int) $argv[1] : 0;
$workerPid = isset($argv[2]) ? (int) $argv[2] : 0;
$pollSeconds = isset($argv[3]) ? max(1, (int) $argv[3]) : 2;

if ($daemonPid <= 0 || $workerPid <= 0) {
    exit(1);
}

// #159: logged at most once -- run() (inside isAlive()/parentOf()) can fail
// this way on EVERY poll in an environment with no `ps`, and this loop polls
// every $pollSeconds forever; one line is the signal, not a stream of them.
$loggedProbeFailure = false;
while (true) {
    if (ProcessTree::isAlive($workerPid) === false) {
        // The call already finished (or the worker died some other way) -- nothing
        // left to guard.
        exit(0);
    }
    // POSIX: read the WORKER's own current parent pid from the process table --
    // reparenting to init/launchd happens the instant the daemon dies, before
    // anyone reaps it, so this is not fooled by the daemon lingering as a zombie
    // (ProcessTree::isAlive($daemonPid) directly WOULD be: a zombie still answers
    // kill(pid, 0), same trap RectorRunner's own idle-wait poll avoids by checking
    // posix_getppid() from inside the worker rather than probing the daemon's pid
    // -- this watchdog is a third process, so it reads the worker's ppid instead).
    // Windows has no such reparenting concept to read: isAlive($daemonPid) is the
    // only signal there, same as the rest of this no-pcntl path.
    $parent = ProcessTree::parentOf($workerPid);
    // #159: a null $parent means either "the worker's parent is simply not
    // $daemonPid any more" (the case the Windows fallback below already
    // handles) or "`ps` itself could not be run at all" -- on POSIX, the
    // fallback is unconditionally false, so the second case used to read as
    // "not orphaned" forever with no signal. lastProbeRanOk() distinguishes
    // them without changing that fallback's behaviour. Read IMMEDIATELY
    // after the probe it is reporting on -- lastProbeRanOk() is one piece of
    // shared, order-dependent state, and the Windows branch below makes its
    // OWN, separate isAlive($daemonPid) probe call; reading this flag once,
    // here, for BOTH platforms (self-review finding) would silently report
    // on the wrong probe's outcome on Windows once that later call runs.
    $parentProbeFailed = $parent === null && ProcessTree::lastProbeRanOk() === false;
    if ($parentProbeFailed && \PHP_OS_FAMILY !== 'Windows' && !$loggedProbeFailure) {
        $loggedProbeFailure = true;
        if (\defined('STDERR') && \is_resource(\STDERR)) {
            @\fwrite(\STDERR, 'rector-warm-orphan-watchdog: could not run `ps` '
                . "to check whether worker {$workerPid} is orphaned; "
                . "#134's kill-detection is degraded to best-effort here until this recovers (#159)\n");
        }
    }
    if ($parent !== null) {
        $orphaned = $parent !== $daemonPid;
    } elseif (\PHP_OS_FAMILY === 'Windows') {
        $daemonAlive = ProcessTree::isAlive($daemonPid);
        if ($daemonAlive === null && ProcessTree::lastProbeRanOk() === false && !$loggedProbeFailure) {
            $loggedProbeFailure = true;
            if (\defined('STDERR') && \is_resource(\STDERR)) {
                @\fwrite(\STDERR, 'rector-warm-orphan-watchdog: could not run `tasklist` '
                    . "to check whether daemon {$daemonPid} is still alive; "
                    . "#134's kill-detection is degraded to best-effort here until this recovers (#159)\n");
            }
        }
        $orphaned = $daemonAlive === false;
    } else {
        $orphaned = false;
    }
    if ($orphaned) {
        // Same tree-kill as a --call-timeout kill (#112), and in the SAME order
        // RectorRunner::discardProcWorker() already uses it in ("Before
        // proc_terminate(): taskkill /T must find the root alive"): the root must
        // still be alive when its descendants are enumerated, since a dead root's
        // children are reparented to init immediately, before anyone reaps them --
        // the exact fact the parentOf() comment above relies on. A direct kill of
        // the worker FIRST (tried, self-review finding) would reparent any
        // process the worker itself spawned (Rector's own parallel mode) out from
        // under this enumeration, leaking it -- the very #112 leak this call
        // exists to prevent, for the scenario #134 exists to guard.
        //
        // THIS PROCESS is itself one of the worker's own children (spawned by
        // spawnOrphanWatchdog(), which runs INSIDE the worker) -- without
        // excluding its own pid, killTree()'s freeze loop would enumerate this
        // watchdog as a descendant of its own target and SIGSTOP it mid-kill,
        // deadlocking before it ever reaches the final SIGKILL (self-review
        // finding, reproduced empirically).
        ProcessTree::killTree($workerPid, [\getmypid()]);
        exit(0);
    }
    sleep($pollSeconds);
}
