<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorRunner;
use PHPUnit\Framework\TestCase;

/**
 * A withBootstrapFiles() file edited WHILE a container boots (#108 review). Rector
 * requires it during createFromBootstrapConfigs(), but its hash was only taken after
 * the boot, so an edit landing in between was recorded as the version the container
 * holds: no reboot, and a stale container served the next call (observed: a silent
 * 0-change where cold reports 1). The no-pcntl standby boots in the background after
 * every call, which reopens this window after every call; the fork path has it on
 * every boot.
 *
 * Deterministic: the bootstrap file rewrites ITSELF the Nth time it is included --
 * after being read, before the hash. The paired control rewrites a file that is not
 * a bootstrap file, so a reboot there would be a false positive.
 */
final class RectorRunnerBootstrapRaceTest extends TestCase
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

    public function testAStandbyBootstrapFileEditedDuringItsBootForcesAReboot(): void
    {
        // Boot 1 serves call 1; boot 2 is the standby that serves call 2.
        self::assertSame([true, false], $this->twoCalls(self::noPcntlRunner(), editOnBoot: 2, editBootstrapItself: true));
    }

    public function testAStandbyWhoseNonBootstrapFileChangedStaysWarm(): void
    {
        self::assertSame([true, true], $this->twoCalls(self::noPcntlRunner(), editOnBoot: 2, editBootstrapItself: false));
    }

    public function testAForkedWorkerBootstrapFileEditedDuringItsBootForcesAReboot(): void
    {
        self::skipWithoutFork();
        // Boot 1 is the worker that serves both calls.
        self::assertSame([true, false], $this->twoCalls(new RectorRunner(120), editOnBoot: 1, editBootstrapItself: true));
    }

    public function testAForkedWorkerWhoseNonBootstrapFileChangedStaysWarm(): void
    {
        self::skipWithoutFork();
        self::assertSame([true, true], $this->twoCalls(new RectorRunner(120), editOnBoot: 1, editBootstrapItself: false));
    }

    /**
     * @return array{0: bool, 1: bool} [the edit happened (must fire), call 2's warm_boot]
     */
    private function twoCalls(RectorRunner $runner, int $editOnBoot, bool $editBootstrapItself): array
    {
        $dir = sys_get_temp_dir() . '/rector-bootstrap-race-' . bin2hex(random_bytes(6));
        mkdir($dir . '/src', 0777, true);
        $this->tmp = $dir;
        $target = $editBootstrapItself ? '__FILE__' : var_export($dir . '/unrelated.txt', true);
        file_put_contents($dir . '/unrelated.txt', "x\n");
        file_put_contents($dir . '/bootstrap.php', "<?php\n"
            . "if (!defined('BOOTSTRAP_RACE_COUNTED')) {\n"
            . "    define('BOOTSTRAP_RACE_COUNTED', true);\n"
            . "    \$n = (int) @file_get_contents(__DIR__ . '/boots.txt') + 1;\n"
            . "    file_put_contents(__DIR__ . '/boots.txt', (string) \$n);\n"
            . "    if (\$n === {$editOnBoot}) {\n"
            . "        file_put_contents({$target}, file_get_contents({$target}) . \"\\n// edited during boot\\n\");\n"
            . "        file_put_contents(__DIR__ . '/edited.txt', 'yes');\n"
            . "    }\n"
            . "}\n");
        file_put_contents($dir . '/src/Foo.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class Foo\n{\n}\n");
        file_put_contents($dir . '/rector.php', "<?php\n\ndeclare(strict_types=1);\n\n"
            . "use Rector\\Config\\RectorConfig;\n"
            . "use Rector\\Php82\\Rector\\Class_\\ReadOnlyClassRector;\n\n"
            . "return RectorConfig::configure()->withPaths([__DIR__ . '/src'])"
            . "->withBootstrapFiles([__DIR__ . '/bootstrap.php'])"
            . "->withRules([ReadOnlyClassRector::class]);\n");
        // Written in the past, so "modified since the boot started" can only mean
        // the edit under test, never the test's own setup.
        foreach (['/bootstrap.php', '/rector.php', '/src/Foo.php', '/unrelated.txt'] as $file) {
            touch($dir . $file, time() - 30);
        }
        chdir($dir);
        $_SERVER['argv'] = ['rector'];

        $argv = ['rector', 'process', '--output-format=json', '--debug', '--no-progress-bar', '--dry-run', '--', $dir . '/src/Foo.php'];
        try {
            $runner->run($argv);
            // The standby (no pcntl) boots in the background: let it finish before
            // call 2, so the edit is certain to have happened by then.
            if ($editOnBoot === 2) {
                $deadline = microtime(true) + 60;
                while (!is_file($dir . '/edited.txt') && microtime(true) < $deadline) {
                    usleep(100_000);
                }
            }
            $second = $runner->run($argv);
        } finally {
            $runner->reboot();
        }

        return [is_file($dir . '/edited.txt'), $second['warm_boot']];
    }

    private static function skipWithoutFork(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid') || !\function_exists('stream_socket_pair')) {
            self::markTestSkipped('the forked-worker path needs pcntl');
        }
    }

    private static function noPcntlRunner(): RectorRunner
    {
        return new class (120) extends RectorRunner {
            protected function canFork(): bool
            {
                return false;
            }
        };
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
