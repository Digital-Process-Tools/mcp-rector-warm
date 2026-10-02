<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Tests\Support\TempPath;
use PHPUnit\Framework\TestCase;

/**
 * Covers bin/rector-cold-call.php directly, by spawning the real script in a
 * real `php` subprocess with hand-crafted stdin -- the only way to reach its
 * defensive guard clauses (#274, part of #245's coverage batch).
 *
 * RectorRunner::runCold() (src/RectorRunner.php) always builds a well-formed
 * request ('result_file' and 'call_argv' always present, stdin always valid
 * JSON), so none of these branches are reachable through the public
 * RectorRunner API at all -- every existing test that exercises the cold path
 * (RectorRunnerTest::testColdCallIsKilledAtItsDeadlineWithoutPcntl() and
 * neighbours) goes through that well-formed request and can never reach them
 * either. This file bypasses RectorRunner entirely and talks to the script
 * the same way runCold() itself does (proc_open() + a stdin pipe), but with
 * deliberately malformed or partial requests.
 *
 * Lines 1-2 of bin/rector-cold-call.php (the `#!/usr/bin/env php` shebang and
 * the opening `<?php` tag) are NOT exercised anywhere in this file: they sit
 * above the first executable statement, so no test -- in this file or any
 * other -- can ever reach them. That is the two lines of #274's 13 Codecov
 * misses this coverage batch leaves unaddressed, by design.
 */
final class RectorColdCallScriptTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $request null means "write literally
     *   nothing to stdin", to pin the empty-stdin arm of the guard separately
     *   from the malformed-JSON arm.
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private static function runColdCallScript(?array $request, ?string $cwd = null): array
    {
        $scriptPath = \dirname(__DIR__, 2) . '/bin/rector-cold-call.php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = \proc_open([\PHP_BINARY, $scriptPath], $descriptors, $pipes, $cwd);
        self::assertIsResource($process, 'could not spawn bin/rector-cold-call.php');

        $stdin = $request === null ? '' : (string) \json_encode($request);
        \fwrite($pipes[0], $stdin);
        \fclose($pipes[0]);

        // The script's own output here is always a single short JSON line or
        // error message -- small enough that reading stdout then stderr in
        // sequence cannot deadlock the way #45 describes for a real Rector
        // run's console output.
        $stdout = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        $exitCode = \proc_close($process);

        return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Covers lines 42-44: stdin that is not valid JSON (or empty) must refuse
     * on stderr with exit 1, before any request field is even looked at.
     * Two "must fire" arms (empty stdin, malformed JSON) rather than one,
     * since either could pass by accident if the guard mis-handled only the
     * other.
     */
    public function testMissingOrInvalidJsonRequestOnStdinRefusesWithExitOne(): void
    {
        $empty = self::runColdCallScript(null);
        self::assertSame(1, $empty['exitCode']);
        self::assertStringContainsString(
            'rector-cold-call: invalid or missing JSON request on stdin',
            $empty['stderr'],
        );
        self::assertSame('', $empty['stdout']);

        $scriptPath = \dirname(__DIR__, 2) . '/bin/rector-cold-call.php';
        $process = \proc_open(
            [\PHP_BINARY, $scriptPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        \fwrite($pipes[0], 'this is not json');
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        $exitCode = \proc_close($process);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'rector-cold-call: invalid or missing JSON request on stdin',
            $stderr,
        );
        self::assertSame('', $stdout);
    }

    /**
     * Covers lines 49 ($resultFile falls back to null when the request omits
     * 'result_file'), 60-61 (writeColdResult() with a null $resultFile writes
     * to stderr instead of a file), 79 ($callArgv falls back to [] when the
     * request omits 'call_argv'), and 87-91 (the catch block: a config that
     * loads but registers no rules throws a \RuntimeException inside
     * runOnceInThisProcess(), which this request's empty $callArgv reaches).
     *
     * Runs against an isolated temp rector.php with zero rules registered --
     * never against this repo's own rector.php -- so there is no risk of the
     * real cold subprocess actually running Rector::process() against this
     * checkout's own files.
     */
    public function testRequestMissingResultFileAndCallArgvFallsBackAndReportsTheCaughtError(): void
    {
        $tmp = \sys_get_temp_dir() . '/rector-cold-call-script-test-' . \bin2hex(\random_bytes(8));
        \mkdir($tmp);
        \file_put_contents(
            $tmp . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\Config\RectorConfig;\n\n"
            . "return RectorConfig::configure();\n",
        );

        try {
            $result = self::runColdCallScript([], $tmp);

            self::assertSame(1, $result['exitCode']);
            self::assertSame(
                '',
                $result['stdout'],
                'the result must never be written to stdout (#46) -- only stderr, since no result_file was given',
            );
            self::assertStringContainsString('"error"', $result['stderr']);
            self::assertStringContainsString('"error_class":"RuntimeException"', $result['stderr']);
            self::assertStringContainsString('registers no rules', $result['stderr']);
        } finally {
            TempPath::unlink($tmp . '/rector.php');
            TempPath::rmdir($tmp);
        }
    }
}
