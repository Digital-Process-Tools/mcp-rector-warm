<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Composer\Autoload\ClassLoader;
use Dpt\McpRectorWarm\RectorRunner;
use PHPUnit\Framework\TestCase;

/**
 * #190 self-review finding: the Composer half of remapCopyClassesInAutoloader()
 * -- "a real Composer project ... ClassLoader::addClassMap()" per the fix's own
 * docblock -- had no positive control anywhere in this diff. tests/E2E/
 * test_lsp_session.py's AUTOLOAD fixture is a hand-rolled spl_autoload_register(),
 * never a real Composer\Autoload\ClassLoader, so that branch could do nothing
 * and every other test here would still pass. A real ClassLoader -- this
 * project's own vendor/composer/ClassLoader.php, the same class every
 * Composer-based target project registers -- closes that gap directly,
 * without a full Composer-generated fixture project.
 *
 * remapCopyClassesInAutoloader() mutates two pieces of process-global state
 * unconditionally -- every CURRENTLY REGISTERED ClassLoader (not only this
 * test's own throwaway one; PHPUnit's own bootstrap registers a real one from
 * vendor/autoload.php), and the global spl_autoload_register() queue with a
 * fresh closure on every call -- so every test here cleans both up in
 * `finally`, not only its own fixture loader (second self-review round,
 * eb66c2f review findings #1/#2).
 */
final class RectorRunnerComposerRemapTest extends TestCase
{
    private const COPY_DECLARES = 'RectorRunnerComposerRemapFixture\\Foo';

    public function testTheCopyWinsOverAClassmapEntryPointingAtTheOriginal(): void
    {
        $dir = \sys_get_temp_dir() . '/mcp-rector-warm-190-' . \bin2hex(\random_bytes(4));
        \mkdir($dir);
        $original = $dir . '/Original.php';
        $copy = $dir . '/Copy.php';
        \file_put_contents($original, "<?php\nnamespace RectorRunnerComposerRemapFixture;\nclass Foo {}\n");
        \file_put_contents($copy, "<?php\nnamespace RectorRunnerComposerRemapFixture;\nclass Foo {}\n");

        // ClassLoader::register() only adds itself to getRegisteredLoaders()
        // -- what remapCopyClassesInAutoloader() iterates -- when $vendorDir
        // is non-null (vendor/composer/ClassLoader.php:387-401); a bare
        // `new ClassLoader()` is silently invisible to it.
        $loader = new ClassLoader($dir);
        $loader->addClassMap([self::COPY_DECLARES => $original]);
        $loader->register();

        $beforeAutoloaders = \spl_autoload_functions();
        try {
            // Precondition: the classmap points at the stale original before
            // the remap runs -- exactly the shape PHPStan's standard
            // reflection chain would resolve against (#190's own mechanism).
            self::assertSame($original, $loader->findFile(self::COPY_DECLARES));

            (new \ReflectionMethod(RectorRunner::class, 'remapCopyClassesInAutoloader'))->invoke(null, $copy);

            self::assertSame($copy, $loader->findFile(self::COPY_DECLARES));
        } finally {
            $this->unregisterLeakedAutoloaders($beforeAutoloaders);
            $this->removeFromEveryOtherRegisteredLoader(self::COPY_DECLARES);
            $loader->unregister();
            \unlink($original);
            \unlink($copy);
            \rmdir($dir);
        }
    }

    public function testAClassTheCopyDoesNotDeclareIsUntouched(): void
    {
        // Must not fire (positive control for the test above): a classmap
        // entry for an unrelated class is left exactly as it was.
        $dir = \sys_get_temp_dir() . '/mcp-rector-warm-190-' . \bin2hex(\random_bytes(4));
        \mkdir($dir);
        $unrelated = $dir . '/Unrelated.php';
        $copy = $dir . '/Copy.php';
        \file_put_contents($unrelated, "<?php\nnamespace RectorRunnerComposerRemapFixture;\nclass Untouched {}\n");
        \file_put_contents($copy, "<?php\nnamespace RectorRunnerComposerRemapFixture;\nclass Foo {}\n");

        // ClassLoader::register() only adds itself to getRegisteredLoaders()
        // -- what remapCopyClassesInAutoloader() iterates -- when $vendorDir
        // is non-null (vendor/composer/ClassLoader.php:387-401); a bare
        // `new ClassLoader()` is silently invisible to it.
        $loader = new ClassLoader($dir);
        $loader->addClassMap(['RectorRunnerComposerRemapFixture\\Untouched' => $unrelated]);
        $loader->register();

        $beforeAutoloaders = \spl_autoload_functions();
        try {
            (new \ReflectionMethod(RectorRunner::class, 'remapCopyClassesInAutoloader'))->invoke(null, $copy);

            self::assertSame($unrelated, $loader->findFile('RectorRunnerComposerRemapFixture\\Untouched'));
        } finally {
            $this->unregisterLeakedAutoloaders($beforeAutoloaders);
            // The copy still declares Foo (self::COPY_DECLARES), so the
            // method under test adds that entry everywhere too, including
            // loaders this test never registered.
            $this->removeFromEveryOtherRegisteredLoader(self::COPY_DECLARES);
            $loader->unregister();
            \unlink($unrelated);
            \unlink($copy);
            \rmdir($dir);
        }
    }

    /**
     * @param list<callable> $before spl_autoload_functions() captured before
     *     the method under test ran
     */
    private function unregisterLeakedAutoloaders(array $before): void
    {
        foreach (\spl_autoload_functions() as $function) {
            if (!\in_array($function, $before, true)) {
                \spl_autoload_unregister($function);
            }
        }
    }

    /**
     * remapCopyClassesInAutoloader() calls addClassMap() on EVERY currently
     * registered ClassLoader, including ones this test never created -- most
     * notably PHPUnit's own, booted from this project's vendor/autoload.php
     * (phpunit.xml's bootstrap). Strips $class back out of every loader's
     * private classMap via reflection, so a fixture-only FQCN never lingers
     * in the real, permanently-registered project autoloader for the rest
     * of this PHPUnit process.
     */
    private function removeFromEveryOtherRegisteredLoader(string $class): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $property = new \ReflectionProperty($loader, 'classMap');
            $map = $property->getValue($loader);
            if (isset($map[$class])) {
                unset($map[$class]);
                $property->setValue($loader, $map);
            }
        }
    }
}
