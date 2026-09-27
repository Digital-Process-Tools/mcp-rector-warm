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

`mcp-rector-warm` keeps a warm Rector container ready across calls. **First call pays the boot once. Every subsequent call reuses it** -- the container itself always lives in a forked worker, never in the long-lived daemon process (#31), so re-editing `rector.php` between calls can never crash the server.

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

Measured with `tools/warm-vs-cold.py` on a real private production codebase (PHP 8.2.0, Apple Silicon, v0.5.0 at `4902c3d`): 20 files sampled across the tree, each run once through one warm server session and once through a fresh `rector process --dry-run`, cold runs serial so neither side shares CPU with the other.

| Setup | median | p95 | Notes |
|-------|--------|-----|-------|
| Cold `rector process`, one file | 7.02s | 9.00s | autoloader + container + ruleset each time |
| **mcp-rector-warm, later calls** | **0.68s** | **2.40s** | container reused |
| mcp-rector-warm, start + handshake | 0.1s | | the container is not built yet |
| mcp-rector-warm, first call | 6.4s | | builds the container: costs about one cold run, paid **once** per session |

**~10× faster per call at the median.** 20 files: **144s cold → 30s warm**, first call included. All 20 warm answers matched the cold ones.

The start and first-call rows come from a separate probe: 3 fresh sessions, each starting with the same small file, which takes 5.9-7.3s cold. The first call took 6.37-6.42s each time, and a second, different file then took 0.28-0.31s. The server builds the container on the first call, not at start, so an idle server costs nothing. The first call costs about the same as running Rector once without the server.

Numbers vary with project size and rule set. The win is the cold-start amortization, not magic. Reproduce on your own project:

```bash
python3 tools/warm-vs-cold.py --project /path/to/project --files 'src/**/*.php' --limit 20 --jobs 1 --out /tmp/wvc
```

The script needs the Python MCP client from `tests/E2E/requirements.txt`, and writes the timings to `report.md` in `--out`.

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

**Failure is reported as an MCP tool error.** A rejected path (outside the
working dir), a nonexistent path, a project with no `rector.php` and no
`--config` given at startup, or an exception raised inside Rector itself all
come back as a tool result with `isError: true`, so an MCP host can see the
failure and surface it instead of treating a broken call as a success. The
structured details survive in `structuredContent`:

```json
{
  "exit_code": -1,
  "output": "",
  "warm_boot": false,
  "error": "rector_process: path is outside the configured working directory.",
  "error_class": "SecurityError",
  "trace": ""
}
```

A missing config reports `error_class: "RuntimeException"` with a message naming
`--config` and `rector.php`. The daemon does not restart itself: add a
`rector.php` and call again, and it boots normally, since a failed boot never
marks the container warm.

The tool also declares its behavior via MCP tool annotations: `readOnlyHint:
false` (a non-dry-run call writes files), `destructiveHint: true`,
`idempotentHint: false`, `openWorldHint: false`.

## How it works

Three decisions worth knowing:

1. **One daemon per project, not per call -- but the container it holds always lives in a forked worker, never in the daemon itself.** Working dir pins at server startup, keeping `$_SERVER['argv']` clean for `RectorConfigsResolver`. Before every call the runner hashes the resolved `rector.php`/`rector.dist.php` (whichever `RectorConfigsResolver` would pick), and a changed hash tears the current worker down and forks a brand-new one, so an edit to the config takes effect on the next call rather than waiting for a restart. Booting always happens in a process that has never booted before: `rector.php` is a plain PHP file `require`d while building the container, and re-`require`ing it in a process that already required it once is a PHP fatal ("Cannot declare class/function already declared") when the config declares a class or function -- so the daemon process itself never boots the container; it only ever talks to a worker over a socket. Only the main config file itself is hashed; a `rector.php` that `require`s a shared file is a known limitation -- touch/edit the main config file too to force a reboot after changing what it includes.

2. **Parallel mode forcibly disabled (`--debug` flag).** Rector's worker fork model expects `$_SERVER['argv'][0]` to be the rector CLI binary. From an MCP server it isn't, so workers can't respawn. Single-thread analysis only — that's fine for the per-file edit loop this is designed for.

3. **Runtime-prefixed namespace handled.** Rector's bundled Symfony is namespaced `RectorPrefix<date>\\Symfony\\Component\\Console\\...` to avoid dependency conflicts. The runner detects the prefix at boot and resolves Application/Input/Output class names dynamically. Survives Rector version bumps.

4. **A missing config, a config with zero rules, or a project's own `rector.php` printing while it loads, cannot corrupt the MCP stdout.** Rector's CLI treats a missing `rector.php`, or one that loads fine but registers no rules or sets, as friendly onboarding, and Symfony's console output writes straight to the real stdout stream, bypassing an `ob_start()` wrap entirely -- and a project's `rector.php` can `echo`, or trigger a notice/deprecation, while it loads. None of that reaches the JSON-RPC pipe: both a missing config and a config with zero registered rules are refused as a real, reported error *before* any of Rector's own console machinery runs (per call, not at server startup -- a `rector.php` fixed up later just works on the next call), config resolution and container boot run inside an output buffer, and PHP's own error display is pointed at stderr (`display_errors=stderr`).

## FAQ

**Does this replace `vendor/bin/rector`?** No. Use it from MCP clients (Claude Desktop, agents). For one-off CLI calls the regular binary is still simpler.

**Can it apply changes?** Yes — pass `dryRun: false`. (Rector itself has no `--fix` flag: it writes by default and only previews with `--dry-run`.)

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
