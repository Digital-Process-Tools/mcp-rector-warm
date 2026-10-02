<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Integration;

use Dpt\McpRectorWarm\Tests\Support\Json;
use Dpt\McpRectorWarm\RectorRunner;
use PHPUnit\Framework\TestCase;

/**
 * Spawns bin/mcp-rector-warm as a subprocess, feeds JSON-RPC on stdin, asserts responses.
 * Covers the real boot path (autoload + scoper-autoload + Rector container).
 */
final class ServerStdioTest extends TestCase
{
    private static string $bin;
    private static string $fixtureDir;
    private static string $fixtureFile;

    /** @var list<string> temp project dirs created per test, removed in tearDown */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tmpDirs = [];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            self::assertInstanceOf(\SplFileInfo::class, $item);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * True when the server's second call is expected to report warm_boot: with pcntl
     * (the forked worker), and since #108 also without it (a pre-booted standby worker
     * process). Only the escape hatch MCP_RECTOR_WARM_NO_PCNTL=cold on a no-pcntl PHP
     * brings back a cold subprocess per call, and with it warm_boot false every time.
     * The server subprocess inherits this process's environment, so the env var read
     * here is the one it sees.
     *
     * #70: reproducing the CI `no-pcntl` leg locally with `php -d
     * disable_functions=... vendor/bin/phpunit` does NOT reproduce this gate going
     * false. `-d` only changes the OUTER phpunit process's ini; testWarmBootOnSecondCall
     * and testEditedSourceIsReprocessedAcrossCalls spawn bin/mcp-rector-warm as a
     * fresh subprocess via proc_open(), which starts its own PHP runtime and reads
     * the DEFAULT php.ini, so pcntl stays enabled there even while this method
     * returns false in the test process. The result is a real assertion mismatch
     * (this method expects a forced reboot; the subprocess actually forks and warms
     * up) that looks like a product bug but is a test-harness environment mismatch.
     * CI's `no-pcntl` job does not have this gap: shivammathur/setup-php's
     * `ini-values` rewrites the actual loaded php.ini, so every php invocation in
     * that job — including the subprocess — has pcntl disabled, and both tests pass
     * there (confirmed: green as of 1b837e0, and reproduced locally with the three
     * disable_functions entries applied via PHPRC to a real php.ini instead of `-d`,
     * which propagates to the subprocess exactly like CI's setup-php mechanism).
     * To actually exercise this path locally, disable the three functions in a real
     * php.ini (or via PHPRC) before running phpunit, not with `-d`.
     */
    private static function expectsWarmth(): bool
    {
        $canFork = \function_exists('pcntl_fork')
            && \function_exists('pcntl_waitpid')
            && \function_exists('stream_socket_pair');
        $forcedCold = \strtolower(\trim((string) \getenv(RectorRunner::NO_PCNTL_MODE_ENV))) === RectorRunner::NO_PCNTL_MODE_COLD;

        return $canFork || !$forcedCold;
    }

    public static function setUpBeforeClass(): void
    {
        self::$bin = dirname(__DIR__, 2) . '/bin/mcp-rector-warm';
        // Absolute paths — server chdirs to working-dir; relative cwd-dependent paths would break.
        self::$fixtureDir = realpath(dirname(__DIR__) . '/Fixtures/project') ?: '';
        self::$fixtureFile = self::$fixtureDir . '/src/Sample.php';

        if (!is_file(self::$bin)) {
            self::markTestSkipped('bin/mcp-rector-warm missing');
        }
        if (!is_file(self::$fixtureFile)) {
            self::markTestSkipped('fixture missing');
        }
    }

    public function testInitializeAndListTools(): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ];
        $responses = $this->invoke($messages, withProject: false);

        // Response 1: initialize
        self::assertSame(1, $responses[0]['id']);
        self::assertArrayHasKey('result', $responses[0]);
        self::assertSame('mcp-rector-warm', Json::at($responses, 0, 'result', 'serverInfo', 'name'));

        // Response 2: tools/list
        self::assertSame(2, $responses[1]['id']);
        $tools = Json::array($responses, 1, 'result', 'tools');
        $names = array_column($tools, 'name');
        self::assertContains('rector_process', $names);
    }

    public function testRectorProcessCall(): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name' => 'rector_process',
                'arguments' => ['path' => self::$fixtureFile, 'dryRun' => true],
            ]],
        ];
        $responses = $this->invoke($messages, withProject: true);

        $call = array_values(array_filter($responses, fn($r) => ($r['id'] ?? null) === 2))[0] ?? null;
        self::assertNotNull($call, 'no response for id=2');
        self::assertArrayHasKey('result', $call, 'expected result, got: ' . json_encode($call));

        $structured = Json::find($call, 'result', 'structuredContent');
        self::assertIsArray($structured);
        self::assertArrayHasKey('exit_code', $structured);
        self::assertArrayHasKey('warm_boot', $structured);
        self::assertFalse($structured['warm_boot'], 'first call should be cold boot');
    }

    public function testWarmBootOnSecondCall(): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name' => 'rector_process',
                'arguments' => ['path' => self::$fixtureFile, 'dryRun' => true],
            ]],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
                'name' => 'rector_process',
                'arguments' => ['path' => self::$fixtureFile, 'dryRun' => true],
            ]],
        ];
        $responses = $this->invoke($messages, withProject: true);

        $third = array_values(array_filter($responses, fn($r) => ($r['id'] ?? null) === 3))[0] ?? null;
        self::assertNotNull($third, 'no response for id=3');
        $structured = Json::array($third, 'result', 'structuredContent');
        self::assertSame(
            self::expectsWarmth(),
            $structured['warm_boot'],
            self::expectsWarmth()
                ? 'second tools/call should reuse warm container'
                : 'MCP_RECTOR_WARM_NO_PCNTL=cold without pcntl: every call is a cold subprocess, the second too',
        );
    }

    /**
     * Staleness guard: an edit made BETWEEN two process calls on the same warm
     * container must be reflected on the second call.
     *
     * Unlike phpunit (which *executes* classes and so can't reload them after an
     * edit — claude-supertool#265), Rector parses source to an AST and re-reads it
     * each run, so a re-processed file should surface the edit. This pins it: a
     * file Rector leaves alone (0 changed) → introduce an all-readonly promoted
     * class on disk (ReadOnlyClassRector applies → 1 changed) → the same warm
     * container must report the change.
     */
    public function testEditedSourceIsReprocessedAcrossCalls(): void
    {
        $project = $this->makeProject(withChange: false);
        $file    = $project . '/src/RectorProbe.php';

        $proc = $this->spawnServer($project);

        try {
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]]);
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            // Clean file → Rector finds nothing to change.
            $this->send($proc['stdin'], $this->processCall(2, $file));
            $first = $this->readResponse($proc['stdout'], 2);
            self::assertNotSame(-1, $this->changedFiles($first), 'rector output was unparseable' . $this->stderrTail($proc['stderr']));
            self::assertSame(
                0,
                $this->changedFiles($first),
                'clean fixture should yield 0 changed files, got: ' . json_encode(Json::find($first, 'result', 'structuredContent') ?? []) . $this->stderrTail($proc['stderr']),
            );

            // Introduce a refactorable pattern on disk; bump mtime past 1s granularity.
            file_put_contents($file, $this->probeClass(withChange: true));
            touch($file, time() + 5);

            // With pcntl, the same warm container must re-read the file and report the
            // change. Without it, RectorRunner reboots before this call (#8's fallback),
            // so warm_boot is legitimately false here too — the guarantee that matters in
            // that mode is correctness (below), not warmth.
            $this->send($proc['stdin'], $this->processCall(3, $file));
            $second = $this->readResponse($proc['stdout'], 3);
            self::assertSame(
                self::expectsWarmth(),
                Json::at($second, 'result', 'structuredContent', 'warm_boot'),
                'second call warm_boot mismatch' . $this->stderrTail($proc['stderr']),
            );
            self::assertNotSame(-1, $this->changedFiles($second), 'rector output was unparseable' . $this->stderrTail($proc['stderr']));
            self::assertSame(
                1,
                $this->changedFiles($second),
                'edited source becomes refactorable — warm container must re-read and report it (stale AST would still report 0)' . $this->stderrTail($proc['stderr']),
            );
        } finally {
            fclose($proc['stdin']);
            stream_get_contents($proc['stdout']);
            fclose($proc['stdout']);
            proc_close($proc['handle']);
        }
    }

    /**
     * Regression for claude-supertool#273: the warm container must return the same
     * result as a cold CLI for every file in a sequence — no spurious
     * "System error: ClassReflection must be resolved for class X".
     *
     * Why this is env-gated and not a self-contained fixture:
     *   1. The bug only manifests when Rector's DynamicSourceLocatorProvider caches
     *      its AggregateSourceLocator, which it does ONLY for non-PHPUnit runs
     *      (StaticPHPUnitEnvironment::isPHPUnitRun() === false). An in-process
     *      PHPUnit test therefore can never reproduce it — the cache is bypassed.
     *      The subprocess server is non-PHPUnit, which is why it triggers there.
     *   2. The failing reflection needs real framework base classes (DVSI's
     *      SiTestCase / SiModuleTestCase) extended from files outside the configured
     *      paths. Trivial synthetic classes resolve cleanly and never trip it.
     *
     * Point this at a project that reproduces it (e.g. a DVSI checkout):
     *   MCP_RECTOR_WARM_REPRO_DIR=/path/to/project \
     *   MCP_RECTOR_WARM_REPRO_FILES=relA.php,relB.php \   # >=2 files, B fails warm pre-fix
     *   MCP_RECTOR_WARM_REPRO_CONFIG=rector.php \         # optional, default rector.php
     *   vendor/bin/phpunit --filter testWarmReflectionMatchesColdForSequence
     */
    public function testWarmReflectionMatchesColdForSequence(): void
    {
        $project = getenv('MCP_RECTOR_WARM_REPRO_DIR');
        $filesEnv = getenv('MCP_RECTOR_WARM_REPRO_FILES');
        if ($project === false || $filesEnv === false) {
            self::markTestSkipped(
                'Set MCP_RECTOR_WARM_REPRO_DIR + MCP_RECTOR_WARM_REPRO_FILES (>=2 comma-separated '
                . 'paths relative to the dir) to run the #273 warm-reflection regression. '
                . 'See tools/repro-273.py to discover a triggering sequence.',
            );
        }

        $project = realpath($project) ?: $project;
        $config = getenv('MCP_RECTOR_WARM_REPRO_CONFIG') ?: 'rector.php';
        // The bundled bin only boots its own rector + autoload; point this at the
        // project's installed bin (e.g. DVSI's libs/bin/mcp-rector-warm) when the
        // config references project-specific custom rules.
        $bin = getenv('MCP_RECTOR_WARM_REPRO_BIN') ?: self::$bin;
        $files = array_values(array_filter(array_map(trim(...), explode(',', $filesEnv))));
        self::assertGreaterThanOrEqual(2, count($files), 'need at least two files to warm then re-use');

        $proc = $this->spawnServer($project, $config, $bin);

        try {
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]]);
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            $id = 1;
            foreach ($files as $rel) {
                $id++;
                $abs = $project . '/' . ltrim($rel, '/');
                $this->send($proc['stdin'], $this->processCall($id, $abs));
                $resp = $this->readResponse($proc['stdout'], $id);
                $blob = (string) json_encode(Json::find($resp, 'result', 'structuredContent') ?? []);
                self::assertStringNotContainsString(
                    'System error',
                    $blob,
                    "warm container emitted a System error on '{$rel}' (#273)" . $this->stderrTail($proc['stderr']),
                );
                self::assertStringNotContainsString(
                    'must be resolved',
                    $blob,
                    "warm container served a stale reflection locator on '{$rel}' (#273)" . $this->stderrTail($proc['stderr']),
                );
            }
        } finally {
            fclose($proc['stdin']);
            stream_get_contents($proc['stdout']);
            fclose($proc['stdout']);
            proc_close($proc['handle']);
        }
    }

    /**
     * Staleness guard for types read from OTHER files. B calls A::foo(); when
     * A::foo()'s return type changes on disk between two warm calls, the second
     * analysis of B must see the new type. PHPStan keeps class reflection for the
     * process lifetime and none of it is ResettableInterface, so a reused container
     * answers with A's old type: no error, just a wrong diff (#8).
     */
    public function testDependencyEditIsSeenByWarmContainer(): void
    {
        $project = $this->makeDependencyProject();
        $proc = $this->spawnServer($project);

        try {
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]]);
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            $this->send($proc['stdin'], $this->processCall(2, $project . '/src/Caller.php'));
            $first = $this->readResponse($proc['stdout'], 2);
            self::assertStringContainsString(
                'public function bar(Dependency $dependency): int',
                $this->diffOf($first),
                'baseline: Dependency::foo(): int should propagate to Caller::bar()' . $this->stderrTail($proc['stderr']),
            );

            file_put_contents($project . '/src/Dependency.php', $this->dependencyClass('string', "'x'"));
            touch($project . '/src/Dependency.php', time() + 5);

            $this->send($proc['stdin'], $this->processCall(3, $project . '/src/Caller.php'));
            $second = $this->readResponse($proc['stdout'], 3);
            self::assertStringContainsString(
                'public function bar(Dependency $dependency): string',
                $this->diffOf($second),
                'warm container must see the edited Dependency::foo(): string, not the cached int' . $this->stderrTail($proc['stderr']),
            );
        } finally {
            fclose($proc['stdin']);
            stream_get_contents($proc['stdout']);
            fclose($proc['stdout']);
            proc_close($proc['handle']);
        }
    }

    /**
     * --config must be honoured. The project holds its config under a non-default
     * name and no rector.php, so the rule can only fire if the flag reaches Rector.
     */
    public function testNonDefaultConfigNameIsHonoured(): void
    {
        $project = $this->makeProject(withChange: true);
        rename($project . '/rector.php', $project . '/custom-rector.php');
        $proc = $this->spawnServer($project, 'custom-rector.php');

        try {
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]]);
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            $this->send($proc['stdin'], $this->processCall(2, $project . '/src/RectorProbe.php'));
            $response = $this->readResponse($proc['stdout'], 2);
            self::assertSame(
                1,
                $this->changedFiles($response),
                'ReadOnlyClassRector from custom-rector.php should apply, got: ' . json_encode(Json::find($response, 'result', 'structuredContent') ?? []) . $this->stderrTail($proc['stderr']),
            );
        } finally {
            fclose($proc['stdin']);
            stream_get_contents($proc['stdout']);
            fclose($proc['stdout']);
            proc_close($proc['handle']);
        }
    }

    /**
     * @param array<mixed> $response
     */
    private function diffOf(array $response): string
    {
        $output = Json::find($response, 'result', 'structuredContent', 'output') ?? '';
        $decoded = is_string($output) && $output !== '' ? json_decode($output, true) : [];

        return implode("\n", array_map(static fn(mixed $file): string => Json::string($file, 'diff'), Json::array(Json::find($decoded, 'file_diffs') ?? [])));
    }

    private function makeDependencyProject(): string
    {
        $dir = sys_get_temp_dir() . '/rector_mcp_dep_' . bin2hex(random_bytes(6));
        mkdir($dir . '/src', 0777, true);
        $this->tmpDirs[] = $dir;

        file_put_contents($dir . '/src/Dependency.php', $this->dependencyClass('int', '1'));
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

        return $dir;
    }

    private function dependencyClass(string $returnType, string $returnValue): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\n"
            . "final class Dependency\n{\n    public function foo(): {$returnType}\n    {\n        return {$returnValue};\n    }\n}\n";
    }

    /**
     * @param array<mixed> $response
     */
    private function changedFiles(array $response): int
    {
        $output = Json::find($response, 'result', 'structuredContent', 'output') ?? '';
        $decoded = is_string($output) && $output !== '' ? json_decode($output, true) : [];

        $changed = Json::find($decoded, 'totals', 'changed_files') ?? -1;

        return is_int($changed) ? $changed : -1;
    }

    /**
     * @return array{handle: resource, stdin: resource, stdout: resource, stderr: string}
     */
    private function spawnServer(string $project, string $config = 'rector.php', ?string $bin = null): array
    {
        // Capture stderr to a file (not /dev/null) so a CI failure has diagnostics.
        $stderr = $project . '/server.stderr';
        // Absolute config path passes through unchanged; a relative one resolves
        // against the project (working) dir, matching the production daemon.
        $configPath = str_starts_with($config, '/') ? $config : $project . '/' . $config;
        // #97: same shebang-without-interpreter fix as invoke() below --
        // $bin (or self::$bin) is a shebang-only PHP script proc_open()
        // cannot resolve on Windows with no interpreter prepended. This
        // review found this second call site still missing the prepend
        // after invoke() alone was fixed.
        $cmd = [
            PHP_BINARY,
            $bin ?? self::$bin,
            '--working-dir=' . $project,
            '--config=' . $configPath,
        ];
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
        );
        self::assertIsResource($proc);

        return ['handle' => $proc, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $stderr];
    }

    private function stderrTail(string $path): string
    {
        $contents = @file_get_contents($path);

        return ($contents === false || $contents === '') ? '' : ' | server stderr: ' . substr($contents, -1500);
    }

    /**
     * @param resource            $stdin
     * @param array<string,mixed> $message
     */
    private function send($stdin, array $message): void
    {
        fwrite($stdin, json_encode($message) . "\n");
        fflush($stdin);
    }

    /**
     * Block reading newline-delimited JSON-RPC until the response with $id arrives.
     * Rector cold boot can take several seconds — allow a generous read timeout.
     *
     * @param resource $stdout
     * @return array<mixed>
     */
    private function readResponse($stdout, int $id): array
    {
        stream_set_timeout($stdout, 120);
        while (($line = fgets($stdout)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['id'] ?? null) === $id) {
                return $decoded;
            }
        }

        self::fail("no response for id={$id}");
    }

    /**
     * @return array<string,mixed>
     */
    private function processCall(int $id, string $file): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => [
            'name'      => 'rector_process',
            'arguments' => ['path' => $file, 'dryRun' => true],
        ]];
    }

    private function makeProject(bool $withChange): string
    {
        $dir = sys_get_temp_dir() . '/rector_mcp_regr_' . bin2hex(random_bytes(6));
        mkdir($dir . '/src', 0777, true);
        $this->tmpDirs[] = $dir;

        file_put_contents($dir . '/src/RectorProbe.php', $this->probeClass($withChange));
        // Pin the SINGLE rule under test rather than the broad php82 set — a broad
        // set's "0 changes" baseline isn't contractually stable across rector
        // versions and could rewrite the clean fixture, failing for the wrong reason.
        file_put_contents(
            $dir . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\n"
            . "use Rector\\Config\\RectorConfig;\n"
            . "use Rector\\Php82\\Rector\\Class_\\ReadOnlyClassRector;\n\n"
            . "return RectorConfig::configure()->withPaths([__DIR__ . '/src'])->withRules([ReadOnlyClassRector::class]);\n",
        );

        return $dir;
    }

    /**
     * Clean version has no property to modernise (0 changes). The changed version
     * is a final class whose only state is a promoted readonly property, which
     * ReadOnlyClassRector rewrites to a `readonly class` (1 change).
     */
    private function probeClass(bool $withChange): string
    {
        if ($withChange) {
            return "<?php\n\ndeclare(strict_types=1);\n\n"
                . "final class RectorProbe\n{\n    public function __construct(private readonly int \$value) {}\n\n"
                . "    public function value(): int\n    {\n        return \$this->value;\n    }\n}\n";
        }

        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . "final class RectorProbe\n{\n    public function add(int \$first, int \$second): int\n    {\n        return \$first + \$second;\n    }\n}\n";
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @return list<array<mixed>>
     */
    private function invoke(array $messages, bool $withProject): array
    {
        $args = [];
        if ($withProject) {
            $args[] = '--working-dir=' . self::$fixtureDir;
            $args[] = '--config=' . self::$fixtureDir . '/rector.php';
        }
        // #97: self::$bin is a shebang-only PHP script with no .bat/.exe/
        // .cmd extension. proc_open can exec a shebang file directly on
        // POSIX, but Windows has no shebang interpretation and nothing for
        // CreateProcess to resolve, so proc_open silently returns false
        // instead of a resource -- found via #97's own windows-latest CI
        // leg. Prepending the interpreter explicitly works identically on
        // every platform and still boots the same script this test means
        // to cover -- it does not depend on the shebang line or the file's
        // executable bit either way.
        $cmd = array_merge([PHP_BINARY, self::$bin], $args);

        $stdin = '';
        foreach ($messages as $m) {
            $stdin .= json_encode($m) . "\n";
        }

        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $responses = [];
        foreach (explode("\n", $stdout) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $responses[] = $decoded;
            }
        }
        self::assertNotEmpty($responses, 'no responses parsed. stdout=' . $stdout . ' stderr=' . $stderr);
        return $responses;
    }
}
