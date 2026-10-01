<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Warm;

use PhpParser\Node;
use PHPStan\BetterReflection\Identifier\Identifier;
use PHPStan\BetterReflection\Identifier\IdentifierType;
use PHPStan\BetterReflection\Reflector\Reflector;
use PHPStan\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use PHPStan\BetterReflection\SourceLocator\Type\SourceLocator;
use PHPStan\DependencyInjection\Container;
use PHPStan\Parser\Parser;
use PHPStan\Parser\PathRoutingParser;
use PHPStan\Reflection\BetterReflection\BetterReflectionSourceLocatorFactory;
use PHPStan\Reflection\BetterReflection\SourceLocator\FileNodesFetcher;
use PHPStan\Reflection\BetterReflection\SourceLocator\OptimizedDirectorySourceLocatorFactory;
use PHPStan\Reflection\BetterReflection\SourceLocator\OptimizedSingleFileSourceLocatorRepository;
use Rector\Configuration\Option;
use Rector\Configuration\Parameter\SimpleParameterProvider;
use Rector\NodeTypeResolver\DependencyInjection\PHPStanServicesFactory;

/**
 * #185: everything the session child needs from Rector's and PHPStan's own
 * services, in one place, reached only through their public API except where
 * noted -- so a Rector or PHPStan upgrade that moves any of it fails here, at
 * session start, and the runner falls back to fork-per-call instead of running
 * a session whose tracking silently stopped working.
 *
 * rector/rector ships PHPStan (and BetterReflection inside it) as phpstan.phar,
 * so nothing here can patch or shadow a PHPStan class. The one hook is a
 * decorator installed on a live service instance: PHPStan's PathRoutingParser,
 * which Rector's parser.neon wires in as `defaultAnalysisParser` -- the parser
 * FileNodesFetcher (every BetterReflection source locator), FileTypeMapper,
 * the trait-use handler and the class reflection extension all parse through.
 * Its three inner parsers are private properties; each is wrapped in a
 * TrackingParser through reflection. installParserHook() refuses (throws)
 * unless the container is shaped exactly that way.
 */
final class SessionHooks implements SymbolResolver
{
    private const ROUTED_PARSERS = ['currentPhpVersionRichParser', 'currentPhpVersionSimpleParser', 'php8Parser'];

    /** Composer's own metadata: `composer install/update/dump-autoload` rewrites these. */
    private const COMPOSER_METADATA = [
        'installed.json', 'installed.php', 'autoload_classmap.php', 'autoload_psr4.php',
        'autoload_namespaces.php', 'autoload_files.php', 'autoload_static.php', 'autoload_real.php',
    ];

    private readonly Container $phpstan;

    private ?SourceLocator $standard = null;

    private ?SourceLocator $autoloadPaths = null;

    private ?Reflector $reflector = null;

    public function __construct(object $rectorContainer)
    {
        if (!\method_exists($rectorContainer, 'get')) {
            throw new \RuntimeException('the Rector container has no get()');
        }
        $factory = $rectorContainer->get(PHPStanServicesFactory::class);
        if (!$factory instanceof PHPStanServicesFactory) {
            throw new \RuntimeException('Rector did not return its PHPStanServicesFactory');
        }
        $this->phpstan = $factory->getContainer();
    }

    /**
     * Report every file PHPStan's analysis parser is about to parse to $onParseFile.
     *
     * @param \Closure(string): void $onParseFile
     */
    public function installParserHook(\Closure $onParseFile): void
    {
        $routing = $this->phpstan->getService('pathRoutingParser');
        if (!$routing instanceof PathRoutingParser) {
            throw new \RuntimeException('PHPStan service pathRoutingParser is not a PathRoutingParser');
        }
        if ($this->phpstan->getService('defaultAnalysisParser') !== $routing) {
            // Without Rector's parser.neon, defaultAnalysisParser is a separate
            // CachedParser and parses could bypass the routing parser entirely.
            throw new \RuntimeException('defaultAnalysisParser is not the pathRoutingParser instance');
        }
        foreach (self::ROUTED_PARSERS as $property) {
            $reflection = new \ReflectionProperty($routing, $property);
            $inner = $reflection->getValue($routing);
            if ($inner instanceof TrackingParser) {
                continue;
            }
            if (!$inner instanceof Parser) {
                throw new \RuntimeException("PathRoutingParser::\${$property} is not a Parser");
            }
            $reflection->setValue($routing, new TrackingParser($inner, $onParseFile));
        }
    }

    public function declaredSymbols(string $path): array
    {
        $fetched = $this->phpstan->getByType(FileNodesFetcher::class)->fetchNodes($path);
        $symbols = [];
        foreach ($fetched->getClassNodes() as $key => $nodes) {
            $symbols[] = [self::CLASS_LIKE, self::nameOf($nodes, $key)];
        }
        foreach ($fetched->getFunctionNodes() as $key => $nodes) {
            $symbols[] = [self::FUNCTION, self::nameOf($nodes, $key)];
        }
        foreach (\array_keys($fetched->getConstantNodes()) as $key) {
            $symbols[] = [self::CONSTANT, (string) $key];
        }

        return $symbols;
    }

