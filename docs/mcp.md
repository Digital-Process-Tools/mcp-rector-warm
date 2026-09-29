# MCP server

`mcp-rector-warm` is an [MCP](https://modelcontextprotocol.io/) server over stdio. It gives an AI agent or chat one tool, [`rector_process`](#rector_process), backed by a warm Rector container. For editor diagnostics, see the [language server](lsp.md) instead.

[Client setup](#client-setup) • [`rector_process`](#rector_process) • [Options](#options) • [Call timeout](#call-timeout) • [Compatibility](#compatibility)

Install: `composer global require dpt/mcp-rector-warm` (see the [README](../README.md#install)).

## Client setup

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

### VS Code (Copilot chat / agent mode)

VS Code 1.102+ runs MCP servers natively. Add `.vscode/mcp.json` to the project:

```json
{
  "servers": {
    "rector": {
      "type": "stdio",
      "command": "${workspaceFolder}/vendor/bin/mcp-rector-warm",
      "args": [
        "--working-dir=${workspaceFolder}",
        "--config=${workspaceFolder}/rector.php"
      ]
    }
  }
}
```

With `composer global require`, use `"command": "mcp-rector-warm"` instead. If Composer's `vendor-dir` is not `vendor/`, adjust the path.

This gives Rector to chat and agent mode. It does **not** run Rector on save or underline code in the editor. That is the [language server](lsp.md)'s job.

### Cline / Continue / Cursor / Zed / any MCP client

Same `command` + `args` shape. The server speaks plain MCP over stdio — no client-specific glue.

### Standalone

```bash
mcp-rector-warm --working-dir=/path/to/project --config=/path/to/project/rector.php
```

Reads MCP JSON-RPC on stdin, writes responses on stdout.

## `rector_process`

Run Rector on a path.

| Argument | Type | Default | Description |
|----------|------|---------|-------------|
| `path` | string | required | Absolute path to file or directory under the working dir |
| `dryRun` | bool | `true` | Preview changes only. `false` writes them. A `dryRun: false` call is never bound by [`--call-timeout`](#call-timeout). |

Returns:

```json
{
  "exit_code": 0,
  "output": "...",
  "warm_boot": true
}
```

`warm_boot: true` ⇒ container reused. `false` ⇒ first call (cold boot just finished).
Without pcntl (Windows), `true` means the call was served by a worker process booted
before it arrived -- see [Windows and other PHP builds without pcntl](how-it-works.md#windows-and-other-php-builds-without-pcntl).

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

## Options

| Flag | Default | Meaning |
|------|---------|---------|
| `--working-dir=PATH` | current directory | `chdir()`s here before anything else runs; `rector_process` refuses any path outside it. |
| `--config=PATH` | Rector's own resolution (`rector.php`/`rector.dist.php` in `--working-dir`) | Passed straight through to Rector; not parsed by mcp-rector-warm itself. |
| `--call-timeout=SECONDS` | `600` | Deadline for a `dryRun: true` call. `0` disables it. Never applies to a `dryRun: false` call. See [Call timeout](#call-timeout). |

## Call timeout

`--call-timeout` bounds preview (`dryRun: true`) calls only.

- A preview call that outruns it is killed and reported as an error, instead of blocking the caller forever.
- It is independent of PHP's `default_socket_timeout`: a call still working past `default_socket_timeout` keeps going.
- The default, 600s, never cuts off real work. Measured on a real project: 0.3-2.4s per warm call, ~10s for the first (container-building) call.
- `0` disables it.

**It never applies to a write (`dryRun: false`) call.** The kill is an unconditional SIGKILL with no grace period, and Rector writes each changed file by truncating it and then writing the new content. A kill landing mid-write would leave that file truncated, with no backup. So a write call is never killed.

The trade-off: a genuinely wedged write call hangs indefinitely. The warm daemon is single-threaded, so while it hangs, every later call from any client hangs too, until the daemon is restarted.

## Compatibility

| Client | Status |
|--------|--------|
| Claude Desktop | ✅ stdio MCP |
| VS Code (Copilot chat / agent mode) | ✅ stdio MCP, `.vscode/mcp.json` |
| Cline (VS Code) | ✅ stdio MCP |
| Continue (VS Code / JetBrains) | ✅ stdio MCP |
| Cursor | ✅ stdio MCP |
| Zed | ✅ stdio MCP |
| Custom (Python/Node/Go MCP clients) | ✅ standard protocol |

Any client that speaks MCP stdio works. No custom protocol.
