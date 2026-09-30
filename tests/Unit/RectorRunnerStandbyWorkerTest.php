<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorRunner;
use PHPUnit\Framework\TestCase;

/**
 * #108: the warm path without pcntl. canFork() is forced false, so these run the
 * real standby worker process (bin/rector-warm-worker.php) on every platform --
 * on Windows that is simply the platform's own path.
 *
 * The property that matters is the one the fork gives for free: every call runs
 * in a container nothing was analysed in before. A long-lived worker serving every
 * call in one container was measured to break exactly
 * testADependencyEditBetweenWarmCallsIsSeen (#8's own reproduction), so that test is
 * the guard against anyone "optimising" the standby into a reused worker.
 */
final class RectorRunnerStandbyWorkerTest extends TestCase
{
    private ?string $tmp = null;
    private string|false $previousCwd = false;
    /** @var list<string> */
    private array $previousArgv = [];
    private string|false $previousMode = false;

    protected function setUp(): void
    {
        $this->previousCwd = getcwd();
        $this->previousArgv = $_SERVER['argv'] ?? ['rector'];
        $this->previousMode = getenv(RectorRunner::NO_PCNTL_MODE_ENV);
        // 'NAME=' (empty) rather than a bare 'NAME' unset: portable to Windows, and
        // anything but 'cold' selects the standby.
        putenv(RectorRunner::NO_PCNTL_MODE_ENV . '=');
    }

    protected function tearDown(): void
    {
        if ($this->previousCwd !== false) {
            chdir($this->previousCwd);
        }
        $_SERVER['argv'] = $this->previousArgv;
        putenv(RectorRunner::NO_PCNTL_MODE_ENV . '=' . ($this->previousMode === false ? '' : $this->previousMode));
        if ($this->tmp !== null) {
            self::removeTree($this->tmp);
        }
    }

    public function testTheSecondCallWithoutPcntlIsWarm(): void
    {
        $project = $this->makeDependencyProject();
        $runner = self::noPcntlRunner();

        try {
            $first = $runner->run(self::argv($project . '/src/Caller.php'));
            $second = $runner->run(self::argv($project . '/src/Caller.php'));

            self::assertFalse($first['warm_boot'], 'the first call boots on demand');
            self::assertTrue($second['warm_boot'], 'the second call must be served by the standby the first left booted (#108)');
            self::assertSame(self::diff($first), self::diff($second));
            self::assertStringContainsString('bar(Dependency $dependency): int', self::diff($second));
        } finally {
            $runner->reboot();
        }
    }

    public function testTheColdEscapeHatchKeepsEveryCallCold(): void
    {
        // Paired control for the test above: the same sequence, forced cold.
        putenv(RectorRunner::NO_PCNTL_MODE_ENV . '=' . RectorRunner::NO_PCNTL_MODE_COLD);
        $project = $this->makeDependencyProject();
        $runner = self::noPcntlRunner();

        $first = $runner->run(self::argv($project . '/src/Caller.php'));
        $second = $runner->run(self::argv($project . '/src/Caller.php'));

        self::assertFalse($first['warm_boot']);
        self::assertFalse($second['warm_boot'], 'MCP_RECTOR_WARM_NO_PCNTL=cold must bring back a cold subprocess per call');
        self::assertFalse($runner->isWarm());
        self::assertSame(self::diff($first), self::diff($second));
    }

    public function testADependencyEditBetweenWarmCallsIsSeen(): void
    {
        $project = $this->makeDependencyProject();
        $runner = self::noPcntlRunner();

        try {
            $first = $runner->run(self::argv($project . '/src/Caller.php'));
            self::assertStringContainsString('bar(Dependency $dependency): int', self::diff($first), 'baseline');

            file_put_contents($project . '/src/Dependency.php', self::dependencyClass('string', "'x'"));
            touch($project . '/src/Dependency.php', time() + 5);

            $second = $runner->run(self::argv($project . '/src/Caller.php'));
            self::assertTrue($second['warm_boot'], 'must fire: the edit is tested against a warm call, not a reboot');
            self::assertStringContainsString(
                'bar(Dependency $dependency): string',
                self::diff($second),
                'a warm call must see the edited Dependency::foo(): string, as cold does (#8)',
            );
        } finally {
            $runner->reboot();
        }
    }

    public function testAConfigEditBetweenCallsDiscardsTheStandby(): void
    {
        $project = $this->makeDependencyProject();
        $runner = self::noPcntlRunner();

        try {
            $runner->run(self::argv($project . '/src/Caller.php'));
            self::assertTrue($runner->isWarm(), 'control: a standby is waiting after the first call');
            // Let the standby finish booting from the OLD rector.php first. An edit
            // that lands before it even hashed the file is simply booted from (warm and
            // correct); this test is about one that lands after.
            $await = new \ReflectionMethod(RectorRunner::class, 'awaitProcWorkerReady');
            $await->invoke($runner, null);

            file_put_contents($project . '/rector.php', "<?php\n\ndeclare(strict_types=1);\n\n"
                . "use Rector\\Config\\RectorConfig;\n"
                . "use Rector\\Php82\\Rector\\Class_\\ReadOnlyClassRector;\n\n"
                . "return RectorConfig::configure()->withPaths([__DIR__ . '/src'])"
                . "->withRules([ReadOnlyClassRector::class]);\n");

            $second = $runner->run(self::argv($project . '/src/Caller.php'));
            self::assertFalse($second['warm_boot'], 'the standby booted from the old rector.php must not serve the call (#20)');
            self::assertStringNotContainsString('): int', self::diff($second), 'the old rule must be gone');
        } finally {
            $runner->reboot();
        }
    }

