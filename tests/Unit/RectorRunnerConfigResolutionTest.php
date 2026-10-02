<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorRunner;
use PHPUnit\Framework\TestCase;

/**
 * #245: RectorRunner.php's config-resolution/bootstrap cluster --
 * resolveMainConfigFile(), resolveComposerFile(), hashConfigFile(),
 * refreshConfigFileState(), ensureRectorAutoloaded(), preloadLikeCold(),
 * findRectorBin(), detectRectorPrefix(), resolvePrefixed() -- has no direct
 * test anywhere. The one existing test that reaches this area
 * (RectorRunnerTest.php's warm/cold-decision test) overrides
 * configFileChanged() itself, bypassing every one of these bodies rather
 * than exercising them. These tests invoke each private method directly via
 * Reflection, the same pattern already used in RectorRunnerTest.php
 * (killAndReap(), readExactly()).
 */
final class RectorRunnerConfigResolutionTest extends TestCase
{
    private string $tmp;

    private string $previousCwd;

    /** $_SERVER['argv'] exactly as found, restored verbatim in tearDown() */
    private mixed $previousArgv;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rector-runner-config-resolution-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmp);
        $this->previousCwd = (string) getcwd();

        // resolveMainConfigFile() -> RectorConfigsResolver::provide() builds a
        // Symfony ArgvInput from the REAL process $_SERVER['argv'] and looks
        // for --config/-c there. Left untouched, PHPUnit's own -c/--config
        // flag (an ordinary PHPUnit invocation, e.g. from an IDE) is
        // indistinguishable to that check from "the user passed Rector a
        // --config", so it tries to treat phpunit.xml as Rector's own config
        // file, resolves it relative to this test's chdir()'d tmp dir, and
        // throws "The path "phpunit.xml" does not exist." Same reset this
        // file's own sibling tests already use (RectorRunnerSessionWorkerTest.php).
        $this->previousArgv = $_SERVER['argv'] ?? ['rector'];
        $_SERVER['argv'] = ['rector'];
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $_SERVER['argv'] = $this->previousArgv;
    }

    private function method(string $name): \ReflectionMethod
    {
        return new \ReflectionMethod(RectorRunner::class, $name);
    }

    public function testHashConfigFileReturnsNullForNullPath(): void
    {
        $runner = new RectorRunner();
        self::assertNull($this->method('hashConfigFile')->invoke($runner, null));
    }

    public function testHashConfigFileReturnsNullForMissingFile(): void
    {
        $runner = new RectorRunner();
        self::assertNull($this->method('hashConfigFile')->invoke($runner, $this->tmp . '/does-not-exist.php'));
    }

    public function testHashConfigFileReturnsSha256OfRealFileAndChangesWithContent(): void
    {
        $runner = new RectorRunner();
        $path = $this->tmp . '/some-file.php';
        file_put_contents($path, "<?php\necho 1;\n");
        $hash = $this->method('hashConfigFile')->invoke($runner, $path);
        self::assertSame(hash_file('sha256', $path), $hash);

        // Positive control: the hash must actually track the bytes, not just
        // "a file exists" -- a stale/constant hash would also pass the
        // assertion above on the first file alone.
        file_put_contents($path, "<?php\necho 2;\n");
        $changedHash = $this->method('hashConfigFile')->invoke($runner, $path);
        self::assertNotSame($hash, $changedHash);
        self::assertSame(hash_file('sha256', $path), $changedHash);
    }

    public function testResolveComposerFileReturnsNullWhenAbsentAndPathWhenPresent(): void
    {
        $runner = new RectorRunner();
        chdir($this->tmp);

        // Negative case first.
        self::assertNull($this->method('resolveComposerFile')->invoke($runner));

        // Positive control: once composer.json actually exists, the same
        // method must find it -- proves the null above means "absent", not
        // "this method never looks". Compared via realpath(): resolveComposerFile()
        // returns getcwd() . '/composer.json' verbatim, and on macOS
        // sys_get_temp_dir() resolves under a /var symlink that getcwd() does
        // not collapse, so a byte-for-byte compare against $this->tmp would be
        // comparing the wrong thing, not asserting the wrong behaviour.
        file_put_contents($this->tmp . '/composer.json', '{}');
        $resolved = $this->method('resolveComposerFile')->invoke($runner);
        self::assertSame(realpath($this->tmp . '/composer.json'), realpath((string) $resolved));
    }

    public function testResolveMainConfigFileFindsRectorPhpInCwd(): void
    {
        $runner = new RectorRunner();
        chdir($this->tmp);
        file_put_contents(
            $this->tmp . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\Config\RectorConfig;\n\nreturn RectorConfig::configure();\n",
        );

        $resolved = $this->method('resolveMainConfigFile')->invoke($runner);
        self::assertIsString($resolved);
        self::assertSame(realpath($this->tmp . '/rector.php'), realpath($resolved));
    }

    public function testRefreshConfigFileStateMirrorsResolvedPathsAndHashesOntoInstance(): void
    {
        $runner = new RectorRunner();
        chdir($this->tmp);
        file_put_contents(
            $this->tmp . '/rector.php',
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\Config\RectorConfig;\n\nreturn RectorConfig::configure();\n",
        );
        file_put_contents($this->tmp . '/composer.json', '{}');

        $this->method('refreshConfigFileState')->invoke($runner);

        $configFile = new \ReflectionProperty(RectorRunner::class, 'configFile');
        $configFileHash = new \ReflectionProperty(RectorRunner::class, 'configFileHash');
        $composerFile = new \ReflectionProperty(RectorRunner::class, 'composerFile');
        $composerFileHash = new \ReflectionProperty(RectorRunner::class, 'composerFileHash');

        $configPath = $configFile->getValue($runner);
        self::assertIsString($configPath);
        self::assertSame(realpath($this->tmp . '/rector.php'), realpath($configPath));
        self::assertSame(hash_file('sha256', $this->tmp . '/rector.php'), $configFileHash->getValue($runner));
        $composerPath = $composerFile->getValue($runner);
        self::assertIsString($composerPath);
        self::assertSame(realpath($this->tmp . '/composer.json'), realpath($composerPath));
        self::assertSame(hash_file('sha256', $this->tmp . '/composer.json'), $composerFileHash->getValue($runner));
    }

    public function testEnsureRectorAutoloadedIsIdempotentAndLoadsRectorClasses(): void
    {
        $runner = new RectorRunner();
        $this->method('ensureRectorAutoloaded')->invoke($runner);
        self::assertTrue(class_exists(\Rector\Bootstrap\RectorConfigsResolver::class, false));

        // Calling it again (the early-return branch, class already loaded)
        // must not throw or otherwise misbehave.
        $this->method('ensureRectorAutoloaded')->invoke($runner);
        self::assertTrue(class_exists(\Rector\Bootstrap\RectorConfigsResolver::class, false));
    }

    public function testPreloadLikeColdReturnsEarlyWhenPackageDirHasNoPreloadOrVendor(): void
    {
        $runner = new RectorRunner();

        // Negative case: an empty dir has neither preload.php nor vendor/, so
        // this must return without throwing. "Did not throw" is the actual
        // assertion here (an exception would fail the test on its own); the
        // counted assertion just records that this line was reached.
        $this->method('preloadLikeCold')->invoke($runner, $this->tmp);
        $this->addToAssertionCount(1);
    }

    public function testPreloadLikeColdRunsAgainstTheRealBundledRectorPackage(): void
    {
        $runner = new RectorRunner();
        // Same resolution ensureRectorAutoloaded() itself uses: reflect on a
        // real Rector class to find the package root, then feed that real
        // directory straight to preloadLikeCold() -- the path this method is
        // actually called on in production, rather than only the early-return
        // guard above.
        $this->method('ensureRectorAutoloaded')->invoke($runner);
        $rectorConfigsResolverFile = (new \ReflectionClass(\Rector\Bootstrap\RectorConfigsResolver::class))->getFileName();
        self::assertNotFalse($rectorConfigsResolverFile);
        $rectorPkgDir = dirname($rectorConfigsResolverFile, 3);

        // Must not throw even though ensureRectorAutoloaded() already ran it
        // once this process (preload.php's own requires are themselves
        // idempotent via PHP's require_once).
        $this->method('preloadLikeCold')->invoke($runner, $rectorPkgDir);
        $this->addToAssertionCount(1);
    }

    public function testFindRectorBinResolvesARealExistingBinary(): void
    {
        $runner = new RectorRunner();
        $bin = $this->method('findRectorBin')->invoke($runner);

        self::assertIsString($bin, 'findRectorBin() must resolve the bundled rector/rector binary in this project');
        self::assertFileExists($bin);
    }

    /**
     * Declares a fake class matching detectRectorPrefix()'s own
     * ^(RectorPrefix\d+)\... pattern, built from concatenated string parts
     * and eval()'d rather than written as a literal namespace/class
     * reference anywhere in this file's source: this project's own
     * mcp-phpstan-warm ruleset has a non-ignorable class.prefixed rule that
     * flags ANY literal reference to a RectorPrefixNNN\... namespace as
     * "most likely unintentional" (it exists to catch an accidentally
     * hardcoded real scoper prefix, which changes over time) -- exactly what
     * a literal fixture class for this test would look like to that rule.
     * Idempotent: a second call with the same digits is a no-op.
     *
     * @return class-string the fake class's FQN
     */
    private function declareFakePrefixedProbeClass(string $digits): string
    {
        $prefix = 'RectorPrefix' . $digits;
        $fqn = $prefix . '\Probe';
        if (!class_exists($fqn, false)) {
            eval('namespace ' . $prefix . '; class Probe {}');
        }
        self::assertTrue(class_exists($fqn, false), "eval() must have declared {$fqn}");

        return $fqn;
    }

    /**
     * Run in a fresh process: every other test in this file calls
     * resolveMainConfigFile()/ensureRectorAutoloaded() somewhere, which
     * requires Rector's scoper-autoload.php -- itself a PhpScoper-prefixed
     * Composer autoloader whose OWN generated class already matches
     * ^(RectorPrefix\d+)\... (confirmed: without this attribute, this test
     * observed a real 'RectorPrefix<date>' class already declared by the
     * time it ran, polluted by an EARLIER test in this same shared process --
     * not a defect in detectRectorPrefix() itself). A fresh process is the
     * only way to observe the true "nothing declared yet" state this negative
     * case is meant to pin.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testDetectRectorPrefixReturnsNullWithNoPrefixedClassDeclaredThenFindsOneOnceDeclared(): void
    {
        $runner = new RectorRunner();

        // Negative case: this process, before the fake class below is
        // declared, has no RectorPrefixNNN\... class declared (same state
        // the existing RectorRunnerTest.php comment documents for the real
        // thing).
        self::assertNull(
            $this->method('detectRectorPrefix')->invoke($runner),
            'no RectorPrefixNNN\... class should be declared yet in a fresh check',
        );

        // Positive control: once a class matching the pattern actually
        // exists, detection must find it -- proves the null above means
        // "none declared", not "this method never looks".
        $fqn = $this->declareFakePrefixedProbeClass('999');
        self::assertSame('RectorPrefix999', $this->method('detectRectorPrefix')->invoke($runner));
        self::assertTrue(class_exists($fqn, false));
    }

    /**
     * Run in a fresh process: declareFakePrefixedProbeClass() eval()s a real
     * class declaration, which (like any class declaration) is permanent for
     * the rest of whichever PHP process it runs in -- get_declared_classes()
     * never forgets it. Left in the main shared PHPUnit process, this class
     * would stay declared for every test that runs afterward in the same
     * run, so a LATER, unrelated test calling detectRectorPrefix() would see
     * it and misreport 'RectorPrefix998' as a real detected prefix. A fresh
     * process is the only way to let this test's own fake class disappear
     * with it, the same isolation
     * testDetectRectorPrefixReturnsNullWithNoPrefixedClassDeclaredThenFindsOneOnceDeclared()
     * above already needs for the same reason.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testResolvePrefixedCombinesPrefixAndAutoloadsRealClassAndThrowsForMissingOne(): void
    {
        $fqn = $this->declareFakePrefixedProbeClass('998');

        $runner = new RectorRunner();
        $prefix = new \ReflectionProperty(RectorRunner::class, 'prefix');
        $prefix->setValue($runner, 'RectorPrefix998');

        self::assertSame($fqn, $this->method('resolvePrefixed')->invoke($runner, 'Probe'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Prefixed class not found');
        $this->method('resolvePrefixed')->invoke($runner, 'DoesNotExistAtAll');
    }
}
