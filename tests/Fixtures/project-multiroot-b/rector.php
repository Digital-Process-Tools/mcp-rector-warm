<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// #107 fixture: deliberately a DIFFERENT ruleset than
// tests/Fixtures/project's own rector.php (php82 only, no dead-code
// removal) -- MultiRootDiagnosticsSourceIntegrationTest proves a document
// here is diagnosed against THIS rector.php, never the other folder's.
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withPhpSets(php82: true)
    ->withPreparedSets(deadCode: true);