    /**
     * A daemon that dies without cleaning up (kill -9, a crash) must not leave its
     * booted standby behind: nothing ever connects to it again, and before this fix
     * it blocked forever in its idle read -- it holds an inherited copy of the
     * daemon's listening socket, so its own still-unaccepted connection never saw
     * EOF. Paired control: the same standby is alive while the daemon is.
     */
    public function testTheStandbyExitsWhenTheDaemonIsKilled(): void
    {
        $project = $this->makeDependencyProject();
        $script = $project . '/daemon.php';
        file_put_contents($script, "<?php\n"
            . 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
            . 'chdir(' . var_export($project, true) . ");\n"
            . "\$_SERVER['argv'] = ['rector'];\n"
            . "\$r = new class(120) extends \\Dpt\\McpRectorWarm\\RectorRunner { protected function canFork(): bool { return false; } };\n"
            . '$r->run(' . var_export(self::argv($project . '/src/Caller.php'), true) . ");\n"
            . "\$w = (new \\ReflectionProperty(\\Dpt\\McpRectorWarm\\RectorRunner::class, 'procWorker'))->getValue(\$r);\n"
            . "echo \$w['pid'], ' ', \$w['stderr'], \"\\n\";\n"
            . "fflush(STDOUT);\n"
            . "sleep(300);\n");

        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $daemon = proc_open([\PHP_BINARY, $script], [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes);
        self::assertIsResource($daemon);
        $workerPid = 0;
        try {
            $line = (string) fgets($pipes[1]);
            self::assertMatchesRegularExpression('/^\d+ \S+$/', trim($line), 'the daemon must report its standby: ' . $line);
            [$pid, $stderrFile] = explode(' ', trim($line), 2);
            $workerPid = (int) $pid;

            // Let the standby finish booting, connect, and settle into its idle wait.
            sleep(4);
            self::assertTrue(self::isAlive($workerPid), 'control: the standby is alive while the daemon is');

            proc_terminate($daemon, 9);

            $deadline = microtime(true) + 15.0;
            while (self::isAlive($workerPid) && microtime(true) < $deadline) {
                usleep(200_000);
            }
            self::assertFalse(self::isAlive($workerPid), 'the standby must exit once its daemon is gone (#108)');
            self::assertFileDoesNotExist($stderrFile, 'an orphaned standby must not leave its stderr temp file behind');
        } finally {
            fclose($pipes[1]);
            proc_terminate($daemon, 9);
            proc_close($daemon);
            if ($workerPid > 0 && self::isAlive($workerPid)) {
                \Dpt\McpRectorWarm\Support\ProcessTree::killTree($workerPid);
            }
        }
    }

    /**
     * The whole accept loop runs under the call deadline: a local process that
     * connects and says nothing costs at most the remaining deadline, not 5s per
     * connection with the deadline never checked in between.
     */
    public function testSilentStrangersCannotStallTheBootWaitPastTheDeadline(): void
    {
        $runner = self::noPcntlRunner();
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server);
        $address = (string) stream_socket_get_name($server, false);
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        // Stands in for a worker that is alive but slow to connect.
        $proc = proc_open([\PHP_BINARY, '-r', 'sleep(60);'], [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        self::assertIsResource($proc);
        $strangers = [];
        for ($i = 0; $i < 4; $i++) {
            $strangers[] = stream_socket_client('tcp://' . $address);
        }

        $property = new \ReflectionProperty(RectorRunner::class, 'procWorker');
        $property->setValue($runner, [
            'proc' => $proc,
            'pid' => (int) proc_get_status($proc)['pid'],
            'server' => $server,
            'socket' => null,
            'token' => str_repeat('a', 32),
            'stderr' => (string) tempnam(sys_get_temp_dir(), 'standby-test-'),
            'ready' => false,
        ]);
        $await = new \ReflectionMethod(RectorRunner::class, 'awaitProcWorkerReady');

        $start = microtime(true);
        $message = null;
        try {
            $await->invoke($runner, hrtime(true) + 2_000_000_000);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        } finally {
            $runner->reboot();
            foreach ($strangers as $stranger) {
                fclose($stranger);
            }
        }
        $elapsed = microtime(true) - $start;

        self::assertNotNull($message, 'must fire: the wait must end in a timeout error');
        self::assertStringContainsString('--call-timeout', (string) $message);
        self::assertLessThan(5.0, $elapsed, "4 silent connections must not stretch a 2s deadline (took {$elapsed}s)");
    }

    /**
     * #126 part 2: a retired worker (one that already served its call and is
     * exiting on its own) must remove its own stderr temp file rather than
     * leaving it for the daemon to reap later -- if the daemon is kill -9d
     * before that reap ever runs, nothing else would ever unlink it. Nothing
     * else in this test touches that file: there is no second call (which
     * would run reapRetiredProcWorkers()), and $runner is still alive (its
     * own __destruct() has not run), so a disappearance can only be the
     * worker's own doing.
     */
    public function testARetiredWorkerRemovesItsOwnStderrFileWithoutWaitingForTheDaemonToReapIt(): void
    {
        $project = $this->makeDependencyProject();
        $runner = self::noPcntlRunner();

        try {
            $runner->run(self::argv($project . '/src/Caller.php'));

            $retiredProperty = new \ReflectionProperty(RectorRunner::class, 'retiredProcWorkers');
            $retired = $retiredProperty->getValue($runner);
            self::assertNotEmpty($retired, 'control: the first call must have retired a worker');
            $proc = $retired[0]['proc'];
            $stderrFile = $retired[0]['stderr'];

            $deadline = microtime(true) + 15.0;
            while (proc_get_status($proc)['running'] && microtime(true) < $deadline) {
                usleep(50_000);
            }
            self::assertFalse(proc_get_status($proc)['running'], 'control: the retired worker must actually exit on its own');

            self::assertFileDoesNotExist(
                $stderrFile,
                'a retired worker must remove its own stderr file on exit -- if the daemon is killed before it ever reaps this worker, nothing else would (#126)',
            );
        } finally {
            $runner->reboot();
        }
    }

    /**
     * #126 part 1: a call served by an already-warm standby that then times out
     * must still report it was warm -- isWarm() alone cannot, because the
     * timeout path discards the worker (discardProcWorker(true)) before the
     * caller ever asks, so by then isWarm() always says false regardless of
     * what actually served the call.
     */
    public function testATimedOutCallStillReportsItWasServedWarm(): void
    {
        $runner = new class(1) extends RectorRunner {
            protected function canFork(): bool
            {
                return false;
            }
        };

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server);
        $address = (string) stream_socket_get_name($server, false);
        $client = @stream_socket_client('tcp://' . $address);
        self::assertNotFalse($client);
        $accepted = stream_socket_accept($server, 1);
        self::assertNotFalse($accepted, 'control: something must actually connect');

        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        // Stands in for a worker process that is alive but never answers --
        // forces callProcWorker()'s read to hit the --call-timeout deadline.
        $proc = proc_open([\PHP_BINARY, '-r', 'sleep(60);'], [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        self::assertIsResource($proc);
        $stderrFile = (string) tempnam(sys_get_temp_dir(), 'wasLastCallWarm-test-');

        $property = new \ReflectionProperty(RectorRunner::class, 'procWorker');
        $property->setValue($runner, [
            'proc' => $proc,
            'pid' => (int) proc_get_status($proc)['pid'],
            'server' => $server,
            'socket' => $client,
            'token' => str_repeat('a', 32),
            'stderr' => $stderrFile,
            'ready' => true,
        ]);

        $callProcWorker = new \ReflectionMethod(RectorRunner::class, 'callProcWorker');

        $threw = null;
        try {
            $callProcWorker->invoke($runner, ['rector'], true, true);
        } catch (\RuntimeException $e) {
            $threw = $e;
        } finally {
            // callProcWorker() itself already discards (and proc_close()s) the
            // worker on a timeout -- guard against double-closing an already
            // invalid resource here.
            @fclose($accepted);
            if (is_resource($proc)) {
                proc_terminate($proc, 9);
                proc_close($proc);
            }
            @unlink($stderrFile);
        }

        self::assertNotNull($threw, 'must fire: nothing ever answers, so the call must time out');
        self::assertStringContainsString('--call-timeout', $threw->getMessage());
        self::assertFalse($runner->isWarm(), 'control: the timed-out worker was discarded, so isWarm() now says false');
        self::assertTrue($runner->wasLastCallWarm(), 'the call WAS served by an already-warm standby before it timed out (#126)');
    }

    /**
     * #125: -d flags given to the daemon are lost when it spawns the no-pcntl
     * standby worker as a brand-new process (proc_open([PHP_BINARY, script])) --
     * unlike pcntl_fork(), which clones the same process image, ini overrides
     * included, for free. ini_get_all(null, true)'s local_value diverges from
     * its global_value for exactly the settings a -d flag (or an early
     * ini_set(), same effect from proc_open()'s point of view) actually
     * changed; reconstructing -d flags from that diff is the only portable way
     * to recover them (there is no reliable, cross-platform way to read back
     * the original command line on Windows, the platform this whole no-pcntl
     * path exists for).
     */
    public function testIniOverridesActiveOnTheDaemonAreForwardedAsDFlagsToTheStandby(): void
    {
        $previous = ini_get('precision');
        $changed = $previous === '17' ? '15' : '17';
        ini_set('precision', $changed);

        try {
            $method = new \ReflectionMethod(RectorRunner::class, 'collectIniOverrideArgs');
            $args = $method->invoke(null);

            self::assertContains('-d', $args);
            self::assertContains("precision='{$changed}'", $args, 'must fire: a directive changed on the daemon must be forwarded, single-quoted raw INI per #130 since a plain number has no single quote in it');
            self::assertStringNotContainsString(
                'default_mimetype=',
                implode('|', $args),
                'must not fire: a directive nobody touched must never be forwarded',
            );
        } finally {
            ini_set('precision', $previous);
        }
    }

    /**
     * Blocking finding from an independent E2E review of this branch: a REAL CLI
     * -d flag (unlike ini_set()) makes the PHP CLI SAPI fold the override into
     * BOTH global_value AND local_value from process start, so a diff between
     * them (the original #125 implementation) never fires for it -- confirmed
     * empirically (`php -d precision=15 -r "..."` reports global_value ===
     * local_value === "15"). collectIniOverrideArgs() must be forwarding an
     * override whose SOURCE is a genuine -d flag on THIS process own command
     * line, not merely one that happens to diverge locally-from-globally at
     * runtime (which only ini_set() -- never a real -d flag -- produces). This
     * spawns a real "php -d precision=N ..." child and reads back what
     * collectIniOverrideArgs() decides INSIDE that child.
     */
    public function testARealCliDFlagIsForwardedNotJustARuntimeIniSetCall(): void
    {
        $currentDefault = (string) ini_get('precision');
        $changed = $currentDefault === '15' ? '17' : '15';

        $script = (string) tempnam(sys_get_temp_dir(), 'ini-override-probe-');
        file_put_contents(
            $script,
            "<?php\n"
            . 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
            . '$m = new \ReflectionMethod(\Dpt\McpRectorWarm\RectorRunner::class, "collectIniOverrideArgs");' . "\n"
            . "\$m->setAccessible(true);\n"
            . "echo json_encode(\$m->invoke(null));\n",
        );

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([\PHP_BINARY, '-d', "precision={$changed}", $script], $descriptors, $pipes);
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        @unlink($script);

        self::assertSame(0, $code, "probe script must exit cleanly: stderr={$err}");
        $args = json_decode($out, true);
        self::assertIsArray($args, "probe output must be JSON: {$out}");
        self::assertContains(
            "precision='{$changed}'",
            $args,
            'a REAL CLI -d flag on the current process must be forwarded (single-quoted raw INI per #130, since a plain number has no single quote in it), not just a runtime ini_set() call',
        );
    }

    /**
     * Second blocking finding from the same review: the old `!is_string($global)`
     * skip incorrectly treated a directive whose true default is not a string
     * (e.g. null) as "leave it alone", even when its current value plainly
     * differs and should be forwarded. isIniOverridden() is the extracted pure
     * decision now covering that case directly, independent of any real ini
     * state (no baseline directive in THIS php build is guaranteed to have a
     * null default, so this is exercised at the unit level rather than by
     * hunting for one).
     */
    public function testANullBaselineDefaultDoesNotSuppressForwardingAChangedValue(): void
    {
        $method = new \ReflectionMethod(RectorRunner::class, 'isIniOverridden');

        self::assertTrue(
            $method->invoke(null, null, 'something'),
            'must fire: a null (non-string) default must never read as "matches current"',
        );
        self::assertFalse(
            $method->invoke(null, 'same', 'same'),
            'must not fire: a string default that matches current must not be forwarded',
        );
        self::assertFalse(
            $method->invoke(null, null, null),
            'must not fire: nothing to forward when the current value itself is null',
        );
    }

    /**
     * Blocking finding from a second independent E2E review of this branch:
     * forwarded -d values were not quoted at all, so PHPs OWN ini-value parser
     * (the identical parser php.ini itself uses) silently truncates at the
     * first "reserved" character rather than raising a forwarding error. The
     * schedulers own repro: -d user_agent=Mozilla/5.0 (X11; Linux) truncated
     * to "Mozilla/5.0 " with a syntax error on the remainder -- confirmed
     * empirically before this fix. This drives the FULL round trip
     * end-to-end: set a value containing "(", "=", ";" and a literal quote
     * together (matching the spirit of the schedulers repro plus an
     * embedded quote), get the exact -d argument collectIniOverrideArgs()
     * produces for it, then feed THAT argument to a real php subprocess and
     * confirm the value comes back byte-for-byte unchanged.
     */
    public function testAForwardedValueWithReservedCharactersAndAQuoteSurvivesARealChildProcess(): void
    {
        $previous = ini_get('user_agent');
        $raw = 'Mozilla/5.0 (X11; rv=1.0; note="quoted")';
        ini_set('user_agent', $raw);

        $userAgentArg = null;
        try {
            $method = new \ReflectionMethod(RectorRunner::class, 'collectIniOverrideArgs');
            $args = $method->invoke(null);

            foreach ($args as $i => $arg) {
                if ($arg === '-d' && isset($args[$i + 1]) && str_starts_with($args[$i + 1], 'user_agent=')) {
                    $userAgentArg = $args[$i + 1];
                    break;
                }
            }
        } finally {
            ini_set('user_agent', $previous);
        }

        self::assertNotNull($userAgentArg, 'must fire: a runtime-changed user_agent must be forwarded');

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [\PHP_BINARY, '-d', $userAgentArg, '-r', 'echo json_encode(ini_get("user_agent"));'],
            $descriptors,
            $pipes,
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        self::assertSame(0, $code, "child must exit cleanly: stderr={$err}");
        self::assertSame(
            json_encode($raw),
            $out,
            'a value containing "(", "=", ";" and a literal quote must survive a real child process unchanged (scheduler repro, #125 follow-up)',
        );
    }

    /**
     * Shared helper for the #130 round-trip cases below: forwards $raw via
     * ini_set('user_agent', ...), reads back the -d argument
     * collectIniOverrideArgs() produces for it, feeds that argument to a real
     * php subprocess, and returns what THAT process's ini_get('user_agent')
     * reports. Mirrors testAForwardedValueWithReservedCharactersAndAQuoteSurvivesARealChildProcess's
     * round trip exactly, factored out so each #130 case states only its own
     * value and assertion.
     */
    private static function roundTripUserAgentThroughARealChildProcess(string $raw): string
    {
        $previous = ini_get('user_agent');
        ini_set('user_agent', $raw);

        $userAgentArg = null;
        try {
            $method = new \ReflectionMethod(RectorRunner::class, 'collectIniOverrideArgs');
            $args = $method->invoke(null);

            foreach ($args as $i => $arg) {
                if ($arg === '-d' && isset($args[$i + 1]) && str_starts_with($args[$i + 1], 'user_agent=')) {
                    $userAgentArg = $args[$i + 1];
                    break;
                }
            }
        } finally {
            ini_set('user_agent', $previous);
        }

        self::assertNotNull($userAgentArg, 'must fire: a runtime-changed user_agent must be forwarded');

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [\PHP_BINARY, '-d', $userAgentArg, '-r', 'echo json_encode(ini_get("user_agent"));'],
            $descriptors,
            $pipes,
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        self::assertSame(0, $code, "child must exit cleanly: stderr={$err}");

        $decoded = json_decode($out, true);
        self::assertIsString($decoded, "child output must decode to a string: {$out}");

        return $decoded;
    }

    /**
     * #130: an independent E2E review of #129 found that a literal `${VAR}`
     * inside a forwarded double-quoted -d value gets silently expanded by the
     * standby worker's own INI parser -- so a value containing that exact
     * four/five-character sequence must still come back byte-for-byte, not
     * with `${nosuchdirective}` replaced by something else (typically empty).
     */
    public function testAForwardedValueContainingLiteralDollarBraceSurvivesARealChildProcess(): void
    {
        $raw = 'literal ${nosuchdirective} here';

        self::assertSame(
            $raw,
            self::roundTripUserAgentThroughARealChildProcess($raw),
            'a literal ${VAR}-shaped sequence must survive a real child process unchanged, not be interpolated by the ini parser (#130)',
        );
    }

    /**
     * #130: single-quoted raw INI has no escape processing at all, so a value
     * containing a backslash anywhere (not just trailing) must round-trip with
     * the backslash itself unchanged -- no doubling, no stripping.
     */
    public function testAForwardedValueContainingABackslashSurvivesARealChildProcess(): void
    {
        $raw = 'C:\\Users\\test\\rector.php';

        self::assertSame(
            $raw,
            self::roundTripUserAgentThroughARealChildProcess($raw),
            'a value containing a backslash must survive a real child process unchanged (#130)',
        );
    }

    /**
     * #130: the double-quoted fallback escapes a trailing backslash specifically
     * so it cannot swallow the closing quote; the single-quoted form taken by
     * this same value must not need that escaping and must still round-trip the
     * literal trailing backslash unchanged (the Windows-path shape named in the
     * issue).
     */
    public function testAForwardedValueEndingInATrailingBackslashSurvivesARealChildProcess(): void
    {
        $raw = 'C:\\Users\\test\\';

        self::assertSame(
            $raw,
            self::roundTripUserAgentThroughARealChildProcess($raw),
            'a value ending in a trailing backslash must survive a real child process unchanged (#130)',
        );
    }

    /**
     * #130: a value containing a literal single quote cannot use the new
     * single-quoted raw form (no escape exists for it there), so it must still
     * fall back to the original double-quoted + escaped form and round-trip
     * unchanged -- this is the pre-existing #125 coverage, re-asserted here so
     * the #130 fallback branch itself is directly exercised too.
     */
    public function testAForwardedValueContainingASingleQuoteFallsBackToDoubleQuotedFormAndSurvives(): void
    {
        $raw = "it's a literal quote";

        self::assertSame(
            $raw,
            self::roundTripUserAgentThroughARealChildProcess($raw),
            'a value containing a single quote must fall back to double-quoted+escaped form and still survive unchanged (#130)',
        );
    }

    /**
     * #135: split out of the E2E check on PR #133. Fixing #130 made a literal
     * `${VAR}` round-trip using INI single quotes, but a value containing BOTH a
     * single quote and `${...}` used to fall back to the single whole-value
     * double-quoted form (#125's original fallback), which the ini parser DOES
     * interpolate -- 4 of 19 probes failed exactly this way: `it's ${HOME}`
     * reached the worker as `it's /Users/...`, `o'k ${precision}` as `o'k 14`.
     * Both of the issue's own repro values, asserted against a REAL child
     * process the same way #130's own single-quote and dollar-brace tests are.
     */
    public function testAForwardedValueContainingBothASingleQuoteAndDollarBraceSurvivesARealChildProcess(): void
    {
        foreach (["it's \${HOME}", "o'k \${precision}"] as $raw) {
            self::assertSame(
                $raw,
                self::roundTripUserAgentThroughARealChildProcess($raw),
                "a value containing both a single quote and \\\${...} must survive a real child process unchanged, not partially interpolated (#135): {$raw}",
            );
        }
    }

    /**
     * Self-review finding: $lastCallWasWarm must not survive across calls when
     * THIS call fails before ever reaching a decision point (boot(),
     * spawnProcWorker(), awaitProcWorkerReady()) -- otherwise it silently
     * inherits whatever a PREVIOUS, unrelated call last decided, which is
     * exactly the class of misreport #126 itself was filed for, just in the
     * opposite direction. canFork() is forced true (regardless of what this
     * environment actually has) purely to select run()'s pcntl branch; the
     * overridden boot() below throws before ever touching a real pcntl
     * function, so this is safe on every platform, including the no-pcntl CI
     * job and Windows.
     */
    public function testACallThatFailsBeforeAnyDecisionDoesNotInheritAPreviousCallsWarmState(): void
    {
        $runner = new class extends RectorRunner {
            protected function canFork(): bool
            {
                return true;
            }

            protected function boot(): void
            {
                throw new \RuntimeException('simulated cold-boot failure');
            }
        };

        // Simulate a PRIOR, unrelated call that really was warm.
        $property = new \ReflectionProperty(RectorRunner::class, 'lastCallWasWarm');
        $property->setValue($runner, true);

        $threw = null;
        try {
            $runner->run(['rector']);
        } catch (\RuntimeException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'must fire: boot() always throws here, so this call must fail before ever deciding warm/cold');
        self::assertFalse($runner->isWarm(), 'control: nothing ever booted');
        self::assertFalse(
            $runner->wasLastCallWarm(),
            'a call that fails before reaching a warm/cold decision point must not inherit a PREVIOUS calls warm state (self-review finding)',
        );
    }

    /**
     * #134: split out of the E2E check on PR #133. #127/#108's idle-wait poll in
     * serveProcessWorker() only runs BEFORE a call's request frame arrives; once
     * execute() is running there is no fork and no tick point to hang a
     * daemon-liveness check off, so a daemon killed -9 mid-call used to leave the
     * standby worker running to completion regardless. A REAL daemon subprocess
     * (not this test process, which cannot safely kill -9 itself) runs two calls:
     * a quick one against Quick.php (so runViaStandbyWorker()'s own finally block
     * retires that worker and pre-spawns a fresh standby for the NEXT call before
     * $r->run() returns), then reports that fresh standby's real pid, then a
     * second call against Wedge.php -- picked up by the SAME pre-spawned standby
     * (warm) -- whose custom rule sleeps, simulating an in-flight analysis. The
     * daemon is SIGKILLed while that sleep is in progress and the test polls how
     * long the reported worker pid keeps answering kill(pid, 0).
     */
    public function testTheStandbyExitsWhenItsDaemonIsKilledMidCall(): void
    {
        $tmp = sys_get_temp_dir() . '/rector-runner-134-daemon-kill-mid-call-test-' . bin2hex(random_bytes(8));
        mkdir($tmp);
        mkdir($tmp . '/src');
        file_put_contents(
            $tmp . '/src/Quick.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class Quick\n{\n}\n",
        );
        file_put_contents(
            $tmp . '/src/Wedge.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class Wedge\n{\n}\n",
        );
        file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "use PhpParser\Node;\n"
            . "use PhpParser\Node\Stmt\Class_;\n"
            . "use Rector\Config\RectorConfig;\n"
            . "use Rector\Rector\AbstractRector;\n\n"
            . "final class Wedge134Rector extends AbstractRector\n"
            . "{\n"
            . "    public function getNodeTypes(): array\n"
            . "    {\n"
            . "        return [Class_::class];\n"
            . "    }\n\n"
            . "    public function refactor(Node \$node): ?Node\n"
            . "    {\n"
            . "        if ((\$node->name?->toString() ?? '') === 'Wedge') {\n"
            . "            sleep(20);\n"
            . "        }\n\n"
            . "        return null;\n"
            . "    }\n"
            . "}\n\n"
            . "return RectorConfig::configure()->withRules([Wedge134Rector::class]);\n",
        );

        $driverScript = $tmp . '/driver.php';
        file_put_contents(
            $driverScript,
            "<?php\n"
            . "declare(strict_types=1);\n"
            . 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
            . 'chdir(' . var_export($tmp, true) . ");\n"
            . "\$_SERVER['argv'] = ['rector'];\n"
            . "\$runner = new class(0) extends \Dpt\McpRectorWarm\RectorRunner {\n"
            . "    protected function canFork(): bool\n"
            . "    {\n"
            . "        return false;\n"
            . "    }\n"
            . "};\n"
            . '$runner->run(' . var_export(self::argv($tmp . '/src/Quick.php'), true) . ");\n"
            . "\$ref = new \ReflectionProperty(\Dpt\McpRectorWarm\RectorRunner::class, 'procWorker');\n"
            . "\$ref->setAccessible(true);\n"
            . "\$w = \$ref->getValue(\$runner);\n"
            . "echo 'WORKER_PID=' . \$w['pid'] . \"\\n\";\n"
            . "fflush(STDOUT);\n"
            . '$runner->run(' . var_export(self::argv($tmp . '/src/Wedge.php'), true) . ");\n",
        );

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([\PHP_BINARY, $driverScript], $descriptors, $pipes);
        self::assertIsResource($proc, 'must be able to spawn the driver ("daemon") subprocess');
        fclose($pipes[0]);

        $workerPid = null;
        try {
            $deadline = microtime(true) + 30.0;
            $buffer = '';
            while ($workerPid === null && microtime(true) < $deadline) {
                $line = fgets($pipes[1]);
                if ($line === false) {
                    break;
                }
                $buffer .= $line;
                if (preg_match('/WORKER_PID=(\d+)/', $line, $m) === 1) {
                    $workerPid = (int) $m[1];
                }
            }
            self::assertNotNull($workerPid, "driver never reported a worker pid; stdout so far: {$buffer}");
            self::assertTrue(
                self::isAlive($workerPid),
                'must fire: the reported worker pid must genuinely be alive right after the daemon reported it',
            );

            $status = proc_get_status($proc);
            self::assertIsArray($status);
            $daemonPid = (int) $status['pid'];

            // Give the pre-spawned standby time to finish booting and receive the
            // second (wedge) call's request frame -- it started booting the moment
            // the FIRST call's finally block spawned it, so most of this overlaps
            // work already in flight; the sleep(20) in the rule leaves comfortable
            // margin either way.
            sleep(4);

            self::assertTrue(self::isAlive($daemonPid), 'control: the daemon must still be alive right before it is killed');
            // posix_kill()/SIGKILL are pcntl/posix-only: both are UNCONDITIONALLY
            // evaluated by a bare `||` expression regardless of which side short-
            // circuits, so referencing either on Windows (no posix extension, and
            // SIGKILL is defined by pcntl, absent there too -- see this file's own
            // src/RectorRunner.php:1554 comment) is a fatal error before the
            // fallback ever runs, not a graceful skip (self-review finding).
            if (\PHP_OS_FAMILY === 'Windows') {
                // Deliberately NOT ProcessTree::killTree($daemonPid): its Windows
                // branch runs `taskkill /T /F`, which kills the daemon's WHOLE
                // process tree -- including the worker -- synchronously, before
                // this call even returns (ProcessTree.php's own doc comment on
                // that branch names this exact gap). That would kill the worker
                // as a side effect of "killing the daemon", not via the watchdog
                // this test exists to exercise, making the positive control right
                // below vacuously true for the wrong reason (CI finding on
                // windows-latest/8.3). `taskkill /F /PID` with no `/T` kills only
                // the named pid, leaving the worker (and its watchdog) alive for
                // the watchdog to notice the daemon's death and act on.
                $null = 'NUL';
                $killer = proc_open(
                    ['taskkill', '/F', '/PID', (string) $daemonPid],
                    [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
                    $killerPipes,
                );
                self::assertIsResource($killer, 'must be able to spawn taskkill to kill the driver ("daemon") alone');
                self::assertSame(0, proc_close($killer), 'taskkill /F /PID <daemonPid> (no /T) must succeed');
            } else {
                self::assertTrue(posix_kill($daemonPid, \SIGKILL), 'must be able to kill -9 the driver ("daemon")');
            }

            // Positive control for the poll itself: the worker pid must still
            // exist at least once right after the kill -- otherwise a broken poll
            // (or a pid reused instantly) would pass this test for free by
            // finding "no process" from the very first check.
            self::assertTrue(
                self::isAlive($workerPid),
                'must fire: the worker must still exist immediately after the kill -- otherwise the poll below is vacuous',
            );

            $pollDeadline = microtime(true) + 8.0;
            $stillAlive = true;
            while (microtime(true) < $pollDeadline) {
                if (!self::isAlive($workerPid)) {
                    $stillAlive = false;
                    break;
                }
                usleep(100_000);
            }
            $elapsed = 8.0 - max(0.0, $pollDeadline - microtime(true));

            self::assertFalse(
                $stillAlive,
                "the standby worker must exit once its daemon is killed -9 mid-call, within a few seconds -- "
                . 'still alive after 8s (#134)',
            );
            self::assertLessThan(
                5.0,
                $elapsed,
                "the standby worker took {$elapsed}s to exit after its daemon was killed -9 mid-call -- "
                . 'expected roughly ORPHAN_POLL_SECONDS, not the full sleep(20) (#134)',
            );
        } finally {
            if ($workerPid !== null && self::isAlive($workerPid)) {
                \Dpt\McpRectorWarm\Support\ProcessTree::killTree($workerPid);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            @proc_terminate($proc, 9);
            proc_close($proc);
            self::removeTree($tmp);
        }
    }

    /**
     * #153: proc_close() has no timeout of its own and blocks unconditionally
     * until the OS reports the child dead. discardProcWorker()'s new bounded
     * wait (awaitProcExitOrDeadline()) must return false, WITHOUT blocking
     * past its own deadline, when the process is genuinely still running --
     * exercised directly, with the process deliberately never signalled at
     * all, so this is deterministic regardless of how fast a real kill
     * resolves on whatever platform the suite runs on.
     */
    public function testAwaitProcExitOrDeadlineReturnsFalseWithoutBlockingPastAnAlreadyPastDeadline(): void
    {
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = proc_open(
            [\PHP_BINARY, '-r', 'sleep(60);'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
        );
        self::assertIsResource($proc);

        $method = new \ReflectionMethod(RectorRunner::class, 'awaitProcExitOrDeadline');

        $start = microtime(true);
        $result = null;
        try {
            self::assertTrue(proc_get_status($proc)['running'], 'control: the process must actually still be running when checked');
            $result = $method->invoke(self::noPcntlRunner(), $proc, hrtime(true) - 1_000_000_000);
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
        }
        $elapsed = microtime(true) - $start;

        self::assertFalse($result, 'must fire: a still-running process past its deadline must report false, never true');
        self::assertLessThan(1.0, $elapsed, "an already-past deadline must not block at all (took {$elapsed}s)");
    }

    /**
     * #153 positive control, paired with the test above: a process that has
     * ALREADY exited by the time awaitProcExitOrDeadline() is called must
     * report true promptly even with a deadline that is itself already in
     * the past -- the negative case above must not be passing merely
     * because nothing is ever checked.
     */
    public function testAwaitProcExitOrDeadlineReturnsTrueOnceTheProcessHasActuallyExited(): void
    {
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = proc_open(
            [\PHP_BINARY, '-r', 'exit(0);'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
        );
        self::assertIsResource($proc);

        $deadline = microtime(true) + 15.0;
        while (proc_get_status($proc)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        self::assertFalse(proc_get_status($proc)['running'], 'control: the process must actually have exited on its own');

        $method = new \ReflectionMethod(RectorRunner::class, 'awaitProcExitOrDeadline');

        $start = microtime(true);
        $result = $method->invoke(self::noPcntlRunner(), $proc, hrtime(true) - 1_000_000_000);
        $elapsed = microtime(true) - $start;
        proc_close($proc);

        self::assertTrue($result, 'must fire: an already-exited process must report true even past an already-past deadline');
        self::assertLessThan(1.0, $elapsed, "an already-exited process must resolve immediately (took {$elapsed}s)");
    }

    /**
     * #153 integration: discardProcWorker() given a deadline still fully
     * cleans up (proc_close()s and unlinks the stderr temp file) a worker
     * that exits promptly -- the bounded path must not regress the ordinary
     * case where nothing is ever mid-boot.
     */
    public function testDiscardProcWorkerWithADeadlineStillCleansUpAWorkerThatExitsPromptly(): void
    {
        $runner = self::noPcntlRunner();
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server);
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = proc_open(
            [\PHP_BINARY, '-r', 'exit(0);'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        $stderrFile = (string) tempnam(sys_get_temp_dir(), 'discard-deadline-control-');

        $property = new \ReflectionProperty(RectorRunner::class, 'procWorker');
        $property->setValue($runner, [
            'proc' => $proc,
            'pid' => (int) proc_get_status($proc)['pid'],
            'server' => $server,
            'socket' => null,
            'token' => str_repeat('a', 32),
            'stderr' => $stderrFile,
            'ready' => false,
        ]);

        $discard = new \ReflectionMethod(RectorRunner::class, 'discardProcWorker');

        $start = microtime(true);
        $discard->invoke($runner, false, hrtime(true) + 5_000_000_000);
        $elapsed = microtime(true) - $start;

        self::assertLessThan(2.0, $elapsed, "a worker that exits promptly must not be held up by the new bounded wait (took {$elapsed}s)");
        self::assertFileDoesNotExist($stderrFile, 'the worker was fully discarded, so its stderr temp file must be removed as before');
    }

    /**
     * #153: the destructor's own new bounded deadline must not block the
     * calling process past it when the standby it discards is still
     * running -- the exact race the issue reports (client closes stdin
     * right as a fresh standby is mid-boot). $tree is false in __destruct(),
     * so this simulates the same shape by installing a still-running
     * process directly and letting the object go out of scope.
     */
    public function testDestructorDoesNotBlockPastItsBoundedDeadlineWhenTheStandbyIsStillRunning(): void
    {
        $runner = self::noPcntlRunner();
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server);
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = proc_open(
            [\PHP_BINARY, '-r', 'sleep(60);'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        $stderrFile = (string) tempnam(sys_get_temp_dir(), 'destruct-deadline-test-');

        $property = new \ReflectionProperty(RectorRunner::class, 'procWorker');
        $property->setValue($runner, [
            'proc' => $proc,
            'pid' => (int) proc_get_status($proc)['pid'],
            'server' => $server,
            'socket' => null,
            'token' => str_repeat('a', 32),
            'stderr' => $stderrFile,
            'ready' => false,
        ]);

        $start = microtime(true);
        unset($runner);
        $elapsed = microtime(true) - $start;

        try {
            // #153 follow-up (audit finding): the bound must clear
            // tests/E2E/stdio_tap.py's own 1.5s EXIT_GRACE, the exact window
            // the flaky harness measures -- not merely be "generous" in the
            // abstract. __destruct() budgets 1.2s combined for both
            // discardProcWorker() and stopRetiredProcWorkers(); 1.5 asserted
            // here (not 1.2) leaves slack for this test's own overhead.
            self::assertLessThan(1.5, $elapsed, "__destruct() must clear the E2E harness's own 1.5s EXIT_GRACE (took {$elapsed}s)");
        } finally {
            if (is_resource($proc)) {
                proc_terminate($proc, 9);
                proc_close($proc);
            }
            @unlink($stderrFile);
        }
    }

    private static function isAlive(int $pid): bool
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return str_contains((string) shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH /FO CSV 2>NUL'), '"' . $pid . '"');
        }
        if (\function_exists('posix_kill')) {
            return posix_kill($pid, 0) || posix_get_last_error() === 1;
        }

        return trim((string) shell_exec('ps -o pid= -p ' . $pid . ' 2>/dev/null')) !== '';
    }

    private static function noPcntlRunner(): RectorRunner
    {
        return new class(120) extends RectorRunner {
            protected function canFork(): bool
            {
                return false;
            }
        };
    }

    /** @return list<string> */
    private static function argv(string $file): array
    {
        return ['rector', 'process', '--output-format=json', '--debug', '--no-progress-bar', '--dry-run', '--', $file];
    }

    /** @param array{output: string} $result */
    private static function diff(array $result): string
    {
        $decoded = json_decode($result['output'], true);
        self::assertIsArray($decoded, 'rector output must be JSON: ' . $result['output']);

        return implode("\n", array_column($decoded['file_diffs'] ?? [], 'diff'));
    }

    private function makeDependencyProject(): string
    {
        $dir = sys_get_temp_dir() . '/rector-standby-' . bin2hex(random_bytes(6));
        mkdir($dir . '/src', 0777, true);
        $this->tmp = $dir;

        file_put_contents($dir . '/src/Dependency.php', self::dependencyClass('int', '1'));
        file_put_contents(
            $dir . '/src/Caller.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\n"
            . "final class Caller\n{\n    public function bar(Dependency \$dependency)\n    {\n        return \$dependency->foo();\n    }\n}\n",
        );
        file_put_contents(
            $dir . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\n"
            . "use Rector\\Config\\RectorConfig;\n"
            . "use Rector\\TypeDeclaration\\Rector\\ClassMethod\\ReturnTypeFromStrictTypedCallRector;\n\n"
            . "return RectorConfig::configure()->withPaths([__DIR__ . '/src'])->withAutoloadPaths([__DIR__ . '/src'])"
            . "->withRules([ReturnTypeFromStrictTypedCallRector::class]);\n",
        );

        chdir($dir);
        $_SERVER['argv'] = ['rector'];

        return $dir;
    }

    private static function dependencyClass(string $returnType, string $returnValue): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\n"
            . "final class Dependency\n{\n    public function foo(): {$returnType}\n    {\n        return {$returnValue};\n    }\n}\n";
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
