<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withRules([ReadOnlyClassRector::class]);
