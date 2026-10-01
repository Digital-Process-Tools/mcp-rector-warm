<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Support;

/**
 * #245: --working-dir=... is parsed identically by bin/rector-warm-lsp and
 * bin/mcp-rector-warm, as inline top-level script code that PHPUnit never
 * exercises (a bin/ entrypoint is never require()'d by a test -- it would
 * exit() and block on real stdin), leaving the logic at 0% line coverage.
 * Extracted here so it is unit-tested directly; both entrypoints now call
 * parse() instead of repeating the foreach/str_starts_with/substr inline.
 */
final class WorkingDirectoryArgument
{
    /**
     * The value of the FIRST --working-dir=... flag in $argv (argv[0], the
     * program name, is never inspected), or null when no such flag is
     * present. Does not validate that the value is a real directory -- the
     * caller still does is_dir()/chdir() itself, same as before this
     * extraction.
     *
     * @param list<string> $argv
     */
    public static function parse(array $argv): ?string
    {
        foreach (\array_slice($argv, 1) as $arg) {
            if (\str_starts_with($arg, '--working-dir=')) {
                return \substr($arg, 14);
            }
        }

        return null;
    }
}