    public function resolveStandard(string $kind, string $name): ?string
    {
        // PHPStan's own chain, built the way PHPStan builds it (the same factory
        // RectorBetterReflectionSourceLocatorFactory puts in front of Rector's
        // dynamic locator), but a separate instance: its lookups never land in
        // the memo the analysis itself reads.
        $this->standard ??= $this->phpstan->getByType(BetterReflectionSourceLocatorFactory::class)->create();

        return $this->locate($this->standard, $kind, $name);
    }

    public function resolveAutoloadPaths(string $kind, string $name): ?string
    {
        if ($this->autoloadPaths === null) {
            $locators = [];
            foreach ($this->autoloadPathList() as $path) {
                if (\is_dir($path)) {
                    $locators[] = $this->phpstan->getByType(OptimizedDirectorySourceLocatorFactory::class)->createByDirectory($path);
                } elseif (\is_file($path)) {
                    $locators[] = $this->phpstan->getByType(OptimizedSingleFileSourceLocatorRepository::class)->getOrCreate($path);
                }
            }
            $this->autoloadPaths = new AggregateSourceLocator($locators);
        }

        return $this->locate($this->autoloadPaths, $kind, $name);
    }

    /**
     * Rector's withAutoloadPaths() entries that exist, as real paths.
     *
     * @return list<string>
     */
    public function autoloadPathList(): array
    {
        return self::existing(self::rectorArrayParameter(Option::AUTOLOAD_PATHS));
    }

    /**
     * Directories PHPStan scans for symbols file by file, whatever their names:
     * withAutoloadPaths() directories and PHPStan's own scanDirectories. Any
     * PHP file under them is an input, edited or not.
     *
     * @return list<string>
     */
    public function scannedDirectories(): array
    {
        $directories = [];
        foreach ([...$this->autoloadPathList(), ...self::existing($this->arrayParameter('scanDirectories'))] as $path) {
            if (\is_dir($path)) {
                $directories[] = $path;
            }
        }

        return \array_values(\array_unique($directories));
    }

