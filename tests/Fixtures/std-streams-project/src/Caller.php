<?php

declare(strict_types=1);

final class Caller
{
    public function __construct(
        private readonly string $name,
    ) {
    }

    public function describe(): string
    {
        // debug_backtrace()'s default argument is a constant declared in the same
        // PhpStorm stub file as STDIN, STDOUT and STDERR: on an empty PHPStan cache,
        // resolving it builds stub reflection for all of them (#192).
        $stack = debug_backtrace();

        return $this->name . ' ' . count($stack);
    }
}
