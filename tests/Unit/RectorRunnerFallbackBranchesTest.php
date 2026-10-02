<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Composer\Autoload\ClassLoader;
use Dpt\McpRectorWarm\RectorRunner;
use Dpt\McpRectorWarm\Tests\Support\TempPath;
use PHPUnit\Framework\TestCase;

/**
 * #245: the fail-open, fallback and argument-validation branches of
 * RectorRunner.php that no test reached -- the skip-copy and autoloader
 * remap fallbacks, the JSON encode fallbacks, the zero-length and idle
 * frame reads, configFileChanged()'s individual "changed" answers, and a
 * handful of small helpers. Private methods are invoked via Reflection,
 * the same pattern RectorRunnerConfigResolutionTest and
 * RectorRunnerComposerRemapTest use. Each "must not change" case is paired
 * with a case where the same code path does change something.
 */
final class RectorRunnerFallbackBranchesTest extends TestCase
{
    private string $tmp;

    private string $previousCwd;

    /** @var list<string> */
    private array $previousArgv;

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/rector-runner-fallback-test-' . \bin2hex(\random_bytes(8));
        \mkdir($this->tmp);
        $this->previousCwd = (string) \getcwd();
        // Same reset as RectorRunnerConfigResolutionTest: RectorConfigsResolver
        // reads --config from the real $_SERVER['argv'].
        $this->previousArgv = $_SERVER['argv'] ?? ['rector'];
        $_SERVER['argv'] = ['rector'];
    }

    protected function tearDown(): void
    {
        \chdir($this->previousCwd);
        $_SERVER['argv'] = $this->previousArgv;
        foreach (\array_reverse($this->created) as $path) {
            if (\is_dir($path)) {
                TempPath::rmdir($path);
            } elseif (\is_file($path)) {
                TempPath::unlink($path);
            }
        }
        TempPath::rmdir($this->tmp);
    }

    private function file(string $name, string $content): string
    {
        $path = $this->tmp . '/' . $name;
        \file_put_contents($path, $content);
        $this->created[] = $path;

        return $path;
    }

    private function method(string $name): \ReflectionMethod
    {
        return new \ReflectionMethod(RectorRunner::class, $name);
    }

    private function property(string $name): \ReflectionProperty
    {
        return new \ReflectionProperty(RectorRunner::class, $name);
    }

    // ---- small helpers -------------------------------------------------

    public function testGetCallTimeoutSecondsReturnsTheConstructorValue(): void
    {
        self::assertSame(42, (new RectorRunner(42))->getCallTimeoutSeconds());
        self::assertSame(0, (new RectorRunner(0))->getCallTimeoutSeconds());
    }

    public function testSessionRetireReasonReadsGigabyteAndKilobyteMemoryLimits(): void
    {
        $mb = 1024 * 1024;
        // 1G: 75% is 768 MB.
        self::assertNull(RectorRunner::sessionRetireReason(1, 700 * $mb, '1G', 0, 0));
        self::assertStringContainsString('memory_limit 1G', (string) RectorRunner::sessionRetireReason(1, 800 * $mb, '1G', 0, 0));
        // 262144K = 256 MB: 75% is 192 MB.
        self::assertNull(RectorRunner::sessionRetireReason(1, 150 * $mb, '262144K', 0, 0));
        self::assertStringContainsString('memory_limit 262144K', (string) RectorRunner::sessionRetireReason(1, 200 * $mb, '262144K', 0, 0));
    }

    public function testDisableSessionMarksTheSessionUnavailable(): void
    {
        $runner = new RectorRunner();
        self::assertFalse($this->property('sessionUnavailable')->getValue($runner));

        $this->method('disableSession')->invoke($runner, 'test reason');

        self::assertTrue($this->property('sessionUnavailable')->getValue($runner));
    }

    public function testCollectIniOverrideArgsSkipsANonArrayEntry(): void
    {
        $method = $this->method('collectIniOverrideArgsFrom');

        self::assertSame([], $method->invoke(null, ['rector_runner_test.directive' => 'not an array']));
        // Positive control: the same name as a real ini_get_all() entry is forwarded.
        $args = $method->invoke(null, ['rector_runner_test.directive' => ['global_value' => 'x', 'local_value' => 'x']]);
        self::assertIsArray($args);
        self::assertSame('-d', $args[0] ?? null);
        self::assertStringStartsWith('rector_runner_test.directive=', (string) ($args[1] ?? ''));
    }

    public function testProcWorkerAliveIsFalseWithNoWorker(): void
    {
        self::assertFalse($this->method('procWorkerAlive')->invoke(new RectorRunner()));
    }

    public function testProcWorkerStderrTailReportsNoStderrOrTheFileContents(): void
    {
        $runner = new RectorRunner();
        $tail = $this->method('procWorkerStderrTail');
        $procWorker = $this->property('procWorker');

        self::assertSame('(no stderr)', $tail->invoke($runner));

        $empty = $this->file('empty-stderr.log', "  \n");
        $procWorker->setValue($runner, ['stderr' => $empty]);
        self::assertSame('(no stderr)', $tail->invoke($runner));

        $full = $this->file('full-stderr.log', "\nPHP Fatal error: boom\n");
        $procWorker->setValue($runner, ['stderr' => $full]);
        self::assertSame('PHP Fatal error: boom', $tail->invoke($runner));

        $long = $this->file('long-stderr.log', \str_repeat('a', 3000) . 'END');
        $procWorker->setValue($runner, ['stderr' => $long]);
        $text = $tail->invoke($runner);
        self::assertIsString($text);
        self::assertSame(2000, \strlen($text));
        self::assertStringEndsWith('END', $text);

        $procWorker->setValue($runner, null);
    }

    // ---- JSON encode fallbacks -----------------------------------------

    public function testEncodeForkResultFallsBackToAnErrorPayloadWhenEncodingFails(): void
    {
        $method = $this->method('encodeForkResult');
        $runner = new RectorRunner();

        // Positive control: an encodable payload passes through unchanged.
        self::assertSame('{"exit_code":0}', $method->invoke($runner, ['exit_code' => 0]));

        $encoded = $method->invoke($runner, ['exit_code' => 0, 'ratio' => \NAN]);
        self::assertIsString($encoded);
        $decoded = \json_decode($encoded, true);
        self::assertIsArray($decoded);
        self::assertSame('JsonException', $decoded['error_class'] ?? null);
        self::assertStringStartsWith('failed to encode the forked call result: ', (string) ($decoded['error'] ?? ''));
        self::assertArrayNotHasKey('exit_code', $decoded);
    }

    public function testEncodeHandshakeFrameFallsBackToAFailedHandshakeWhenEncodingFails(): void
    {
        $method = $this->method('encodeHandshakeFrame');
        $runner = new RectorRunner();

        self::assertSame('{"ok":true}', $method->invoke($runner, ['ok' => true]));

        $encoded = $method->invoke($runner, ['ok' => true, 'ratio' => \INF]);
        self::assertIsString($encoded);
        $decoded = \json_decode($encoded, true);
        self::assertIsArray($decoded);
        self::assertFalse($decoded['ok'] ?? null);
        self::assertStringStartsWith('failed to encode the warm-worker handshake frame: ', (string) ($decoded['error'] ?? ''));
    }

    // ---- frame reads -----------------------------------------------------

    /**
     * A connected loopback TCP pair, portable to Windows (no AF_UNIX).
     *
     * @return array{resource, resource, resource}
     */
    private function socketPair(): array
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server, 'could not open a loopback TCP server for this test');
        $address = \stream_socket_get_name($server, false);
        self::assertIsString($address);
        $client = \stream_socket_client('tcp://' . $address);
        self::assertNotFalse($client);
        $accepted = \stream_socket_accept($server);
        self::assertNotFalse($accepted);

        return [$server, $client, $accepted];
    }

    public function testReadFrameReturnsAnEmptyStringForAZeroLengthFrame(): void
    {
        [$server, $writer, $reader] = $this->socketPair();
        try {
            \fwrite($writer, \pack('N', 0) . \pack('N', 3) . 'abc');
            $readFrame = $this->method('readFrame');
            $runner = new RectorRunner();

            self::assertSame('', $readFrame->invoke($runner, $reader));
            // Positive control: the next frame on the same socket is read whole.
            self::assertSame('abc', $readFrame->invoke($runner, $reader));
        } finally {
            \fclose($writer);
            \fclose($reader);
            \fclose($server);
        }
    }

    public function testReadExactlyCallsOnIdleOnEachReadTimeoutUntilDataArrives(): void
    {
        [$server, $writer, $reader] = $this->socketPair();
        try {
            \stream_set_timeout($reader, 0, 50_000);
            $idleCalls = 0;
            $onIdle = static function () use (&$idleCalls, $writer): void {
                $idleCalls++;
                if ($idleCalls === 2) {
                    \fwrite($writer, 'hello');
                }
            };

            $read = $this->method('readExactly')->invoke(new RectorRunner(), $reader, 5, null, $onIdle);

            self::assertSame('hello', $read);
            self::assertGreaterThanOrEqual(2, $idleCalls);
        } finally {
            \fclose($writer);
            \fclose($reader);
            \fclose($server);
        }
    }

    // ---- configFileChanged() / refreshConfigFileState() ----------------

    private function rectorPhp(): void
    {
        $this->file(
            'rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\nreturn RectorConfig::configure();\n",
        );
    }

    public function testConfigFileChangedIsFalseRightAfterARefresh(): void
    {
        \chdir($this->tmp);
        $this->rectorPhp();
        $this->file('composer.json', '{}');
        $runner = new RectorRunner();
        $this->method('refreshConfigFileState')->invoke($runner);

        self::assertFalse($this->method('configFileChanged')->invoke($runner));
    }

    public function testConfigFileChangedWhenTheConfigPathDiffersFromTheBootedOne(): void
    {
        \chdir($this->tmp);
        $this->rectorPhp();
        $runner = new RectorRunner();
        $this->method('refreshConfigFileState')->invoke($runner);
        self::assertFalse($this->method('configFileChanged')->invoke($runner));

        $this->property('configFile')->setValue($runner, $this->tmp . '/some-other-rector.php');

        self::assertTrue($this->method('configFileChanged')->invoke($runner));
    }

    public function testConfigFileChangedWhenComposerJsonAppearsOrIsEdited(): void
    {
        \chdir($this->tmp);
        $this->rectorPhp();
        $runner = new RectorRunner();
        $this->method('refreshConfigFileState')->invoke($runner);
        self::assertFalse($this->method('configFileChanged')->invoke($runner));

        // A composer.json that did not exist at boot: a different resolved path.
        $this->file('composer.json', '{}');
        self::assertTrue($this->method('configFileChanged')->invoke($runner));

        // Same path, different bytes.
        $this->method('refreshConfigFileState')->invoke($runner);
        self::assertFalse($this->method('configFileChanged')->invoke($runner));
        \file_put_contents($this->tmp . '/composer.json', '{"require":{"php":"^8.2"}}');
        self::assertTrue($this->method('configFileChanged')->invoke($runner));
    }

    public function testAnUnresolvableConfigReadsAsChangedAndRefreshesToNull(): void
    {
        \chdir($this->tmp);
        $this->rectorPhp();
        $runner = new RectorRunner();
        $this->method('refreshConfigFileState')->invoke($runner);
        self::assertNotNull($this->property('configFile')->getValue($runner));
        self::assertFalse($this->method('configFileChanged')->invoke($runner));

        // An explicit --config that does not exist makes provide() throw.
        $_SERVER['argv'] = ['rector', '--config', $this->tmp . '/missing-rector.php'];

        self::assertTrue($this->method('configFileChanged')->invoke($runner));
        $this->method('refreshConfigFileState')->invoke($runner);
        self::assertNull($this->property('configFile')->getValue($runner));
        self::assertNull($this->property('configFileHash')->getValue($runner));
    }

    // ---- applySkipsOfOriginalPath() / ruleSkippedFor() ------------------

    /**
     * @param array<string, object> $services
     */
    private function fakeContainer(array $services): object
    {
        return new class ($services) {
            /** @var list<string> */
            public array $asked = [];

            /** @param array<string, object> $services */
            public function __construct(private array $services) {}

            public function get(string $id): object
            {
                $this->asked[] = $id;
                if (!isset($this->services[$id])) {
                    throw new \RuntimeException("no service {$id}");
                }

                return $this->services[$id];
            }
        };
    }

    /**
     * @param array<string, true> $skippedFiles paths shouldSkipFilePath() is true for
     * @param array<string, list<string>> $ruleSkips rule => paths matchSkip() matches
     */
    private function fakeSkipper(array $skippedFiles, array $ruleSkips = []): object
    {
        return new class ($skippedFiles, $ruleSkips) {
            /**
             * @param array<string, true> $skippedFiles
             * @param array<string, list<string>> $ruleSkips
             */
            public function __construct(private array $skippedFiles, private array $ruleSkips) {}

            public function shouldSkipFilePath(string $path): bool
            {
                return isset($this->skippedFiles[$path]);
            }

            public function matchSkip(string $class, string $path): ?string
            {
                return \in_array($path, $this->ruleSkips[$class] ?? [], true) ? $path : null;
            }
        };
    }

    /** @return list<string> */
    private function copyPaths(string $copyPath): array
    {
        return \array_values(\array_unique(\array_filter([$copyPath, \realpath($copyPath) ?: null])));
    }

    public function testAWhollySkippedOriginalAddsTheCopyToTheSkippedPaths(): void
    {
        $original = $this->tmp . '/Original.php';
        $copy = $this->file('Copy.php', "<?php\n");
        $pathsResolver = new class {
            /** @var list<string> */
            private array $skippedPaths = ['/already/skipped'];

            /** @return list<string> */
            public function resolve(): array
            {
                return $this->skippedPaths;
            }
        };
        $container = $this->fakeContainer([
            \Rector\Skipper\Skipper\Skipper::class => $this->fakeSkipper([$original => true]),
            \Rector\Skipper\SkipCriteriaResolver\SkippedPathsResolver::class => $pathsResolver,
        ]);
        $runner = new RectorRunner();
        $this->property('container')->setValue($runner, $container);

        $this->method('applySkipsOfOriginalPath')->invoke($runner, $original, $copy);

        self::assertSame(\array_merge(['/already/skipped'], $this->copyPaths($copy)), $pathsResolver->resolve());
    }

    public function testRuleSkipsFollowTheCopyButSkippedEverywhereRulesAreLeftAlone(): void
    {
        $original = $this->tmp . '/Original.php';
        $copy = $this->file('Copy.php', "<?php\n");
        $classResolver = new class {
            /** @var array<string, list<string>|null> */
            private array $skippedClassesToFiles = ['EverywhereRule' => null, 'PathRule' => ['/orig']];

            /** @return array<string, list<string>|null> */
            public function resolve(): array
            {
                return $this->skippedClassesToFiles;
            }
        };
        $container = $this->fakeContainer([
            \Rector\Skipper\Skipper\Skipper::class => $this->fakeSkipper([], ['PathRule' => [$original]]),
            \Rector\Skipper\SkipCriteriaResolver\SkippedClassResolver::class => $classResolver,
        ]);
        $runner = new RectorRunner();
        $this->property('container')->setValue($runner, $container);

        $this->method('applySkipsOfOriginalPath')->invoke($runner, $original, $copy);

        self::assertSame(
            ['EverywhereRule' => null, 'PathRule' => \array_merge(['/orig'], $this->copyPaths($copy))],
            $classResolver->resolve(),
        );
    }

    public function testApplySkipsFailsOpenWhenTheContainerThrows(): void
    {
        $container = $this->fakeContainer([]);
        $runner = new RectorRunner();
        $this->property('container')->setValue($runner, $container);

        // Must return normally: the copy is then diagnosed without the skips.
        $this->method('applySkipsOfOriginalPath')->invoke($runner, $this->tmp . '/Original.php', $this->tmp . '/Copy.php');

        self::assertSame(
            [\Rector\Skipper\Skipper\Skipper::class],
            (new \ReflectionProperty($container, 'asked'))->getValue($container),
        );
    }

    public function testRuleSkippedForUsesTheOlderSkipperApiAndRefusesAnUnknownOne(): void
    {
        $method = $this->method('ruleSkippedFor');
        $older = new class {
            public function shouldSkipElementAndFilePath(string $class, string $path): bool
            {
                return $class === 'Rule' && $path === '/a.php';
            }
        };
        self::assertTrue($method->invoke(null, $older, 'Rule', '/a.php'));
        self::assertFalse($method->invoke(null, $older, 'Rule', '/b.php'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has neither matchSkip() nor shouldSkipElementAndFilePath()');
        $method->invoke(null, new \stdClass(), 'Rule', '/a.php');
    }

    // ---- remapCopyClassesInAutoloader() ---------------------------------

    /**
     * @param list<callable> $before
     */
    private function unregisterLeakedAutoloaders(array $before): void
    {
        foreach (\spl_autoload_functions() as $function) {
            if (!\in_array($function, $before, true)) {
                \spl_autoload_unregister($function);
            }
        }
    }

    private function removeFromEveryRegisteredLoader(string $class): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $property = new \ReflectionProperty(ClassLoader::class, 'classMap');
            $map = $property->getValue($loader);
            if (\is_array($map) && isset($map[$class])) {
                unset($map[$class]);
                $property->setValue($loader, $map);
            }
        }
    }

    public function testRemapIsANoOpForAnUnreadableOrClassFreeCopy(): void
    {
        $remap = $this->method('remapCopyClassesInAutoloader');
        $before = \spl_autoload_functions();
        try {
            // A missing copy: file_get_contents() fails.
            $remap->invoke(null, $this->tmp . '/missing-copy.php');
            self::assertCount(\count($before), \spl_autoload_functions());

            // Readable, but declares nothing.
            $remap->invoke(null, $this->file('NoClasses.php', "<?php\necho 1;\n"));
            self::assertCount(\count($before), \spl_autoload_functions());

            // Positive control: a copy that declares a class does register a loader.
            $remap->invoke(null, $this->file('Declares.php', "<?php\nnamespace RectorRunnerFallbackFixture;\nclass NotLoaded {}\n"));
            self::assertCount(\count($before) + 1, \spl_autoload_functions());
        } finally {
            $this->unregisterLeakedAutoloaders($before);
            $this->removeFromEveryRegisteredLoader('RectorRunnerFallbackFixture\\NotLoaded');
        }
    }

    public function testTheRemappedClassAutoloadsFromTheCopy(): void
    {
        $short = 'Remapped' . \bin2hex(\random_bytes(4));
        $class = 'RectorRunnerFallbackFixture\\' . $short;
        $copy = $this->file('Remapped.php', "<?php\nnamespace RectorRunnerFallbackFixture;\nclass {$short} {}\n");
        $before = \spl_autoload_functions();
        try {
            self::assertFalse(\class_exists($class, false));

            $this->method('remapCopyClassesInAutoloader')->invoke(null, $copy);

            // The prepended loader handles only the copy's classes...
            self::assertFalse(\class_exists('RectorRunnerFallbackFixture\\NeverDeclared', true));
            // ...and loads those from the copy.
            self::assertTrue(\class_exists($class, true));
            self::assertSame(\realpath($copy), \realpath((string) (new \ReflectionClass($class))->getFileName()));
        } finally {
            $this->unregisterLeakedAutoloaders($before);
            $this->removeFromEveryRegisteredLoader($class);
        }
    }

    public function testRemapFailsOpenWhenARegisteredLoaderThrows(): void
    {
        $class = 'RectorRunnerFallbackFixture\\Throwing';
        $copy = $this->file('Throwing.php', "<?php\nnamespace RectorRunnerFallbackFixture;\nclass Throwing {}\n");
        $loaderDir = $this->tmp . '/vendor';
        \mkdir($loaderDir);
        $this->created[] = $loaderDir;
        $throwing = new class ($loaderDir) extends ClassLoader {
            public int $calls = 0;

            /** @param array<string, string> $classMap */
            public function addClassMap(array $classMap): void
            {
                $this->calls++;

                throw new \RuntimeException('addClassMap refused');
            }
        };
        $throwing->register();
        $before = \spl_autoload_functions();
        try {
            // Must return normally, and stop before registering its own loader.
            $this->method('remapCopyClassesInAutoloader')->invoke(null, $copy);

            self::assertSame(1, $throwing->calls);
            self::assertCount(\count($before), \spl_autoload_functions());
        } finally {
            $this->unregisterLeakedAutoloaders($before);
            $this->removeFromEveryRegisteredLoader($class);
            $throwing->unregister();
        }
    }
}
