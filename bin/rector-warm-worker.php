#!/usr/bin/env php
<?php

declare(strict_types=1);

// No-pcntl warm worker process (#108). Spawned by RectorRunner::spawnProcWorker()
// when RectorRunner::canFork() is false (Windows, or #18's disable_functions case):
// reads one JSON request from stdin (daemon argv, cwd, the loopback address to
// connect back to, a token), connects, proves itself with the token, boots Rector,
// then waits as a booted standby and serves exactly ONE call over that socket
// (RectorRunner::serveProcessWorker()) before exiting: every call runs in a
// container nothing was analysed in before, the same guarantee the forked
// grandchild gives on the pcntl path.
//
// stdout is not a channel here (the daemon points it at the null device); every
// PHP-level notice goes to stderr, which the daemon keeps in a temp file and quotes
// when a boot fails.
ini_set('display_errors', 'stderr');

@ini_set('memory_limit', '-1');
foreach ([
    __DIR__ . '/../vendor/autoload.php',       // local dev: composer install in this repo
    __DIR__ . '/../../../autoload.php',         // composer global / project-local require
] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use Dpt\McpRectorWarm\RectorRunner;

$input = stream_get_contents(STDIN);
$request = is_string($input) && $input !== '' ? json_decode($input, true) : null;
if (!is_array($request) || !is_string($request['address'] ?? null) || !is_string($request['token'] ?? null)) {
    fwrite(STDERR, "rector-warm-worker: invalid or missing JSON request on stdin\n");
    exit(1);
}

// RectorConfigsResolver reads --config straight from $_SERVER['argv']: mirror the
// daemon's own launch argv so config resolution matches the pcntl path exactly.
if (isset($request['daemon_argv']) && is_array($request['daemon_argv'])) {
    $_SERVER['argv'] = $request['daemon_argv'];
}
if (isset($request['cwd']) && is_string($request['cwd']) && is_dir($request['cwd'])) {
    chdir($request['cwd']);
}

$socket = @stream_socket_client('tcp://' . $request['address'], $errno, $errstr, 10);
if ($socket === false) {
    fwrite(STDERR, "rector-warm-worker: could not connect back to the daemon at {$request['address']}: {$errstr}\n");
    exit(1);
}

$daemonPid = is_int($request['daemon_pid'] ?? null) ? $request['daemon_pid'] : null;
$code = (new RectorRunner())->serveProcessWorker($socket, $request['token'], $daemonPid);

// Exit code 0 covers two cases: we served our one call and are retiring, or
// nothing ever arrived (EOF -- the daemon discarded us). Either way we are
// exiting on our own with nothing more to do, so remove our own stderr file
// now rather than leaving it for the daemon to reap later (reapRetiredProcWorkers()
// / stopRetiredProcWorkers()) -- if the daemon is kill -9d before that reap ever
// runs, nothing else would ever unlink it (#126). A worker that instead exits
// via WORKER_EXIT_ORPHANED already means the daemon is already gone; the same
// cleanup applies.
if (($code === RectorRunner::WORKER_EXIT_ORPHANED || $code === 0) && is_string($request['stderr_file'] ?? null)) {
    // Close it first: Windows cannot delete a file that is still open.
    fclose(STDERR);
    @unlink($request['stderr_file']);
}

exit($code);
