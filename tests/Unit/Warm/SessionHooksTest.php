<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Warm;

use Composer\Autoload\ClassLoader;
use Dpt\McpRectorWarm\Warm\Path;
use Dpt\McpRectorWarm\Warm\SessionHooks;
use Dpt\McpRectorWarm\Warm\TrackingParser;
use PHPStan\BetterReflection\Identifier\Identifier;
use PHPStan\BetterReflection\Identifier\IdentifierType;
use PHPStan\BetterReflection\Reflection\Reflection;
use PHPStan\BetterReflection\Reflector\Reflector;
use PHPStan\BetterReflection\SourceLocator\Type\SourceLocator;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ExtensionsCollection;
use PHPStan\Parser\Parser;
use PHPStan\Parser\PathRoutingParser;
use PHPStan\Reflection\BetterReflection\BetterReflectionSourceLocatorFactory;
use PHPStan\Reflection\BetterReflection\SourceLocator\FileNodesFetcher;
use PHPStan\Reflection\BetterReflection\SourceLocator\OptimizedSingleFileSourceLocatorRepository;
use PHPUnit\Framework\TestCase;
use Rector\Configuration\Option;
use Rector\Configuration\Parameter\SimpleParameterProvider;
use Rector\NodeTypeResolver\DependencyInjection\PHPStanServicesFactory;

/**
 * #268 (part of #245): the 18 lines Codecov reported missed in SessionHooks.php,
 * none of which had any dedicated test before -- every other line in this file is
 * reached indirectly through RectorRunner's own warm-session integration tests.
 *
 * SessionHooks reaches into PHPStan's real container (PHPStan\DependencyInjection\
 * Container, an interface) and into one final PHPStan value class after another
 * (PathRoutingParser, PHPStanServicesFactory, FileNodesFetcher, ...). Booting a real
 * PHPStan container per test would be slow and would not let these specific branches
 * be forced deterministically, so every test here builds a FakeContainer (below)
 * implementing the real Container interface, and reaches PHPStanServicesFactory
 * through ReflectionClass::newInstanceWithoutConstructor() + a private-property swap
 * -- its own constructor always boots a real container with no seam to replace it, so
 * the only way to control what SessionHooks sees is to skip that constructor entirely
 * and substitute the FakeContainer afterward.
 *
 * Three of the issue's 18 lines are deliberately not forced here, not skipped by
 * oversight:
 *
 * - 285, 297 (vendorDirectories()/composerSourceDirectories()'s
 *   `!\class_exists(\Composer\Autoload\ClassLoader::class, false)` guards): the
 *   `false` argument means "only count an ALREADY LOADED class" -- and Composer's own
 *   ClassLoader is what boots every PHPUnit run in this repository (vendor/autoload.php),
 *   so it is always already loaded by the time any test runs. There is no PHPUnit
 *   process in which this guard can be false; forcing it would need a production seam
 *   this class does not have.
 * - 94 (`installParserHook()`'s `!$inner instanceof Parser` guard): the routed
 *   property this checks (PathRoutingParser::$currentPhpVersionRichParser and its two
 *   siblings) is itself typed `Parser`, and PHP enforces a typed property's declared
 *   type even through ReflectionProperty::setValue() on a private property -- confirmed
 *   by trying it here: assigning a non-Parser value throws a TypeError before
 *   installParserHook() is ever called. There is no way to get a non-Parser value into
 *   that property without PathRoutingParser (a final PHPStan class this repo does not
 *   own) losing its own type, so this branch is unreachable from any caller.
 */
final class SessionHooksTest extends TestCase
{
    private string|false $previousAutoloadPaths = false;

    private bool $hadAutoloadPaths = false;

    private ?string $tmpFile = null;

    private ?string $fallbackDir = null;

    private ?ClassLoader $registeredLoader = null;

    protected function setUp(): void
    {
        $this->hadAutoloadPaths = SimpleParameterProvider::hasParameter(Option::AUTOLOAD_PATHS);
        if ($this->hadAutoloadPaths) {
            $this->previousAutoloadPaths = \serialize(SimpleParameterProvider::provideArrayParameter(Option::AUTOLOAD_PATHS));
        }
    }

