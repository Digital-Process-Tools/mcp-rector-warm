<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// #107 fixture: the OTHER folder's rector.php (deadCode: true) must never
// apply here -- this one deliberately has no dead-code rule at all.
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withPhpSets(php82: true);
