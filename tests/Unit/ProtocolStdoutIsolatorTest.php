<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Support\ProtocolStdoutIsolator;
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
}
