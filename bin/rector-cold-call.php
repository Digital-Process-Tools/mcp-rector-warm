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
// JSON request from stdin, writes one JSON result to stdout, then exits.

// Every PHP-level notice/deprecation/warning display goes to stderr, never onto
// this process's stdout, which carries the JSON result and nothing else (mirrors
// bin/mcp-rector-warm's own hygiene, #15/#26).
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
    fwrite(STDOUT, (string) json_encode([
        'error' => 'rector-cold-call: invalid or missing JSON request on stdin',
        'error_class' => 'RuntimeException',
    ]));
    exit(1);
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

$callArgv = isset($request['call_argv']) && is_array($request['call_argv']) ? $request['call_argv'] : [];

$runner = new RectorRunner();
try {
    $result = $runner->runOnceInThisProcess($callArgv);
    $encoded = json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
    fwrite(STDOUT, $encoded !== false ? $encoded : '{"error":"failed to encode the cold-call result","error_class":"JsonException"}');
} catch (\Throwable $e) {
    fwrite(STDOUT, (string) json_encode([
        'error' => $e->getMessage(),
        'error_class' => $e::class,
    ], JSON_INVALID_UTF8_SUBSTITUTE));
    exit(1);
}
