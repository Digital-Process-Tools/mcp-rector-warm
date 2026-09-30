<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Warm;

/**
 * #185: the three questions the session child asks PHPStan before it analyses a
 * file itself (WarmSession::route()). SessionHooks answers them from the real
 * PHPStan container; the unit tests answer from fixed maps.
 */
interface SymbolResolver
{
    /** The kind of a symbol a file declares, as the symbol lookups name it. */
    public const CLASS_LIKE = 'class';
    public const FUNCTION = 'function';
    public const CONSTANT = 'constant';

    /**
     * The class-likes, functions and constants $path declares, as PHPStan's own
     * file scan (FileNodesFetcher) sees them.
     *
     * @return list<array{0: string, 1: string}> [kind, name] pairs
     */
    public function declaredSymbols(string $path): array;

    /**
     * Where PHPStan's standard source locator chain -- everything a cold run
     * consults BEFORE the analysed file itself -- finds $name: its file, '' when
     * found with no file (a PHP internal), null when not found at all.
     */
    public function resolveStandard(string $kind, string $name): ?string;

    /**
     * Where Rector's withAutoloadPaths() directories and files find $name, or
     * null. A cold run consults these after the analysed file.
     */
    public function resolveAutoloadPaths(string $kind, string $name): ?string;
}
