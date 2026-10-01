<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

// #230: one-time dogfooding config, same spirit as rector.php and
// phpstan.neon. `name('*.php')` means Finder would otherwise skip
// bin/mcp-rector-warm and bin/rector-warm-lsp -- both extensionless --
// so they are added explicitly via append(), which is not subject to
// the name() filter.
$finder = (new Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/bin',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->exclude('Fixtures')
    ->append([
        __DIR__ . '/bin/mcp-rector-warm',
        __DIR__ . '/bin/rector-warm-lsp',
    ]);

// @PER-CS2.0 only: no risky rules. The project's own rector.php runs
// Rector's php82 set over the same paths (#201 dogfooding), and nothing
// in @PER-CS2.0 reaches into behaviour -- it is a pure formatting
// standard -- so there is no fixer-vs-rector conflict to resolve here.
return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS2.0' => true,
    ])
    ->setFinder($finder);
