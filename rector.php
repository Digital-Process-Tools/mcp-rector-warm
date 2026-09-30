<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;

// Dogfooding config (#201): this is what our own validator runs on every PHP
// edit in this repo, via supertool's `rector-mcp` validator pointed at this
// checkout's own bin/mcp-rector-warm.
//
// Deliberately conservative and deliberately empty of any top-level function
// or class declaration: rector.php is loaded once per warm daemon boot and,
// per #31 (see RectorRunnerTest::testRunWithoutForkSupportAlwaysRunsColdAndNeverBootsInPlace),
// a bootstrap file that declares a symbol breaks a second in-process reboot of
// the SAME PHP process with a fatal "cannot redeclare" -- exactly the failure
// mode this dogfooding exercise exists to catch for our own users, so this
// file must not become an instance of it.
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/bin',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php82: true)
    ->withSkip([
        __DIR__ . '/tests/Fixtures',
        // RectorRunnerSkipAsTest's classResolver() stub is deliberately mutable:
        // RectorRunner::overwriteResolved() rewrites the property via
        // ReflectionProperty::setValue(), mirroring Rector's own (non-readonly)
        // SkippedClassResolver. Marking it readonly here makes that write throw
        // and silently disables the skip mechanism this test verifies.
        ReadOnlyPropertyRector::class => [
            __DIR__ . '/tests/Unit/RectorRunnerSkipAsTest.php',
        ],
    ])
    ->withCache(cacheDirectory: sys_get_temp_dir() . '/mcp-rector-warm-rector-cache');
