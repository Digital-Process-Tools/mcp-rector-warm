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
    $orphaned = $parent !== null
        ? $parent !== $daemonPid
        : (\PHP_OS_FAMILY === 'Windows' && ProcessTree::isAlive($daemonPid) === false);
    if ($orphaned) {
        // A direct SIGKILL of the worker itself first, THEN the tree-kill for its
        // descendants (Rector's own parallel mode, off here -- #134's own repro
        // uses --debug -- but not necessarily off for every call this guards):
        // ProcessTree::killTree()'s POSIX path freezes the WHOLE tree with
        // repeated `ps -A` enumeration rounds before its own final SIGKILL, which
        // is the right trade-off for a clean multi-process kill but is not the
        // fast, bounded response this worker's own exit needs to be -- the direct
        // kill below gives that immediately, and the tree-kill afterward is then
        // pure best-effort cleanup of anything the worker itself spawned.
        if (\function_exists('posix_kill')) {
            @\posix_kill($workerPid, \defined('SIGKILL') ? \SIGKILL : 9);
        }
        ProcessTree::killTree($workerPid);
        exit(0);
    }
    sleep($pollSeconds);
}
