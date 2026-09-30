<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Warm;

use PHPStan\Parser\Parser;

/**
 * #185: a decorator around one of the parsers PHPStan's PathRoutingParser routes
 * every parseFile() through. The session child installs one around each of
 * PathRoutingParser's inner parsers (SessionHooks::installParserHook()), so every
 * PHP file PHPStan reads for reflection, PHPDoc resolution or trait analysis is
 * reported to $onParseFile BEFORE it is read -- the order matters: a file that
 * changes between the report and the read is then recorded with its older
 * bytes, and the next staleness check sees the difference. Reported after the
 * read, the same race would record the newer bytes and hide the change forever.
 *
 * It sits below PathRoutingParser and above PHPStan's CachedParser, whose AST
 * cache is keyed by source text: wrapping below that cache would miss a second
 * path whose content is identical to one already parsed.
 */
final readonly class TrackingParser implements Parser
{
    /**
     * @param \Closure(string): void $onParseFile
     */
    public function __construct(
        private Parser $inner,
        private \Closure $onParseFile,
    ) {
    }

    public function parseFile(string $file): array
    {
        ($this->onParseFile)($file);

        return $this->inner->parseFile($file);
    }

    public function parseString(string $sourceCode): array
    {
        return $this->inner->parseString($sourceCode);
    }

    public function inner(): Parser
    {
        return $this->inner;
    }
}