    protected function tearDown(): void
    {
        if ($this->hadAutoloadPaths && \is_string($this->previousAutoloadPaths)) {
            /** @var array<mixed> $restored */
            $restored = \unserialize($this->previousAutoloadPaths);
            SimpleParameterProvider::setParameter(Option::AUTOLOAD_PATHS, $restored);
        } elseif (!$this->hadAutoloadPaths) {
            // SimpleParameterProvider::setParameter(..., []) would leave hasParameter()
            // true for the rest of this (single-process) PHPUnit run -- it has no public
            // "unset" API, so restoring "never set" needs a reflection write on its own
            // private static $parameters map, the same way the rest of this file reaches
            // into otherwise-inaccessible state on purpose.
            self::unsetAutoloadPathsParameter();
        }
        if ($this->tmpFile !== null) {
            @\unlink($this->tmpFile);
            $this->tmpFile = null;
        }
        if ($this->registeredLoader !== null) {
            $this->registeredLoader->unregister();
            $this->registeredLoader = null;
        }
        if ($this->fallbackDir !== null) {
            @\rmdir($this->fallbackDir);
            $this->fallbackDir = null;
        }
    }

    public function testConstructorRefusesARectorContainerWithNoGetMethod(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the Rector container has no get()');

        new SessionHooks(new \stdClass());
    }

