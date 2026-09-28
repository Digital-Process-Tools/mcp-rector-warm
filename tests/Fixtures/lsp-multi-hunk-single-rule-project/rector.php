<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withRules([RemoveUnusedPrivatePropertyRector::class]);
