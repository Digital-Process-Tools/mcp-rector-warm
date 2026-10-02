<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Cast\RecastingRemovalRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\TypeDeclaration\Rector\While_\WhileNullableToInstanceofRector;

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
    ->withPreparedSets(deadCode: true, typeDeclarations: true, privatization: true)
    ->withAttributesSets(phpunit: true)
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
        // #286: ini_get() returns string|false. PHPStan's ini_get() extension
        // types a known directive as plain string, so Rector reads the
        // (string) cast on ini_get('memory_limit') in the session retire check
        // as dead and drops it. Kept: a false there must still become ''.
        RecastingRemovalRector::class => [
            __DIR__ . '/src/RectorRunner.php',
        ],
        // #286: runInSession() returns the session child's decoded reply under
        // a `@var` on the return statement. Rector reads a `@var` with no
        // variable as dead, but PHPStan applies it, and the reply is not
        // validated against that shape (the session's fork route can also send
        // an error-shaped result). Dropping the tag is a type change on the
        // warm path; validating the reply is a behaviour change, out of scope.
        RemoveNonExistingVarAnnotationRector::class => [
            __DIR__ . '/src/RectorRunner.php',
        ],
        // #286: these php_user_filter::filter() loops compare
        // stream_bucket_make_writeable() with null. Rector rewrites that to
        // `instanceof \stdClass`, which is what it returns up to PHP 8.3, but on
        // PHP 8.4 it returns a StreamBucket, so the loop would never run there.
        WhileNullableToInstanceofRector::class => [
            __DIR__ . '/tests/Unit/ProtocolStdoutIsolatorTest.php',
            __DIR__ . '/tests/Unit/RectorRunnerNoSessionFrameTest.php',
        ],
        // #286: AlwaysFailingPhpWrapper::stream_open() is a stream-wrapper
        // protocol method PHP calls with four arguments; its signature stays
        // the documented one even though the stub ignores them.
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__ . '/tests/Unit/ProtocolStdoutIsolatorTest.php',
        ],
    ])
    ->withCache(cacheDirectory: sys_get_temp_dir() . '/mcp-rector-warm-rector-cache');
