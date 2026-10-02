<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Support\ProtocolStdoutIsolator;
use Dpt\McpRectorWarm\Support\Scalar;
use PHPUnit\Framework\TestCase;

/**
 * #194's gating/decision logic, pinned directly against injected platform
 * facts rather than through the ambient process -- the actual re-exec
 * (reexecIsolated()) spawns a real child process and either exits or blocks
 * on it, so it is covered by the E2E suite instead (tests/E2E/scenarios, a
 * noisy-stdout fixture) and deliberately NOT unit-tested here.
 */
final class ProtocolStdoutIsolatorTest extends TestCase
{
    public function testIsolatesOnlyWhenEveryCapabilityIsPresent(): void
    {
        self::assertTrue(ProtocolStdoutIsolator::shouldIsolate(
            isolatedAlready: false,
            disabled: false,
            isPosix: true,
            hasPcntl: true,
            hasProcOpen: true,
            hasPosixKill: true,
        ));
    }

    public function testNeverIsolatesTwice(): void
    {
        // The re-exec'd child's own environment carries ENV_ISOLATED -- without
        // this, the child would see every capability present and re-exec
        // itself again, forever.
        self::assertFalse(ProtocolStdoutIsolator::shouldIsolate(
            isolatedAlready: true,
            disabled: false,
            isPosix: true,
            hasPcntl: true,
            hasProcOpen: true,
            hasPosixKill: true,
        ));
    }

    public function testTheEscapeHatchDisablesIsolationEvenWhenEverythingIsSupported(): void
    {
        self::assertFalse(ProtocolStdoutIsolator::shouldIsolate(
            isolatedAlready: false,
            disabled: true,
            isPosix: true,
            hasPcntl: true,
            hasProcOpen: true,
            hasPosixKill: true,
        ));
    }

    public function testNeverIsolatesOnWindows(): void
    {
        // No pcntl on Windows at all, so RectorRunner::canFork() is always
        // false there and the bug this exists for cannot occur (#194) -- but
        // this is pinned independently of that fact, as its own platform gate.
        self::assertFalse(ProtocolStdoutIsolator::shouldIsolate(
            isolatedAlready: false,
            disabled: false,
            isPosix: false,
            hasPcntl: true,
            hasProcOpen: true,
            hasPosixKill: true,
        ));
    }

    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function missingCapabilityProvider(): iterable
    {
        yield 'no pcntl' => [false, true, true];
        yield 'no proc_open' => [true, false, true];
        yield 'no posix_kill' => [true, true, false];
    }

    /**
     * @dataProvider missingCapabilityProvider
     */
    public function testNeverIsolatesWithoutEveryRequiredCapability(
        bool $hasPcntl,
        bool $hasProcOpen,
        bool $hasPosixKill,
    ): void {
        self::assertFalse(ProtocolStdoutIsolator::shouldIsolate(
            isolatedAlready: false,
            disabled: false,
            isPosix: true,
            hasPcntl: $hasPcntl,
            hasProcOpen: $hasProcOpen,
            hasPosixKill: $hasPosixKill,
        ));
    }

    public function testIsAlreadyIsolatedChildReadsTheRealEnvironment(): void
    {
        $previous = getenv(ProtocolStdoutIsolator::ENV_ISOLATED);
        try {
            putenv(ProtocolStdoutIsolator::ENV_ISOLATED . '=1');
            self::assertTrue(ProtocolStdoutIsolator::isAlreadyIsolatedChild());

            putenv(ProtocolStdoutIsolator::ENV_ISOLATED . '=0');
            self::assertFalse(
                ProtocolStdoutIsolator::isAlreadyIsolatedChild(),
                '"0" must read as off, the same convention MCP_RECTOR_WARM_SESSION uses',
            );

            putenv(ProtocolStdoutIsolator::ENV_ISOLATED); // unset
            self::assertFalse(ProtocolStdoutIsolator::isAlreadyIsolatedChild());
        } finally {
            if ($previous === false) {
                putenv(ProtocolStdoutIsolator::ENV_ISOLATED);
            } else {
                putenv(ProtocolStdoutIsolator::ENV_ISOLATED . '=' . $previous);
            }
        }
    }

    public function testProtocolStreamIsTheRealStdoutWhenNotIsolated(): void
    {
        $previous = getenv(ProtocolStdoutIsolator::ENV_ISOLATED);
        try {
            putenv(ProtocolStdoutIsolator::ENV_ISOLATED); // unset
            self::assertSame(\STDOUT, ProtocolStdoutIsolator::protocolStream());
        } finally {
            if ($previous === false) {
                putenv(ProtocolStdoutIsolator::ENV_ISOLATED);
            } else {
                putenv(ProtocolStdoutIsolator::ENV_ISOLATED . '=' . $previous);
            }
        }
    }

    /**
     * Positive control for the next test: when the process is not the
     * isolated child, protocolStream() must not log anything -- otherwise
     * the "must warn" assertion below would also pass against a harness
     * that never writes to STDERR at all.
     */
    public function testProtocolStreamLogsNothingWhenNotIsolated(): void
    {
        $previous = getenv(ProtocolStdoutIsolator::ENV_ISOLATED);
        try {
            putenv(ProtocolStdoutIsolator::ENV_ISOLATED); // unset
            $stderr = self::captureStderrDuring(
                static function (): void {
                    ProtocolStdoutIsolator::protocolStream();
                },
            );
            self::assertSame('', $stderr);
        } finally {
            if ($previous === false) {
                putenv(ProtocolStdoutIsolator::ENV_ISOLATED);
            } else {
                putenv(ProtocolStdoutIsolator::ENV_ISOLATED . '=' . $previous);
            }
        }
    }

