<p align="center">
  <img src="banner.png" alt="mcp-rector-warm — cold start dies. 9× faster per edit." width="900">
</p>

# mcp-rector-warm

> **Stop paying [Rector](https://getrector.com/)'s cold-start tax on every edit.**
> A warm-process [MCP](https://modelcontextprotocol.io/) server that keeps the [Rector](https://github.com/rectorphp/rector) container hot. ~9× faster per call. Works with every MCP client.

[![Tests](https://github.com/Digital-Process-Tools/mcp-rector-warm/actions/workflows/tests.yml/badge.svg)](https://github.com/Digital-Process-Tools/mcp-rector-warm/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/dpt/mcp-rector-warm.svg)](https://packagist.org/packages/dpt/mcp-rector-warm)
[![PHP](https://img.shields.io/badge/php-8.2%2B-blue)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Community-brightgreen)](LICENSE)

[Why](#why) • [Install](#install) • [Use it](#use-it) • [Benchmark](#benchmark) • [Compatibility](#compatibility) • [How it works](#how-it-works) • [FAQ](#faq)

---

## Why

[Rector](https://getrector.com/) is one of the most useful tools in modern PHP — automated refactoring, type fixes, version upgrades. It is also one of the slowest to **start**.

Every `rector process foo.php` pays the same toll: autoloader bootstrap, [DI container](https://github.com/rectorphp/rector/blob/main/src/DependencyInjection) build, ruleset compile. **~3-5 seconds before a single rule fires.** For agents and validators that run Rector after every edit, that cold-start cost dominates wall time.

`mcp-rector-warm` runs Rector inside a long-lived PHP process. **First call pays the boot once. Every subsequent call reuses the live container.**

## Install

```bash
composer global require dpt/mcp-rector-warm
```

Makes `mcp-rector-warm` available on `$PATH`.

Requires PHP 8.2+. Pulls Rector ^2.4 as a real Composer dep (no phar gymnastics).

## Use it

### Claude Desktop

Edit `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
  "mcpServers": {
    "rector": {
      "command": "mcp-rector-warm",
      "args": [
        "--working-dir=/path/to/your/project",
        "--config=/path/to/your/project/rector.php"
      ]
    }
  }
}
```

Restart Claude. Ask: *"Run Rector on src/Foo.php"*.

### Cline / Continue / Cursor / Zed / any MCP client

Same `command` + `args` shape. The server speaks plain MCP over stdio — no client-specific glue.

### Standalone

```bash
mcp-rector-warm --working-dir=/path/to/project --config=/path/to/project/rector.php
```

Reads MCP JSON-RPC on stdin, writes responses on stdout.

## Benchmark

Measured on a real DVSI codebase (Rector config with 677 paths, ~50KB target file):

| Setup | Per-call wall | Notes |
|-------|---------------|-------|
| Cold rector CLI | ~4500ms | autoloader + container + ruleset each time |
| **mcp-rector-warm (warm)** | **~500ms** | container reused, opcache hot |
| Daemon cold boot | ~3000ms | paid **once** at server start |

**~9× faster per call.** For a tool running Rector after 100 edits: **7.5 min → 50s.**

Numbers vary with project size and rule set. The win is the cold-start amortization, not magic.

## Compatibility

| Client | Status |
|--------|--------|
| Claude Desktop | ✅ stdio MCP |
| Cline (VS Code) | ✅ stdio MCP |
| Continue (VS Code / JetBrains) | ✅ stdio MCP |
| Cursor | ✅ stdio MCP |
| Zed | ✅ stdio MCP |
| Custom (Python/Node/Go MCP clients) | ✅ standard protocol |

Any client that speaks MCP stdio works. No custom protocol.

## Tools exposed

### `rector_process`

Run Rector on a path.

| Argument | Type | Default | Description |
|----------|------|---------|-------------|
| `path` | string | required | Absolute path to file or directory under the working dir |
| `dryRun` | bool | `true` | Preview changes only. `false` writes them. |

Returns:

```json
{
  "exit_code": 0,
  "output": "...",
  "warm_boot": true
}
```

`warm_boot: true` ⇒ container reused. `false` ⇒ first call (cold boot just finished).

## How it works

Three decisions worth knowing:

1. **One daemon per project, not per call.** Config + working dir pin at server startup. This keeps `$_SERVER['argv']` clean for `RectorConfigsResolver` and lets the container cache across every call.

2. **Parallel mode forcibly disabled (`--debug` flag).** Rector's worker fork model expects `$_SERVER['argv'][0]` to be the rector CLI binary. From an MCP server it isn't, so workers can't respawn. Single-thread analysis only — that's fine for the per-file edit loop this is designed for.

3. **Runtime-prefixed namespace handled.** Rector's bundled Symfony is namespaced `RectorPrefix<date>\\Symfony\\Component\\Console\\...` to avoid dependency conflicts. The runner detects the prefix at boot and resolves Application/Input/Output class names dynamically. Survives Rector version bumps.

## FAQ

**Does this replace `vendor/bin/rector`?** No. Use it from MCP clients (Claude Desktop, agents). For one-off CLI calls the regular binary is still simpler.

**Does it support `rector --fix`?** Yes — pass `dryRun: false` to apply changes.

**Why not a phar?** Rector ships as a real Composer library. Phar packaging would just add a runtime cost without a benefit here.

**Memory?** The daemon sets `memory_limit = -1` like Rector's own CLI. Idle daemon ≈ 80MB resident.

**Does it survive Rector version updates?** Probably. The prefix-detection scheme is forward-compatible with new `RectorPrefix<date>` values. Pin a Rector version in your own `composer.json` if you need determinism.

## Credits

- **[Rector](https://github.com/rectorphp/rector)** by [Tomas Votruba](https://github.com/TomasVotruba) and contributors — the engine doing all the real work. If you ship PHP, [sponsor him](https://github.com/sponsors/TomasVotruba).
- **[Model Context Protocol](https://modelcontextprotocol.io/)** by Anthropic — the protocol that makes this kind of tool integration possible.
- **[mcp/sdk](https://github.com/modelcontextprotocol/php-sdk)** — official PHP SDK, used here for stdio transport + tool discovery.

## Related

- **[Rector docs](https://getrector.com/documentation)** — config, rules, sets.
- **[Rector on Packagist](https://packagist.org/packages/rector/rector)** — the upstream package.
- **[claude-supertool](https://github.com/Digital-Process-Tools/claude-supertool)** — DPT's batched-ops Claude Code companion; integrates this server as a validator.

## License

Community License — see [LICENSE](LICENSE). Built by [Digital Process Tools](https://github.com/Digital-Process-Tools).
