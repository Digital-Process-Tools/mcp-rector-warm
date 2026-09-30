<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorRunner;
use PHPUnit\Framework\TestCase;

/**
 * #185: which calls the warm worker may hand to its session child. Only a dry
 * run on exactly one existing file; a write call (#72) and anything else keep
 * forking from the pristine worker, and MCP_RECTOR_WARM_SESSION=0 turns the
 * session off entirely.
 */
final class RectorRunnerSessionTest extends TestCase
{
    private string $file;

    private string|false $previousEnv;

    protected function setUp(): void
    {
        $this->file = \sys_get_temp_dir() . '/runner-session-' . \bin2hex(\random_bytes(6)) . '.php';
        \file_put_contents($this->file, '<?php class RunnerSessionFixture {}');
        $this->previousEnv = \getenv(RectorRunner::SESSION_ENV);
        // Switched on (it is opt-in), so each routing test below is about the call, not the switch.
        \putenv(RectorRunner::SESSION_ENV . '=1');
    }

    protected function tearDown(): void
    {
        @\unlink($this->file);
        \putenv($this->previousEnv === false ? RectorRunner::SESSION_ENV : RectorRunner::SESSION_ENV . '=' . $this->previousEnv);
    }

    /** @param list<string> $argv */
    private function candidate(array $argv, bool $dryRun): ?string
    {
        $method = new \ReflectionMethod(RectorRunner::class, 'sessionCandidate');

        return $method->invoke(new RectorRunner(), $argv, $dryRun);
    }

    /** @return list<string> */
    private function argv(string ...$tail): array
    {
        return ['rector', 'process', '--output-format=json', '--debug', '--no-progress-bar', '--dry-run', ...$tail];
    }

    public function testADryRunOnOneFileGoesToTheSession(): void
    {
        self::assertSame($this->file, $this->candidate($this->argv('--', $this->file), true));
    }

    public function testABufferCopyGoesToTheSessionWithoutItsSkipAsOption(): void
    {
        $argv = $this->argv(RectorRunner::SKIP_AS_OPTION . '=/p/src/Foo.php', '--', $this->file);

        self::assertSame($this->file, $this->candidate($argv, true));
    }

    public function testAWriteCallNeverGoesToTheSession(): void
    {
        self::assertNull($this->candidate(['rector', 'process', '--output-format=json', '--', $this->file], false));
    }

    public function testADirectoryOrSeveralPathsNeverGoToTheSession(): void
    {
        self::assertNull($this->candidate($this->argv('--', \dirname($this->file)), true));
        self::assertNull($this->candidate($this->argv('--', $this->file, $this->file), true));
        self::assertNull($this->candidate($this->argv($this->file), true), 'no -- separator');
        self::assertNull($this->candidate($this->argv('--', $this->file . '.missing'), true));
    }

    public function testTheSessionIsOffUnlessSwitchedOn(): void
    {
        // PR #189 review: opt-in. Unset, empty, 0 and anything unrecognised keep it off.
        \putenv(RectorRunner::SESSION_ENV);
        self::assertTrue(RectorRunner::sessionSwitchedOff());
        self::assertNull($this->candidate($this->argv('--', $this->file), true));
        foreach (['', '0', 'off', 'false', 'no', 'maybe'] as $value) {
            \putenv(RectorRunner::SESSION_ENV . '=' . $value);
            self::assertTrue(RectorRunner::sessionSwitchedOff(), $value);
            self::assertNull($this->candidate($this->argv('--', $this->file), true), $value);
        }
        // Positive control: switched on, the same call goes to the session.
        foreach (['1', 'on', 'true', 'yes', ' ON '] as $value) {
            \putenv(RectorRunner::SESSION_ENV . '=' . $value);
            self::assertFalse(RectorRunner::sessionSwitchedOff(), $value);
            self::assertSame($this->file, $this->candidate($this->argv('--', $this->file), true), $value);
        }
    }

    public function testWatchPathsAreSplitOnPathSeparatorAndResolvedAgainstTheProject(): void
    {
        $previous = \getenv(RectorRunner::SESSION_WATCH_ENV);
        \putenv(RectorRunner::SESSION_WATCH_ENV . '=config' . \PATH_SEPARATOR . ' ' . \PATH_SEPARATOR . '/abs/templates/');
        try {
            self::assertSame(['/p/config', '/abs/templates'], RectorRunner::sessionWatchPaths('/p'));
            \putenv(RectorRunner::SESSION_WATCH_ENV);
            self::assertSame([], RectorRunner::sessionWatchPaths('/p'));
        } finally {
            \putenv($previous === false ? RectorRunner::SESSION_WATCH_ENV : RectorRunner::SESSION_WATCH_ENV . '=' . $previous);
        }
    }

    public function testTheSessionRetiresOnItsOwnMemoryOrCallCountWhateverMemoryLimitSays(): void
    {
        $mb = 1024 * 1024;
        // Must not fire: under both caps, and memory_limit -1 as both bins set it.
        self::assertNull(RectorRunner::sessionRetireReason(10, 100 * $mb, '-1', 512, 250));
        // Must fire: over its own memory cap although memory_limit is unlimited.
        self::assertStringContainsString('MB', (string) RectorRunner::sessionRetireReason(10, 600 * $mb, '-1', 512, 250));
        // Must fire: after the configured number of calls.
        self::assertStringContainsString('250 calls', (string) RectorRunner::sessionRetireReason(250, 100 * $mb, '-1', 512, 250));
        // Still honoured: 75% of a finite memory_limit.
        self::assertNotNull(RectorRunner::sessionRetireReason(1, 200 * $mb, '256M', 512, 250));
        self::assertNull(RectorRunner::sessionRetireReason(1, 100 * $mb, '256M', 512, 250));
    }
}
