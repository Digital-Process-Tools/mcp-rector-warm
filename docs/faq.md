# FAQ

**MCP server or language server?** The [MCP server](mcp.md) is for an AI agent or chat that should run Rector. The [language server](lsp.md) is for an editor that should show Rector's diagnostics and quick-fixes. Both ship in the same package and can run side by side.

**Does this replace `vendor/bin/rector`?** No. Use it from MCP clients (Claude Desktop, agents) and editors. For one-off CLI calls the regular binary is still simpler.

**Can it apply changes?** Yes. Over MCP, pass `dryRun: false`. (Rector itself has no `--fix` flag: it writes by default and only previews with `--dry-run`.) This always works, regardless of `--call-timeout`: the deadline only ever bounds a `dryRun: true` call, and a write call is never killed by it -- see [Call timeout](mcp.md#call-timeout) for the trade-off. In the editor, use the language server's [quick-fixes](lsp.md#quick-fixes) or [`rector-warm.fixWorkspace`](lsp.md#fix-the-whole-workspace).

**Why not a phar?** Rector ships as a real Composer library. Phar packaging would just add a runtime cost without a benefit here.

**Memory?** The daemon sets `memory_limit = -1` like Rector's own CLI. Idle daemon ≈ 80MB resident.

**Does it survive Rector version updates?** Probably. The prefix-detection scheme is forward-compatible with new `RectorPrefix<date>` values. Pin a Rector version in your own `composer.json` if you need determinism.

**Does it work on Windows?** Yes, without `pcntl`: a standby worker process takes the place of the fork. See [How it works](how-it-works.md#windows-and-other-php-builds-without-pcntl).
