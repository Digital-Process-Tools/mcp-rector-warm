<?php

// #192: stands in for a project whose Composer autoloader can resolve PhpParser\*
// classes from ITS OWN nikic/php-parser copy -- laravel/framework and symfony/symfony
// both ship one, and phpstan.phar carries another. Registered with prepend = true, as
// Composer's generated autoload_real.php does, so once this file is loaded it is asked
// before Rector's own autoloader. Cold `rector process` requires Rector's preload.php
// (every php-parser class, from Rector's own copy) before it loads this file, so this
// loader is never asked for a php-parser class there; warm must behave the same.
spl_autoload_register(static function (string $class): void {
    if ($class === 'PhpParser\\NodeVisitorAbstract') {
        require __DIR__ . '/nikic/php-parser/lib/PhpParser/NodeVisitorAbstract.php';
    }
}, true, true);