    public function testConstructorRefusesWhenGetDoesNotReturnThePHPStanServicesFactory(): void
    {
        $container = new class {
            public function get(string $id): null
            {
                return null;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rector did not return its PHPStanServicesFactory');

        new SessionHooks($container);
    }

    public function testInstallParserHookRefusesWhenPathRoutingParserServiceIsWrongType(): void
    {
        $hooks = $this->hooks(new FakeContainer(services: [
            'pathRoutingParser' => new \stdClass(),
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PHPStan service pathRoutingParser is not a PathRoutingParser');

        $hooks->installParserHook(static function (string $file): void {});
    }

    public function testInstallParserHookRefusesWhenDefaultAnalysisParserIsADifferentInstance(): void
    {
        $routing = self::fakePathRoutingParser();
        $hooks = $this->hooks(new FakeContainer(services: [
            'pathRoutingParser' => $routing,
            // A different PathRoutingParser instance: !== $routing.
            'defaultAnalysisParser' => self::fakePathRoutingParser(),
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('defaultAnalysisParser is not the pathRoutingParser instance');

        $hooks->installParserHook(static function (string $file): void {});
    }

    public function testInstallParserHookSkipsAParserThatIsAlreadyTracked(): void
    {
        $routing = self::fakePathRoutingParser();
        $alreadyTracked = new TrackingParser(self::fakeParser(), static function (string $file): void {});
        self::setRoutedParser($routing, 'currentPhpVersionRichParser', $alreadyTracked);
        self::setRoutedParser($routing, 'currentPhpVersionSimpleParser', self::fakeParser());
        self::setRoutedParser($routing, 'php8Parser', self::fakeParser());

        $hooks = $this->hooks(new FakeContainer(services: [
            'pathRoutingParser' => $routing,
            'defaultAnalysisParser' => $routing,
        ]));

        $hooks->installParserHook(static function (string $file): void {});

        // The already-TrackingParser property was skipped (continue), not rewrapped.
        self::assertSame($alreadyTracked, self::getRoutedParser($routing, 'currentPhpVersionRichParser'));
        // The other two, plain Parser instances, were wrapped.
        self::assertInstanceOf(TrackingParser::class, self::getRoutedParser($routing, 'currentPhpVersionSimpleParser'));
        self::assertInstanceOf(TrackingParser::class, self::getRoutedParser($routing, 'php8Parser'));
    }

    public function testDeclaredSymbolsReturnsFunctionsAndConstantsAndNameOfFallsBackToTheFetchedKey(): void
    {
        $fetched = new FakeFetchedNodes(
            classNodes: [],
            functionNodes: ['App\\myFunc' => [new \stdClass()]],
            constantNodes: ['App\\MY_CONST' => new \stdClass()],
        );
        $fetcher = new class ($fetched) {
            public function __construct(private readonly FakeFetchedNodes $fetched) {}

            public function fetchNodes(string $path): FakeFetchedNodes
            {
                return $this->fetched;
            }
        };
        $hooks = $this->hooks(new FakeContainer(byType: [
            FileNodesFetcher::class => $fetcher,
        ]));

        $symbols = $hooks->declaredSymbols('/some/file.php');

        self::assertSame([
            [SessionHooks::FUNCTION, 'App\\myFunc'],
            [SessionHooks::CONSTANT, 'App\\MY_CONST'],
        ], $symbols);
    }

    public function testResolveStandardReturnsNullWhenTheSourceLocatorCannotAnswer(): void
    {
        $sourceLocator = new class implements SourceLocator {
            public function locateIdentifier(Reflector $reflector, Identifier $identifier): ?Reflection
            {
                throw new \RuntimeException('BetterReflection cannot answer this one');
            }

            public function locateIdentifiersByType(Reflector $reflector, IdentifierType $identifierType): array
            {
                return [];
            }
        };
        $factory = new class ($sourceLocator) {
            public function __construct(private readonly SourceLocator $sourceLocator) {}

            public function create(): SourceLocator
            {
                return $this->sourceLocator;
            }
        };
        $hooks = $this->hooks(new FakeContainer(
            services: ['betterReflectionReflector' => new FakeReflector()],
            byType: [BetterReflectionSourceLocatorFactory::class => $factory],
        ));

        self::assertNull($hooks->resolveStandard(SessionHooks::CLASS_LIKE, 'App\\Unknown'));
    }

    public function testResolveAutoloadPathsReachesAFileEntryThroughTheSingleFileSourceLocatorRepository(): void
    {
        $this->tmpFile = \sys_get_temp_dir() . '/session-hooks-test-' . \bin2hex(\random_bytes(8)) . '.php';
        \file_put_contents($this->tmpFile, "<?php\n");
        SimpleParameterProvider::setParameter(Option::AUTOLOAD_PATHS, [$this->tmpFile]);

        $sourceLocator = new class implements SourceLocator {
            public function locateIdentifier(Reflector $reflector, Identifier $identifier): ?Reflection
            {
                return null;
            }

            public function locateIdentifiersByType(Reflector $reflector, IdentifierType $identifierType): array
            {
                return [];
            }
        };
        $repository = new class ($sourceLocator) {
            /** @var list<string> */
            public array $requestedFiles = [];

            public function __construct(private readonly SourceLocator $sourceLocator) {}

            public function getOrCreate(string $file): SourceLocator
            {
                $this->requestedFiles[] = $file;

                return $this->sourceLocator;
            }
        };
        $hooks = $this->hooks(new FakeContainer(
            services: ['betterReflectionReflector' => new FakeReflector()],
            byType: [OptimizedSingleFileSourceLocatorRepository::class => $repository],
        ));

        self::assertNull($hooks->resolveAutoloadPaths(SessionHooks::CLASS_LIKE, 'App\\Unknown'));
        // The is_file() branch (line 136) really ran, not just "no locators at all":
        // without it $repository->getOrCreate() would never be called.
        self::assertSame([Path::real($this->tmpFile)], $repository->requestedFiles);
    }

    public function testComposerSourceDirectoriesReachesAPsr4FallbackDirectory(): void
    {
        $this->fallbackDir = \sys_get_temp_dir() . '/session-hooks-test-fallback-' . \bin2hex(\random_bytes(8));
        \mkdir($this->fallbackDir);

        $loader = new ClassLoader('fake-vendor-' . \bin2hex(\random_bytes(8)));
        $loader->addPsr4('', [$this->fallbackDir]);
        $loader->register();
        $this->registeredLoader = $loader;

        $hooks = $this->hooks(new FakeContainer());
        $method = new \ReflectionMethod($hooks, 'composerSourceDirectories');
        /** @var list<string> $directories */
        $directories = $method->invoke($hooks);

        self::assertContains(Path::real($this->fallbackDir), $directories);
    }

    public function testFileExtensionsFallsBackToJustPhpWhenNoParameterIsSet(): void
    {
        $hooks = $this->hooks(new FakeContainer());

        self::assertSame(['php'], $hooks->fileExtensions());
    }

    /**
     * #200: a declared MCP_RECTOR_WARM_SESSION_IGNORE directory is excluded
     * from the snapshot directorySnapshot() builds -- never walked, never
     * listed, the mirror of the watch-root wiring just above. Paired with a
     * must-fire case outside the ignored directory so a broken wiring that
     * excludes everything would not pass silently.
     */
    public function testDirectorySnapshotExcludesADeclaredIgnoreDirectory(): void
    {
        $root = Path::real(\sys_get_temp_dir()) . '/session-hooks-ignore-test-' . \bin2hex(\random_bytes(8));
        \mkdir($root . '/temp/cache', 0o777, true);
        \mkdir($root . '/src', 0o777, true);
        try {
            $hooks = $this->hooks(new FakeContainer());
            $snapshot = $hooks->directorySnapshot($root, [], [$root . '/temp/cache']);
            $snapshot->refresh();
            \clearstatcache();
            \usleep(1_100_000);

            \file_put_contents($root . '/temp/cache/entry.php', '<?php return [];');
            self::assertNull($snapshot->firstChange(), 'a declared ignore directory must not force a respawn');

            \file_put_contents($root . '/src/New.php', '<?php class New_ {}');
            self::assertSame($root . '/src', $snapshot->firstChange(), 'positive control: a change OUTSIDE the ignore directory must still be seen');
        } finally {
            self::remove($root);
        }
    }

    /**
     * #200's CONTAINS-a-root guard: a declared ignore directory that CONTAINS
     * a real root must be refused, the same as the pre-existing cache-dir
     * guard just above it in directorySnapshot() -- otherwise a project
     * misconfiguring MCP_RECTOR_WARM_SESSION_IGNORE as an ancestor of (or
     * the same path as) a root would silently stop that root from being
     * watched at all, with no error.
     */
    public function testDirectorySnapshotRefusesToIgnoreADirectoryThatContainsARoot(): void
    {
        $root = Path::real(\sys_get_temp_dir()) . '/session-hooks-ignore-guard-test-' . \bin2hex(\random_bytes(8));
        \mkdir($root . '/src', 0o777, true);
        try {
            $hooks = $this->hooks(new FakeContainer());
            // The ignore path IS the root itself -- containsAny() must refuse it.
            $snapshot = $hooks->directorySnapshot($root, [], [$root]);
            $snapshot->refresh();
            \clearstatcache();
            \usleep(1_100_000);

            \file_put_contents($root . '/src/New.php', '<?php class New_ {}');
            self::assertSame($root . '/src', $snapshot->firstChange(), 'a root must still be watched even when (mis)declared as its own ignore directory');
        } finally {
            self::remove($root);
        }
    }

    private static function remove(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);

            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        @\rmdir($path);
    }

    private function hooks(Container $phpstanContainer): SessionHooks
    {
        $factoryReflection = new \ReflectionClass(PHPStanServicesFactory::class);
        /** @var PHPStanServicesFactory $factory */
        $factory = $factoryReflection->newInstanceWithoutConstructor();
        $factoryReflection->getProperty('container')->setValue($factory, $phpstanContainer);

        $rectorContainer = new class ($factory) {
            public function __construct(private readonly PHPStanServicesFactory $factory) {}

            public function get(string $id): ?PHPStanServicesFactory
            {
                return $id === PHPStanServicesFactory::class ? $this->factory : null;
            }
        };

        return new SessionHooks($rectorContainer);
    }

    private static function fakePathRoutingParser(): PathRoutingParser
    {
        return (new \ReflectionClass(PathRoutingParser::class))->newInstanceWithoutConstructor();
    }

    private static function fakeParser(): Parser
    {
        return new class implements Parser {
            public function parseFile(string $file): array
            {
                return [];
            }

            public function parseString(string $sourceCode): array
            {
                return [];
            }
        };
    }

    private static function setRoutedParser(PathRoutingParser $routing, string $property, Parser $value): void
    {
        (new \ReflectionProperty($routing, $property))->setValue($routing, $value);
    }

    private static function getRoutedParser(PathRoutingParser $routing, string $property): object
    {
        /** @var object $value */
        $value = (new \ReflectionProperty($routing, $property))->getValue($routing);

        return $value;
    }

    private static function unsetAutoloadPathsParameter(): void
    {
        $property = new \ReflectionProperty(SimpleParameterProvider::class, 'parameters');
        /** @var array<string, mixed> $parameters */
        $parameters = $property->getValue();
        unset($parameters[Option::AUTOLOAD_PATHS]);
        $property->setValue(null, $parameters);
    }
}

/**
 * The real PHPStan\DependencyInjection\Container is an interface, so SessionHooksTest
 * implements it directly instead of mocking a concrete class -- services, by-type
 * lookups and parameters are each a plain map the test supplies.
 */
final readonly class FakeContainer implements Container
{
    /**
     * @param array<string, mixed> $services
     * @param array<class-string, object> $byType
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private array $services = [],
        private array $byType = [],
        private array $parameters = [],
    ) {}

    public function hasService(string $serviceName): bool
    {
        return \array_key_exists($serviceName, $this->services);
    }

    public function getService(string $serviceName): mixed
    {
        return $this->services[$serviceName];
    }

    public function getByType(string $className): object
    {
        // @phpstan-ignore return.type (a plain map of test doubles, not PHPStan's real DI resolution -- cannot prove the value is the generic T Container promises)
        return $this->byType[$className];
    }

    public function findServiceNamesByType(string $className): array
    {
        return [];
    }

    public function getExtensionsCollection(string $extensionInterfaceName): ExtensionsCollection
    {
        throw new \RuntimeException('FakeContainer::getExtensionsCollection() is not faked');
    }

    public function getServicesByTag(string $tagName): array
    {
        return [];
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function hasParameter(string $parameterName): bool
    {
        return \array_key_exists($parameterName, $this->parameters);
    }

    public function getParameter(string $parameterName): mixed
    {
        return $this->parameters[$parameterName];
    }
}

final class FakeReflector implements Reflector
{
    public function reflectClass(string $identifierName): \PHPStan\BetterReflection\Reflection\ReflectionClass
    {
        throw new \RuntimeException('FakeReflector::reflectClass() is not faked');
    }

    public function reflectAllClasses(): iterable
    {
        return [];
    }

    public function reflectFunction(string $identifierName): \PHPStan\BetterReflection\Reflection\ReflectionFunction
    {
        throw new \RuntimeException('FakeReflector::reflectFunction() is not faked');
    }

    public function reflectAllFunctions(): iterable
    {
        return [];
    }

    public function reflectConstant(string $identifierName): \PHPStan\BetterReflection\Reflection\ReflectionConstant
    {
        throw new \RuntimeException('FakeReflector::reflectConstant() is not faked');
    }

    public function reflectAllConstants(): iterable
    {
        return [];
    }
}

/**
 * Duck-typed stand-in for PHPStan's own (final) FileNodesFetcher result: SessionHooks
 * never checks its type, only calls getClassNodes()/getFunctionNodes()/getConstantNodes().
 */
final readonly class FakeFetchedNodes
{
    /**
     * @param array<string, list<object>> $classNodes
     * @param array<string, list<object>> $functionNodes
     * @param array<string, object> $constantNodes
     */
    public function __construct(
        private array $classNodes,
        private array $functionNodes,
        private array $constantNodes,
    ) {}

    /** @return array<string, list<object>> */
    public function getClassNodes(): array
    {
        return $this->classNodes;
    }

    /** @return array<string, list<object>> */
    public function getFunctionNodes(): array
    {
        return $this->functionNodes;
    }

    /** @return array<string, object> */
    public function getConstantNodes(): array
    {
        return $this->constantNodes;
    }
}
