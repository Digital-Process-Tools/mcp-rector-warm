<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorRunner;
use PHPUnit\Framework\TestCase;

/**
 * #223: RectorRunnerSessionTest pins sessionCandidate() via reflection, in
 * isolation from a real call -- the issue's own claim is that nothing
 * exercises the REAL wire: run() -> runForked()'s JSON payload
 * (RectorRunner.php:750, the exact line the issue names) -> the frame bytes
 * the worker actually decodes (RectorRunner.php:698). These boot a real
 * forked worker (same pcntl-gated harness as RectorRunnerTest's
 * testRunThrowsWhenZeroRulesRegistered / testDeadWorkerSelfHealsOnNextCall)
 * and tap the real workerSocket with a stream filter to capture the literal
 * bytes run() writes for an explicit noSession:true call, then separately
 * prove the call is never served by a session that has already gone stale.
 */
final class RectorRunnerNoSessionFrameTest extends TestCase
{
    private function requirePcntl(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid') || !\function_exists('stream_socket_pair')) {
            self::markTestSkipped('pcntl_fork/pcntl_waitpid/stream_socket_pair unavailable in this environment (e.g. Windows)');
        }
        if (!\in_array('rector223_frame_capture', \stream_get_filters(), true)) {
            \stream_filter_register('rector223_frame_capture', RectorRunnerFrameCaptureFilter::class);
        }
    }

    /** @return array{string, list<string>} tmp dir, argv for a single-file dry run on it */
    private function zeroRuleProject(): array
    {
        $tmp = \sys_get_temp_dir() . '/rector-runner-nosession-frame-' . \bin2hex(\random_bytes(8));
        \mkdir($tmp);
        \file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\nreturn RectorConfig::configure();\n",
        );
        \file_put_contents($tmp . '/Sample.php', "<?php\n\nclass Sample\n{\n}\n");

        return [$tmp, ['rector', 'process', '--output-format=json', '--debug', '--no-progress-bar', '--dry-run', '--', $tmp . '/Sample.php']];
    }

    private function captureFrame(RectorRunner $runner, array $argv, bool $dryRun, bool $noSession): array
    {
        try {
            $runner->run($argv, $dryRun, $noSession);
        } catch (\RuntimeException) {
            // expected for the zero-rule fixture: execute() throws AFTER the
            // worker already decoded this call's frame.
        }

        $socketProperty = new \ReflectionProperty(RectorRunner::class, 'workerSocket');
        $socket = $socketProperty->getValue($runner);
        self::assertIsResource($socket, 'a real worker socket must exist after a forked call');

        RectorRunnerFrameCaptureFilter::$captured = [];
        $filter = \stream_filter_append($socket, 'rector223_frame_capture', \STREAM_FILTER_WRITE);
        self::assertNotFalse($filter);

        try {
            $runner->run($argv, $dryRun, $noSession);
        } catch (\RuntimeException) {
        } finally {
            \stream_filter_remove($filter);
        }

        self::assertNotEmpty(
            RectorRunnerFrameCaptureFilter::$captured,
            'expected the request frame to actually be written to the worker socket',
        );
        $raw = \implode('', RectorRunnerFrameCaptureFilter::$captured);
        // writeFrame() prepends a 4-byte big-endian length (pack('N', ...))
        // before the JSON payload -- strip it to get at the real bytes run()
        // sent to the worker for this call.
        $decoded = \json_decode(\substr($raw, 4), true);
        self::assertIsArray($decoded, $raw);

        return $decoded;
    }

    public function testExplicitNoSessionCallPutsItInTheRealWireFrame(): void
    {
        $this->requirePcntl();
        [$tmp, $argv] = $this->zeroRuleProject();
        $previousCwd = \getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];
        $previousSessionEnv = \getenv(RectorRunner::SESSION_ENV);
        \putenv(RectorRunner::SESSION_ENV . '=1');
        \chdir($tmp);
        $_SERVER['argv'] = ['rector'];
        $runner = new RectorRunner();

        try {
            $decoded = $this->captureFrame($runner, $argv, true, true);

            self::assertArrayHasKey('no_session', $decoded);
            self::assertTrue(
                $decoded['no_session'],
                'the literal wire frame run() sends to the worker must carry no_session:true for an explicit noSession:true call',
            );
        } finally {
            $runner->reboot();
            \chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            \putenv($previousSessionEnv === false ? RectorRunner::SESSION_ENV : RectorRunner::SESSION_ENV . '=' . $previousSessionEnv);
            @\unlink($tmp . '/Sample.php');
            @\unlink($tmp . '/rector.php');
            @\rmdir($tmp);
        }
    }

    /**
     * Must-fire positive control (CLAUDE.md: a must-not-fire assertion needs
     * a must-fire sibling): a plain dry run (noSession:false) must NOT carry
     * no_session:true on the wire -- proving the field above reflects the
     * ACTUAL call made, not a constant the filter always happens to see.
     */
    public function testAPlainDryRunDoesNotCarryNoSessionOnTheWire(): void
    {
        $this->requirePcntl();
        [$tmp, $argv] = $this->zeroRuleProject();
        $previousCwd = \getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];
        $previousSessionEnv = \getenv(RectorRunner::SESSION_ENV);
        \putenv(RectorRunner::SESSION_ENV . '=1');
        \chdir($tmp);
        $_SERVER['argv'] = ['rector'];
        $runner = new RectorRunner();

        try {
            $decoded = $this->captureFrame($runner, $argv, true, false);

            self::assertArrayHasKey('no_session', $decoded);
            self::assertFalse($decoded['no_session']);
        } finally {
            $runner->reboot();
            \chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            \putenv($previousSessionEnv === false ? RectorRunner::SESSION_ENV : RectorRunner::SESSION_ENV . '=' . $previousSessionEnv);
            @\unlink($tmp . '/Sample.php');
            @\unlink($tmp . '/rector.php');
            @\rmdir($tmp);
        }
    }

    /**
     * #223's second half: "must not be served by the session". A plain dry
     * run (noSession:false) primes the session child, which caches the
     * custom rule's own data file (file_get_contents, never tracked by the
     * session's parser-hook directory watch -- docs/how-it-works.md §1b,
     * the same documented limit tests/E2E/test_warm_session_watch.py's
     * MapStringsFromJsonRector fixture pins). After the data file changes,
     * an explicit noSession:true call must still reflect the EDIT (proving
     * it forked fresh rather than reusing the session's stale answer); the
     * must-fire positive control is the THIRD call, still noSession:false,
     * which is still served by the very same (never-touched, still stale)
     * session and so must still return the OLD cached answer -- proving the
     * session really was primed and really would have answered wrong here,
     * not merely untouched.
     */
    public function testAnExplicitNoSessionCallIsNeverServedByAStaleSession(): void
    {
        $this->requirePcntl();
        $tmp = \sys_get_temp_dir() . '/rector-runner-nosession-stale-' . \bin2hex(\random_bytes(8));
        \mkdir($tmp);
        \mkdir($tmp . '/src');
        // map.json lives OUTSIDE withPaths([__DIR__ . '/src']) below -- same
        // layout as test_warm_session_watch.py's own fixture (config/map.json
        // outside src/). Inside it, editing map.json would also change the
        // directory LISTING of an analysed path, which the session's own
        // (unrelated) directory-snapshot staleness check already catches on
        // its own -- defeating this test's whole point, which is the
        // UNDECLARED file_get_contents() read a directory listing can never
        // see.
        \file_put_contents($tmp . '/map.json', '{"hello":"world"}');
        \file_put_contents(
            $tmp . '/rector.php',
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use PhpParser\Node;
            use PhpParser\Node\Scalar\String_;
            use Rector\Config\RectorConfig;
            use Rector\Rector\AbstractRector;

            final class MapStringsFromJsonRector223 extends AbstractRector
            {
                private static ?array $map = null;

                public function getNodeTypes(): array
                {
                    return [String_::class];
                }

                public function refactor(Node $node): ?Node
                {
                    self::$map ??= json_decode((string) file_get_contents(__DIR__ . '/map.json'), true);
                    $to = self::$map[$node->value] ?? null;

                    return is_string($to) && $to !== $node->value ? new String_($to) : null;
                }
            }

            return RectorConfig::configure()
                ->withPaths([__DIR__ . '/src'])
                ->withAutoloadPaths([__DIR__ . '/src'])
                ->withRules([MapStringsFromJsonRector223::class]);
            PHP,
        );
        \file_put_contents(
            $tmp . '/src/Sample.php',
            "<?php\n\nfinal class SampleNoSessionFrame\n{\n    public function label(): string\n    {\n        return 'hello';\n    }\n}\n",
        );
        $argv = ['rector', 'process', '--output-format=json', '--debug', '--no-progress-bar', '--dry-run', '--', $tmp . '/src/Sample.php'];

        $previousCwd = \getcwd();
        $previousArgv = $_SERVER['argv'] ?? ['rector'];
        $previousSessionEnv = \getenv(RectorRunner::SESSION_ENV);
        \putenv(RectorRunner::SESSION_ENV . '=1');
        \chdir($tmp);
        $_SERVER['argv'] = ['rector'];
        $runner = new RectorRunner();

        try {
            // Call 1: noSession:false -- warms the worker AND the session,
            // which caches the custom rule's static with map.json's ORIGINAL
            // content.
            $result1 = $runner->run($argv, true, false);
            self::assertStringContainsString("'world'", $result1['output'], $result1['output']);

            // Go stale: edit the custom rule's own data file -- never tracked
            // by the session's own directory watch.
            \file_put_contents($tmp . '/map.json', '{"hello":"there"}');
            \touch($tmp . '/map.json', \time() + 10);

            // Call 2: noSession:true -- must fork fresh from the pristine
            // worker and so must reflect the EDIT, never the session's stale
            // cached answer.
            $result2 = $runner->run($argv, true, true);
            self::assertStringContainsString("'there'", $result2['output'], $result2['output']);
            self::assertStringNotContainsString("'world'", $result2['output'], $result2['output']);

            // Call 3 (must-fire positive control): noSession:false again,
            // same still-alive session from call 1 -- it never saw the edit
            // (call 2 forked past it), so it is STILL stale and must still
            // answer 'world'. If this came back 'there' instead, the session
            // was never actually exercised by this test at all, and call 2's
            // "not stale" result would prove nothing about noSession:true.
            $result3 = $runner->run($argv, true, false);
            self::assertStringContainsString("'world'", $result3['output'], $result3['output']);
        } finally {
            $runner->reboot();
            \chdir($previousCwd);
            $_SERVER['argv'] = $previousArgv;
            \putenv($previousSessionEnv === false ? RectorRunner::SESSION_ENV : RectorRunner::SESSION_ENV . '=' . $previousSessionEnv);
            @\unlink($tmp . '/src/Sample.php');
            @\rmdir($tmp . '/src');
            @\unlink($tmp . '/rector.php');
            @\unlink($tmp . '/map.json');
            @\rmdir($tmp);
        }
    }
}

/**
 * #223 test seam: captures every chunk written through it, unchanged, so a
 * test can inspect the literal bytes writeFrame() sends over a real socket
 * without needing to be the other end reading them.
 */
final class RectorRunnerFrameCaptureFilter extends \php_user_filter
{
    /** @var list<string> */
    public static array $captured = [];

    /** @param int $consumed */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while (($bucket = \stream_bucket_make_writeable($in)) !== null) {
            self::$captured[] = $bucket->data;
            $consumed += (int) $bucket->datalen;
            \stream_bucket_append($out, $bucket);
        }

        return \PSFS_PASS_ON;
    }
}