    /**
     * When this process claims to be the isolated re-exec'd child
     * (ENV_ISOLATED set) but fd 3 is not actually open -- the ordinary case
     * for a plain PHP process that was never re-exec'd by reexecIsolated()
     * -- protocolStream() must fall back to the real STDOUT rather than
     * return a broken stream, and must warn on STDERR so an operator can
     * tell this apart from isolation never having engaged (lines 231-233).
     *
     * "fd 3 is gone" is simulated by swapping out the built-in "php"
     * stream wrapper for the duration of the call, rather than by actually
     * closing an OS file descriptor: this process's own ambient fd table is
     * not something a unit test controls (CI sandboxes, local shells and
     * even this repo's own tooling can each leave unrelated descriptors
     * open at index 3), so asserting against the real fd table would make
     * this test's outcome depend on the environment it runs in rather than
     * on protocolStream() itself.
     */
    public function testProtocolStreamFallsBackToStdoutAndWarnsWhenFd3IsGone(): void
    {
        $previous = getenv(ProtocolStdoutIsolator::ENV_ISOLATED);
        try {
            putenv(ProtocolStdoutIsolator::ENV_ISOLATED . '=1');
            self::assertTrue(ProtocolStdoutIsolator::isAlreadyIsolatedChild());

            $stream = null;
            $stderr = self::captureStderrDuring(
                static function () use (&$stream): void {
                    $stream = self::withFd3UnavailableDuring(
                        static fn() => ProtocolStdoutIsolator::protocolStream(),
                    );
                },
            );

            self::assertSame(\STDOUT, $stream, 'must still fall back to the real STDOUT');
            self::assertStringContainsString(
                "fd isolation's own fd 3 is gone",
                $stderr,
                'the fallback must warn, not silently swap streams',
            );
        } finally {
            if ($previous === false) {
                putenv(ProtocolStdoutIsolator::ENV_ISOLATED);
            } else {
                putenv(ProtocolStdoutIsolator::ENV_ISOLATED . '=' . $previous);
            }
        }
    }

    /**
     * Runs $callback with every "php://..." open (including "php://fd/3")
     * failing, as if the resource did not exist, then restores the real
     * built-in wrapper -- even if $callback throws.
     *
     * @param callable(): mixed $callback
     */
    private static function withFd3UnavailableDuring(callable $callback): mixed
    {
        self::assertTrue(
            \stream_wrapper_unregister('php'),
            'failed to unregister the built-in "php" stream wrapper',
        );
        try {
            self::assertTrue(
                \stream_wrapper_register('php', AlwaysFailingPhpWrapper::class),
                'failed to install the simulated "php" stream wrapper',
            );

            // Positive control: confirm the override is actually live before
            // trusting what it measures -- a silently no-op override would
            // let fopen() fall through to the real wrapper, and the whole
            // point of this helper is to be independent of whatever this
            // process's ambient fd table happens to look like.
            self::assertFalse(
                \is_resource(@\fopen('php://memory', 'r')),
                'the simulated wrapper must fail every php:// open, including this canary',
            );

            return $callback();
        } finally {
            \stream_wrapper_restore('php');
        }
    }

    /**
     * Runs $callback with a filter attached to the real \STDERR constant
     * that records every byte written through it (passing them through
     * unchanged), and returns what was captured.
     *
     * @param callable(): mixed $callback
     */
    private static function captureStderrDuring(callable $callback): string
    {
        if (!\in_array('oss273CaptureStderr', \stream_get_filters(), true)) {
            \stream_filter_register('oss273CaptureStderr', StderrCaptureFilter::class);
        }

        StderrCaptureFilter::$captured = '';
        $handle = \stream_filter_append(\STDERR, 'oss273CaptureStderr', \STREAM_FILTER_WRITE);
        self::assertIsResource($handle);

        try {
            $callback();
        } finally {
            \stream_filter_remove($handle);
        }

        return StderrCaptureFilter::$captured;
    }
}

/**
 * Makes every "php://" stream open fail, used to simulate "fd 3 is not
 * open" deterministically rather than depending on this process's real
 * (environment-dependent) file descriptor table. See
 * ProtocolStdoutIsolatorTest::withFd3UnavailableDuring().
 */
final class AlwaysFailingPhpWrapper
{
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}

/**
 * Records every byte written through a stream it is attached to, passing
 * them through unchanged. See
 * ProtocolStdoutIsolatorTest::captureStderrDuring().
 */
final class StderrCaptureFilter extends \php_user_filter
{
    public static string $captured = '';

    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     * @param-out int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while (($bucket = \stream_bucket_make_writeable($in)) !== null) {
            self::$captured .= Scalar::toString($bucket->data, 'stream bucket data');
            $consumed += Scalar::toInt($bucket->datalen, 'stream bucket datalen');
            \stream_bucket_append($out, $bucket);
        }

        return \PSFS_PASS_ON;
    }
}
