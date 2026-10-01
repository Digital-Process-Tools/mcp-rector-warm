<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorRunner;
use PHPUnit\Framework\TestCase;

/**
 * #185/#216: which calls the warm worker may hand to its session child. Only a
 * dry run on exactly one existing file, with no explicit no-session override;
 * a write call (#72), an explicit no-session call (#216 -- LSP code actions
 * and fixWorkspace build a WorkspaceEdit from a dry run, so that specific dry
 * run must not come from the session), and anything else keep forking from
 * the pristine worker. #216: the session is ON unless MCP_RECTOR_WARM_SESSION
 * is explicitly one of 0/off/false/no -- unset means on.
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
        // #216: explicit =1 pins it on regardless of this test process's
        // ambient environment, so each routing test below is about the call,
        // not the switch -- unset would already mean on since #216, but
        // leaving that implicit here would make these tests silently depend
        // on nothing having set MCP_RECTOR_WARM_SESSION=0 in the shell.
        \putenv(RectorRunner::SESSION_ENV . '=1');
    }

    protected function tearDown(): void
    {
        @\unlink($this->file);
        \putenv($this->previousEnv === false ? RectorRunner::SESSION_ENV : RectorRunner::SESSION_ENV . '=' . $this->previousEnv);
    }

    /** @param list<string> $argv */
    private function candidate(array $argv, bool $dryRun, bool $noSession = false): ?string
    {
        $method = new \ReflectionMethod(RectorRunner::class, 'sessionCandidate');

        return $method->invoke(new RectorRunner(), $argv, $dryRun, $noSession);
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

    /**
     * #216: LSP code actions ("Apply all Rector fixes", per-diagnostic fixes)
     * and fixWorkspace build their WorkspaceEdit from a dry run -- that
     * specific dry run must not come from the session, even though it is a
     * dry run on one file that would otherwise be a perfectly good session
     * candidate. Diagnostics alone (noSession: false) keep using the session.
     */
    public function testAnExplicitNoSessionCallNeverGoesToTheSession(): void
    {
        self::assertSame($this->file, $this->candidate($this->argv('--', $this->file), true));
        self::assertNull($this->candidate($this->argv('--', $this->file), true, true));
    }

    public function testADirectoryOrSeveralPathsNeverGoToTheSession(): void
    {
        self::assertNull($this->candidate($this->argv('--', \dirname($this->file)), true));
        self::assertNull($this->candidate($this->argv('--', $this->file, $this->file), true));
        self::assertNull($this->candidate($this->argv($this->file), true), 'no -- separator');
        self::assertNull($this->candidate($this->argv('--', $this->file . '.missing'), true));
    }

    public function testTheSessionIsOnUnlessSwitchedOff(): void
    {
        // #216: default on. Unset (and empty) mean on, same as before #216's
        // own explicit "1"/"on"/"true"/"yes" values and anything unrecognised.
        \putenv(RectorRunner::SESSION_ENV);
        self::assertFalse(RectorRunner::sessionSwitchedOff());
        self::assertSame($this->file, $this->candidate($this->argv('--', $this->file), true));
        foreach (['', '1', 'on', 'true', 'yes', ' ON ', 'maybe'] as $value) {
            \putenv(RectorRunner::SESSION_ENV . '=' . $value);
            self::assertFalse(RectorRunner::sessionSwitchedOff(), $value);
            self::assertSame($this->file, $this->candidate($this->argv('--', $this->file), true), $value);
        }
        // Positive control: only an explicit off token turns it off.
        foreach (['0', 'off', 'false', 'no', ' OFF '] as $value) {
            \putenv(RectorRunner::SESSION_ENV . '=' . $value);
            self::assertTrue(RectorRunner::sessionSwitchedOff(), $value);
            self::assertNull($this->candidate($this->argv('--', $this->file), true), $value);
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