    /**
     * Files that are inputs from the moment the session starts: Composer's
     * metadata for every registered vendor directory, PHPStan's stub and scan
     * files, withAutoloadPaths() files, and every PHP file under a scanned
     * directory.
     *
     * @return list<string>
     */
    public function staticInputFiles(): array
    {
        $files = [];
        foreach ($this->vendorDirectories() as $vendor) {
            foreach (self::COMPOSER_METADATA as $name) {
                if (\is_file($vendor . '/composer/' . $name)) {
                    $files[] = $vendor . '/composer/' . $name;
                }
            }
        }
        foreach ([...$this->arrayParameter('stubFiles'), ...$this->arrayParameter('scanFiles'), ...$this->autoloadPathList()] as $file) {
            if (\is_file($file)) {
                $files[] = $file;
            }
        }
        $extensions = $this->fileExtensions();
        foreach ($this->scannedDirectories() as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && \in_array(\strtolower($file->getExtension()), $extensions, true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return \array_values(\array_unique($files));
    }

    /**
     * The directory snapshot for this project, not yet refreshed: the working
     * directory and every non-vendor Composer source root as plain roots, the
     * scanned directories as full roots, declared watch directories as watch
     * roots; vendor directories (their Composer metadata is tracked instead)
     * and Rector's caches excluded.
     *
     * @param list<string> $watchPaths absolute (RectorRunner::sessionWatchPaths())
     */
    public function directorySnapshot(string $workingDirectory, array $watchPaths = []): DirectorySnapshot
    {
        $vendors = $this->vendorDirectories();
        $roots = [$workingDirectory];
        foreach ($this->composerSourceDirectories() as $directory) {
            if (!self::isUnderAny($directory, $vendors)) {
                $roots[] = $directory;
            }
        }
        $fullRoots = $this->scannedDirectories();
        $caches = [
            SimpleParameterProvider::provideStringParameter(Option::CACHE_DIR, ''),
            SimpleParameterProvider::provideStringParameter(Option::CONTAINER_CACHE_DIRECTORY, ''),
            (string) ($this->phpstan->hasParameter('tmpDir') ? $this->phpstan->getParameter('tmpDir') : ''),
        ];
        $excluded = $vendors;
        foreach (self::existing($caches) as $cache) {
            // Never exclude a directory that CONTAINS a root (the project can
            // live under the system temp dir PHPStan uses as its tmpDir).
            if (!self::containsAny($cache, [...$roots, ...$fullRoots])) {
                $excluded[] = $cache;
            }
        }

        // Declared watch directories (MCP_RECTOR_WARM_SESSION_WATCH): any file
        // appearing there counts, whatever its extension.
        $watchRoots = \array_values(\array_filter($watchPaths, is_dir(...)));

        return new DirectorySnapshot($roots, $fullRoots, $excluded, [...$this->fileExtensions(), 'php', 'inc'], 100_000, $watchRoots);
    }

    /**
     * Where PHPStan and Rector keep their own caches (see WarmSession's constructor).
     *
     * @return list<string>
     */
    public function cacheDirectories(): array
    {
        $tmpDir = (string) ($this->phpstan->hasParameter('tmpDir') ? $this->phpstan->getParameter('tmpDir') : '');

        return self::existing([
            $tmpDir === '' ? '' : $tmpDir . '/cache/PHPStan',
            SimpleParameterProvider::provideStringParameter(Option::CACHE_DIR, ''),
            SimpleParameterProvider::provideStringParameter(Option::CONTAINER_CACHE_DIRECTORY, ''),
        ]);
    }

    /**
     * @return list<string>
     */
    public function fileExtensions(): array
    {
        $extensions = [...$this->arrayParameter('fileExtensions'), ...self::rectorArrayParameter(Option::FILE_EXTENSIONS)];
        $extensions = \array_map(static fn(mixed $extension): string => \strtolower(\ltrim((string) $extension, '.')), $extensions);

        return \array_values(\array_unique(['php', ...$extensions]));
    }

    /**
     * @return list<string>
     */
    private function vendorDirectories(): array
    {
        if (!\class_exists(\Composer\Autoload\ClassLoader::class, false)) {
            return [];
        }

        return self::existing(\array_keys(\Composer\Autoload\ClassLoader::getRegisteredLoaders()));
    }

    /**
     * @return list<string>
     */
    private function composerSourceDirectories(): array
    {
        if (!\class_exists(\Composer\Autoload\ClassLoader::class, false)) {
            return [];
        }
        $directories = [];
        foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ([...\array_values($loader->getPrefixesPsr4()), ...\array_values($loader->getPrefixes())] as $paths) {
                foreach ((array) $paths as $path) {
                    $directories[] = (string) $path;
                }
            }
            foreach ([...$loader->getFallbackDirsPsr4(), ...$loader->getFallbackDirs()] as $path) {
                $directories[] = (string) $path;
            }
        }

        return self::existing($directories);
    }

    private function locate(SourceLocator $locator, string $kind, string $name): ?string
    {
        $this->reflector ??= $this->phpstan->getService('betterReflectionReflector');
        $type = match ($kind) {
            self::FUNCTION => IdentifierType::IDENTIFIER_FUNCTION,
            self::CONSTANT => IdentifierType::IDENTIFIER_CONSTANT,
            default => IdentifierType::IDENTIFIER_CLASS,
        };
        try {
            $reflection = $locator->locateIdentifier($this->reflector, new Identifier($name, new IdentifierType($type)));
        } catch (\Throwable) {
            // Unanswerable counts as "not found": the caller then declines, the safe side.
            return null;
        }
        if ($reflection === null) {
            return null;
        }
        $file = \method_exists($reflection, 'getFileName') ? $reflection->getFileName() : null;

        return \is_string($file) ? $file : '';
    }

    /**
     * The declared name with its original case: a case-sensitive autoloader
     * (most custom ones) cannot find `app\child` for `App\Child`.
     *
     * @param list<object> $nodes
     */
    private static function nameOf(array $nodes, string|int $key): string
    {
        foreach ($nodes as $fetched) {
            $node = \method_exists($fetched, 'getNode') ? $fetched->getNode() : null;
            if ($node instanceof Node && \property_exists($node, 'namespacedName') && $node->namespacedName instanceof Node\Name) {
                return $node->namespacedName->toString();
            }
        }

        return (string) $key;
    }

    /**
     * @return list<string>
     */
    private function arrayParameter(string $name): array
    {
        if (!$this->phpstan->hasParameter($name)) {
            return [];
        }
        $value = $this->phpstan->getParameter($name);

        return \is_array($value) ? \array_values(\array_filter($value, is_string(...))) : [];
    }

    /**
     * @return list<mixed>
     */
    private static function rectorArrayParameter(string $name): array
    {
        return SimpleParameterProvider::hasParameter($name)
            ? \array_values(SimpleParameterProvider::provideArrayParameter($name))
            : [];
    }

    /**
     * @param array<mixed> $paths
     * @return list<string>
     */
    private static function existing(array $paths): array
    {
        $existing = [];
        foreach ($paths as $path) {
            if (!\is_string($path) || $path === '' || \str_starts_with($path, 'phar://')) {
                continue;
            }
            $real = Path::real($path);
            if ($real !== null) {
                $existing[] = $real;
            }
        }

        return \array_values(\array_unique($existing));
    }

    /**
     * @param list<string> $parents
     */
    private static function isUnderAny(string $path, array $parents): bool
    {
        foreach ($parents as $parent) {
            if (Path::isUnder(Path::normalise($path), Path::normalise($parent))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $paths
     */
    private static function containsAny(string $directory, array $paths): bool
    {
        foreach ($paths as $path) {
            if (self::isUnderAny(Path::real($path) ?? $path, [$directory])) {
                return true;
            }
        }

        return false;
    }
}
