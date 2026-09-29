<p align="center">
  <img src="banner.png" alt="mcp-rector-warm — cold start dies. 9× faster per edit." width="900">
</p>

# mcp-rector-warm

> **[Rector](https://getrector.com/) is great. Its cold start is not.**
> Every `rector process` rebuilds its container before a single rule fires: **7 seconds per file** on a real production codebase.
> Keep Rector warm, and every call after the first takes **0.68s**. Same output as cold. **~10× faster.**

Two ways in, one warm engine:

- an **[MCP server](#rector-in-your-ai-agent)** that gives your AI agent a fast Rector;
- a **[language server](#rector-in-your-editor)** that puts Rector's fixes in your editor.

[![Tests](https://github.com/Digital-Process-Tools/mcp-rector-warm/actions/workflows/tests.yml/badge.svg)](https://github.com/Digital-Process-Tools/mcp-rector-warm/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/dpt/mcp-rector-warm.svg)](https://packagist.org/packages/dpt/mcp-rector-warm)
[![PHP](https://img.shields.io/badge/php-8.2%2B-blue)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Community-brightgreen)](LICENSE)

[Who it's for](#who-its-for) • [Get started](#get-started) • [AI agent](#rector-in-your-ai-agent) • [Editor](#rector-in-your-editor) • [Validation loop](#rector-in-your-validation-loop) • [Numbers](#the-numbers) • [Docs](#documentation)

---

## Who it's for

| You… | You get | Use |
|------|---------|-----|
| let an **AI agent** refactor PHP | Rector as a tool your agent calls after every edit | [MCP server](#rector-in-your-ai-agent) |
| want **Rector in your editor** | Rector's fixes flagged as you type, one click to apply | [Language server](#rector-in-your-editor) |
| run Rector in a **validation loop** | A warm Rector you call file by file, without paying the boot each time | [MCP server](#rector-in-your-validation-loop) |

Both ship in one package. Run one, or both side by side.

## Same answers. Just faster.

- **~10× faster per call.** 0.68s warm against 7.02s cold, at the median.
- **Identical output.** All 20 benchmark files gave the same answer warm and cold. CI runs every end-to-end scenario through both and compares. Nothing to re-check.
- **One-time boot.** The first call costs about one cold run (~6.4s), once per session. An idle server costs nothing.
- **Edit `rector.php` freely.** Config changes apply on the next call. No restart.

## Get started

```bash
composer global require dpt/mcp-rector-warm
```

That's the install. PHP 8.2+, Rector ^2.4 comes along as a normal Composer dependency. Now pick where Rector should run.

## Rector in your AI agent

**For:** anyone who lets Claude, Copilot agent mode, Cursor, Cline or another agent refactor PHP.

- **Your agent runs Rector after every edit**, without waiting 7 seconds each time.
- **Preview first.** The agent sees the diff; files change only when it asks to write.
- **Stays inside your project.** Paths outside the working directory are refused.
- **Any MCP client.** Claude Desktop, VS Code, Cursor, Cline, Continue, Zed.

Add it to Claude Desktop (`~/Library/Application Support/Claude/claude_desktop_config.json` on macOS, `%APPDATA%\Claude\claude_desktop_config.json` on Windows):

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

Restart Claude and ask: *"Run Rector on src/Foo.php"*. Done.

**[Full setup →](docs/mcp.md)** VS Code and other clients, the `rector_process` tool, timeouts.

## Rector in your editor

**For:** PHP developers who want Rector's suggestions where they write code, not in a terminal afterwards.

- **Fixes appear as you type**, on unsaved edits too, not only on save.
- **One click to apply** one rule, the whole file, or the whole project, without touching files that have unsaved changes.
- **A broken file never looks clean.** A syntax error or a bad config shows up as an error.
- **Change `rector.php`, see it at once.** Open files re-check when the config changes.

Point your editor's LSP client at:

```bash
rector-warm-lsp --working-dir=/path/to/project
```

for PHP files, with `composer.json` / `rector.php` as root markers.

**[Full setup →](docs/lsp.md#editor-setup)** copy-paste configs for Neovim and Helix (tested), Sublime Text and PhpStorm.

## Rector in your validation loop

**For:** teams and tools that run Rector after every generated change, as a check.

- **Start once, call per file.** No boot on each check after the first.
- **Plain MCP over stdio.** Drive it from any MCP client library (Python, Node, Go…).
- **Failures are real errors.** A bad path, a missing config or a Rector exception comes back as an error, never as a silent pass.

```bash
mcp-rector-warm --working-dir=/path/to/project --config=/path/to/project/rector.php
```

**[Full setup →](docs/mcp.md)**

## The numbers

**~10× faster per call.** The first call boots Rector once, costing about the same as one cold run (6.4s). Every call after that takes 0.68s (median) instead of 7.0s cold.

| Setup | median | p95 |
|-------|--------|-----|
| Cold `rector process`, every call | 7.02s | 9.00s |
| Warm, first call (one-time boot, once per session) | 6.4s | |
| **Warm, every later call** | **0.68s** | **2.40s** |

20 files from a real production codebase: **144s cold, 30s warm** including the one-time boot. All 20 warm answers matched the cold ones.

**[Method, caveats, reproduce it on your project →](docs/benchmark.md)**

## Install

```bash
composer global require dpt/mcp-rector-warm
```

Puts `mcp-rector-warm` and `rector-warm-lsp` on your `$PATH`. As a project dependency, both are in `vendor/bin/`.

## Documentation

- **[MCP server](docs/mcp.md)**: every client setup, the `rector_process` tool, options, the call timeout, compatibility.
- **[Language server](docs/lsp.md)**: editor setup, diagnostics, quick-fixes, unsaved buffers, fix-all, limits.
- **[How it works](docs/how-it-works.md)**: architecture, config reloads, Windows and other PHP builds without pcntl.
- **[Benchmark](docs/benchmark.md)**: method, full numbers, how to reproduce.
- **[FAQ](docs/faq.md)**.
- **[The language server, for the Rector maintainers](docs/lsp-for-rector-maintainers.md)**: design, correctness checks, known limits.

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
