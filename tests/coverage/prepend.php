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
 *   - every `proc_open([PHP_BINARY, ...])` subprocess: most call sites pass
 *     no explicit `env` array at all, so PHP inherits the parent's
 *     environment -- `PHP_INI_SCAN_DIR` included -- for free. The one
 *     exception (tests/Integration/OrphanWatchdogTest.php's PATH-break test,
 *     #269) DOES pass an explicit `env` array, but seeds it from `getenv()`
 *     and only overrides `PATH`, so `PHP_INI_SCAN_DIR` still comes through
 *     unchanged;
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
 *
 * #245 verified, not reasoned: the paragraph above (forked child inherits an
 * already-registered shutdown function, and the parent/child's pcov buffers
 * stay independent after fork) used to be read from source only. It is now
 * measured: a throwaway two-line-probe script (one statement reachable only
 * in the parent, one reachable only in the child) registered a shutdown
 * dump BEFORE calling pcntl_fork(), with no explicit in-child dump call.
 * Result -- parent and child each wrote their own `<pid>-*.json` dump (that
 * probe's own ad hoc bookkeeping format, unrelated to the `.cov`/PHP-report
 * format this file's real `register_shutdown_function()` callback writes
 * below -- the probe needed no more than `json_encode()` for its own
 * throwaway purpose), and each dump showed only its own process's line as
 * hit (the other process's unique line stayed unhit), confirming the two
 * buffers are
 * genuinely separate per-process and both survive to a normal exit()'s
 * shutdown sequence. A child killed by a raw signal (SIGKILL, bypassing
 * PHP's shutdown sequence entirely) produced no dump for itself at all --
 * its coverage would be silently lost -- which is why it matters that none
 * of RectorRunner.php's three `pcntl_fork()` sites (boot()'s worker fork,
 * the standby-worker fork, and the session-retire fork) ever calls anything
 * but a normal `exit()` in the child. So: the fork/merge mechanism this file
 * implements is not the source of any of the three #245 coverage-undercount
 * reports. The real, also-measured cause behind one of them (bin/rector-warm-lsp
 * staying at 0% even after dedicated tests were added) is that nothing this
 * repository runs under coverage instrumentation ever executes that file as
 * its own process: tests/Unit/WorkingDirectoryArgumentTest.php (#254) only
 * exercises the extracted WorkingDirectoryArgument::parse() helper, and the
 * only real invocations of the bin/rector-warm-lsp script -- the Python LSP
 * E2E suite and the headless-nvim/headless-helix smoke jobs -- run in CI
 * jobs that never set PHP_INI_SCAN_DIR/pcov at all (that setup lives only in
 * the `coverage` job's own shell session, via $GITHUB_ENV, which does not
 * cross job boundaries). bin/mcp-rector-warm, by contrast, reads real
 * coverage because tests/Integration/ServerStdioTest.php spawns it as a
 * subprocess from inside the instrumented `coverage` job itself -- the same
 * mechanism this file exists to support. A 0% file-level number for
 * bin/rector-warm-lsp is therefore not evidence of a broken merge; it is an
 * accurate report of "never run under an instrumented process", and closing
 * it for real needs either a subprocess-spawning integration test for this
 * one entrypoint (a product-test decision, not a tooling one) or coverage
 * instrumentation wired into the E2E/headless jobs that already exercise it
 * for real -- both out of scope for this file. The third #245 report (PR
 * #256's new direct-Reflection tests for 9 RectorRunner methods moving the
 * project's total line count by only 3) is also not a pipeline defect: a
 * local instrumented run of the *existing* suite (minus that PR's own new
 * test file) already showed those 9 methods between 80% and 100%
 * line-covered via the full unit+integration run's merge, because other
 * tests already exercise them indirectly through RectorRunner's real
 * warm/cold flow. "0%-covered" in that PR's own commit message meant "no
 * dedicated direct test", not "never executed" -- a real and legitimate
 * reason to add the tests (precision, speed, determinism of a Reflection
 * call vs. an end-to-end flow), but not one that was ever going to move the
 * merged file-level number by much, because the lines were already being
 * hit.
 */

$coverageDir = getenv('MCP_RECTOR_WARM_COVERAGE_DIR');

if ($coverageDir === false || $coverageDir === '' || !extension_loaded('pcov')) {
    return;
}

// #230 regression, root cause: Rector's own parallel-mode worker subprocess
// (ParallelFileProcessor/WorkerCommandLineFactory, invoked as
// "<rector bin> worker --port=N ...") runs entirely inside Rector's own
// bundled, PhpScoper-prefixed vendor tree -- none of mcp-rector-warm's own
// src/ or bin/ code ever executes there, so this file has nothing to
// instrument in a worker and never did. Requiring OUR OWN (real, unprefixed,
// post-#230 react/* among them) vendor/autoload.php below, via
// auto_prepend_file, BEFORE the worker's own entrypoint gets to require
// Rector's bundled autoloader, raced with Rector's prefixed
// react/promise "files"-autoload entry under coverage/pcov's added overhead:
// reproduced locally (10/10 direct invocations) as the worker fataling with
// "Call to undefined function RectorPrefix...\React\Promise\resolve()",
// caught by Rector's own error handling and reported as a clean exit code 1
// with no output -- never a thrown PHP exception, so RectorRunner::runCold()
// saw a normal (if wrong) result and never hit its deadline-kill path at
// all. The kill tests (RectorRunnerProcessTreeKillTest,
// RectorRunnerTest::testColdCallIsKilledAtItsDeadlineWithoutPcntl) rely on
// the rule's sleep() actually running to exercise the kill; a worker that
// never gets that far makes the call finish instead of wedge. Skipping
// entirely for a worker invocation removes the race at its source instead
// of only reducing pcov's instrumentation footprint (#230's prior,
// insufficient attempt: scoping pcov.exclude to vendor/ left this require
// -- not pcov's instrumentation -- as the actual trigger).
$prependArgv = $_SERVER['argv'] ?? [];
if (\is_array($prependArgv) && \in_array('worker', $prependArgv, true)) {
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
// The same '.php' file list the deprecated includeDirectory() builds
// internally, through the same public php-file-iterator facade.
$filter->includeFiles((new \SebastianBergmann\FileIterator\Facade())->getFilesAsArray([$root . '/src', $root . '/bin'], '.php'));
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
