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
 */
final class RectorRunnerComposerRemapTest extends TestCase
{
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
        $loader->addClassMap(['RectorRunnerComposerRemapFixture\\Foo' => $original]);
        $loader->register();

        try {
            // Precondition: the classmap points at the stale original before
            // the remap runs -- exactly the shape PHPStan's standard
            // reflection chain would resolve against (#190's own mechanism).
            self::assertSame($original, $loader->findFile('RectorRunnerComposerRemapFixture\\Foo'));

            (new \ReflectionMethod(RectorRunner::class, 'remapCopyClassesInAutoloader'))->invoke(null, $copy);

            self::assertSame($copy, $loader->findFile('RectorRunnerComposerRemapFixture\\Foo'));
        } finally {
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

        try {
            (new \ReflectionMethod(RectorRunner::class, 'remapCopyClassesInAutoloader'))->invoke(null, $copy);

            self::assertSame($unrelated, $loader->findFile('RectorRunnerComposerRemapFixture\\Untouched'));
        } finally {
            $loader->unregister();
            \unlink($unrelated);
            \unlink($copy);
            \rmdir($dir);
        }
    }
}
