#!/usr/bin/env php
<?php

declare(strict_types=1);

// One-shot, cold Rector run: booted and executed in a brand-new OS process every
// time, so re-requiring rector.php (or a withBootstrapFiles file) that declares a
// class or function can never redeclare -- unlike RectorRunner's warm path, this
// process boots exactly once, ever (#31's no-pcntl fallback). Spawned by
// RectorRunner::runCold() only when RectorRunner::canFork() is false (no pcntl
// at all -- Windows, or #18's disable_functions case), since there is then no
// other safe way to isolate a reboot from an already-booted process. Reads one
// JSON request from stdin, writes one JSON result to the temp file the request
// names ('result_file' -- #46, never stdout), then exits.

// Every PHP-level notice/deprecation/warning display goes to stderr (mirrors
// bin/mcp-rector-warm's own hygiene, #15/#26). stdout is NOT the result channel:
// Rector's own SymfonyStyle/ConsoleOutput can write straight to the real stdout
// (a deprecated-set warning, an onboarding notice), bypassing ob_start() and any
// display_errors setting entirely -- so a channel shared with Rector's own console
// output cannot be trusted to carry clean JSON (#46). The result goes to the temp
// file named in the request's 'result_file' instead; stdout and stderr are left
// free for whatever Rector itself chooses to write there, and RectorRunner::runCold()
// only ever reads them as diagnostics, never as data.
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
if (!is_array($request)) {
    fwrite(STDERR, 'rector-cold-call: invalid or missing JSON request on stdin');
    exit(1);
}

$resultFile = isset($request['result_file']) && is_string($request['result_file']) && $request['result_file'] !== ''
    ? $request['result_file']
    : null;

/**
 * Write the cold call's result to its channel: the result_file the parent named in
 * the request, or stderr as a last resort when the parent never gave one (an old
 * parent, or a malformed request) -- never stdout, which #46 established Rector
 * itself may already be writing to.
 */
function writeColdResult(?string $resultFile, string $json): void
{
    if ($resultFile === null) {
        fwrite(STDERR, $json);
        return;
    }
    file_put_contents($resultFile, $json);
}

// RectorConfigsResolver reads --config straight from $_SERVER['argv'] (see
// bin/mcp-rector-warm); mirror the daemon's own launch argv here so config
// resolution matches exactly what the warm path would have used.
if (isset($request['daemon_argv']) && is_array($request['daemon_argv'])) {
    $_SERVER['argv'] = $request['daemon_argv'];
}
if (isset($request['cwd']) && is_string($request['cwd']) && is_dir($request['cwd'])) {
    chdir($request['cwd']);
}

/** @var list<string> $callArgv */
$callArgv = isset($request['call_argv']) && is_array($request['call_argv'])
    ? array_values(array_filter($request['call_argv'], is_string(...)))
    : [];

$runner = new RectorRunner();
try {
    $result = $runner->runOnceInThisProcess($callArgv);
    $encoded = json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
    writeColdResult($resultFile, $encoded !== false ? $encoded : '{"error":"failed to encode the cold-call result","error_class":"JsonException"}');
} catch (\Throwable $e) {
    writeColdResult($resultFile, (string) json_encode([
        'error' => $e->getMessage(),
        'error_class' => $e::class,
    ], JSON_INVALID_UTF8_SUBSTITUTE));
    exit(1);
}
