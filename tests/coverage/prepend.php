<?php

declare(strict_types=1);

/**
 * Coverage prepend for the mcp-rector-warm test suite (#218).
 *
 * pcov only instruments the one process PHPUnit runs in. Code that only runs
 * in a `pcntl_fork()` child (`RectorRunner::boot()`/`forkAndExecute()`/the
 * session-retire fork) or in a `proc_open()` subprocess (`bin/*`, started
 * from `RectorRunner.php` and from `tests/Integration/ServerStdioTest.php`)
 * is invisible to that one process's own `--coverage-clover` report, even
 * though the integration and E2E suites genuinely exercise it.
 *
 * This file is loaded via the `auto_prepend_file` ini directive -- set only
 * through `PHP_INI_SCAN_DIR` in the CI `coverage` job
 * (.github/workflows/tests.yml), never from phpunit.xml's own `bootstrap` --
 * so it is picked up by *every* `php` invocation that job's shell session
 * launches:
 *
 *   - the top-level `vendor/bin/phpunit` process itself;
 *   - every `proc_open([PHP_BINARY, ...])` subprocess, because none of those
 *     call sites pass an explicit `env` array, so PHP inherits the parent's
 *     environment -- `PHP_INI_SCAN_DIR` included -- for free;
 *   - every `pcntl_fork()` child, because a fork duplicates the whole
 *     process image. This file does not run again in the child; the child
 *     simply inherits the shutdown function this file already registered in
 *     the parent, before the fork happened.
 *
 * No-op unless `MCP_RECTOR_WARM_COVERAGE_DIR` is set and the `pcov`
 * extension is loaded, so a non-coverage run (every other CI leg, and any
 * local run) takes zero extra branches -- #218's "must not change behaviour
 * when coverage is off".
 *
 * Deliberately never touches `CodeCoverage::start()`/`stop()`, which call
 * the pcov driver's own `start()`/`stop()`
 * (vendor/phpunit/php-code-coverage/src/Driver/PcovDriver.php) and so pause,
 * resume or clear pcov's single process-global recording buffer. The
 * top-level phpunit process already owns that buffer's start/stop/clear
 * cycle for its own `--coverage-clover` report; a second manager toggling
 * the same global switch on a shutdown-function schedule -- which fires in
 * that same top-level process too, not only in forked children -- would
 * risk pausing or clearing phpunit's own in-flight per-test collection.
 * Instead this reads pcov's own always-on recording through the read-only
 * `pcov\waiting()` / `pcov\collect()` pair -- exactly what
 * `PcovDriver::stop()` does internally -- and skips `pcov\clear()`, leaving
 * that buffer exactly as phpunit's own driver still expects to find it.
 * `CodeCoverage::append()` (unlike `start()`/`stop()`) never calls the
 * driver, so building a `RawCodeCoverageData` by hand from those two
 * read-only calls and handing it to `append()` is this file's only contact
 * with that object.
 *
 * Each process writes its own `<pid>-<random>.cov` file -- the same
 * serialized-`CodeCoverage` format PHPUnit's own `--coverage-php` writes --
 * so `phpcov merge` combines them with the top-level run's own report with
 * no format translation. A forked child's buffer necessarily also contains
 * whatever the parent already executed up to the fork point (pcov's
 * recording is process-wide, and fork duplicates that state); the resulting
 * duplicate "this line ran" entries are harmless under merge, which is a
 * union over processes, not a sum.
 */

$coverageDir = getenv('MCP_RECTOR_WARM_COVERAGE_DIR');

if ($coverageDir === false || $coverageDir === '' || !extension_loaded('pcov')) {
    return;
}

if (defined('MCP_RECTOR_WARM_COVERAGE_PREPEND_LOADED')) {
    // auto_prepend_file runs once per process; this guards only against this
    // file being require()'d a second time by hand in the same process.
    return;
}
define('MCP_RECTOR_WARM_COVERAGE_PREPEND_LOADED', true);

// pcov does not record anything until \pcov\start() is called at least once
// in the process -- confirmed locally (pcov 1.0.12): without it,
// \pcov\waiting() stays empty however much instrumented code runs.
// Calling it here is what makes a *subprocess* (bin/*, no PHPUnit driver
// ever running inside it) record at all. It is also safe to call in the
// top-level phpunit process, and in a pcntl_fork() child that inherited an
// already-"started" state from it: a repeated \pcov\start() call does not
// clear previously recorded hits (confirmed locally -- hits from before and
// after a second start() call both survived to the next collect()), so it
// never competes with PHPUnit's own per-test start()/stop()/clear() cycle
// for the same reason append() below never does: nothing here ever calls
// \pcov\clear().
if (function_exists('pcov\start')) {
    \pcov\start();
}

$root = dirname(__DIR__, 2);

require_once $root . '/vendor/autoload.php';

if (!class_exists(\SebastianBergmann\CodeCoverage\CodeCoverage::class)) {
    // phpunit/php-code-coverage not installed (e.g. a --no-dev install).
    return;
}

$filter = new \SebastianBergmann\CodeCoverage\Filter();
$filter->includeDirectory($root . '/src');
$filter->includeDirectory($root . '/bin');
// includeDirectory() only matches a '.php' suffix by default (same reason
// phpunit.xml's own <source><include> lists these two explicitly): the two
// installed binaries have no extension at all.
$filter->includeFile($root . '/bin/mcp-rector-warm');
$filter->includeFile($root . '/bin/rector-warm-lsp');

try {
    $driver = (new \SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter);
} catch (\Throwable) {
    return;
}

$coverage = new \SebastianBergmann\CodeCoverage\CodeCoverage($driver, $filter);
$prefixes = [$root . '/src/', $root . '/bin/'];

register_shutdown_function(static function () use ($coverage, $coverageDir, $prefixes): void {
    if (!function_exists('pcov\waiting')) {
        return;
    }

    $waiting = \pcov\waiting();

    if ($waiting === []) {
        return;
    }

    $relevant = [];

    foreach ($waiting as $file) {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($file, $prefix)) {
                $relevant[] = $file;

                break;
            }
        }
    }

    if ($relevant === []) {
        return;
    }

    // Read-only: collect() without clear() leaves pcov's process-global
    // buffer untouched for whatever else in *this* process still expects to
    // read it (the top-level phpunit process's own driver, mid test-run,
    // when a fork happens underneath it).
    $collected = \pcov\collect(\pcov\inclusive, $relevant);

    if ($collected === []) {
        return;
    }

    $rawData = \SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData::fromXdebugWithoutPathCoverage($collected);

    try {
        $coverage->append($rawData, sprintf('pid-%d', getmypid()));
    } catch (\Throwable) {
        return;
    }

    $target = sprintf(
        '%s/%d-%s.cov',
        rtrim($coverageDir, '/'),
        getmypid(),
        bin2hex(random_bytes(4)),
    );

    try {
        (new \SebastianBergmann\CodeCoverage\Report\PHP())->process($coverage, $target);
    } catch (\Throwable) {
        // Best-effort: a process that cannot write its own coverage file
        // must not change the product behaviour it is measuring.
    }
});
