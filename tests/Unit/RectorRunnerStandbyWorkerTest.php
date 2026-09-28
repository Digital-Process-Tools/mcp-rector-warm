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
            $await->setAccessible(true);
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
