# Language server

`rector-warm-lsp` is a [language server](https://microsoft.github.io/language-server-protocol/) over stdio. It shows Rector's pending changes in your editor as diagnostics, with quick-fixes, on the same warm Rector container as the [MCP server](mcp.md). For an AI agent or chat that should run Rector, see the MCP server instead.

[Editor setup](#editor-setup) • [Diagnostics](#diagnostics) • [Quick-fixes](#quick-fixes) • [Unsaved buffers](#unsaved-buffers) • [Config changes](#config-changes) • [Fix the whole workspace](#fix-the-whole-workspace) • [Progress](#progress)

For the architecture, the design decisions behind it, correctness (the warm
== cold oracle) and current benchmark numbers -- written for someone
evaluating this in five minutes -- see
[The language server, for the Rector maintainers](lsp-for-rector-maintainers.md).

## Command and options

```bash
rector-warm-lsp --working-dir=/path/to/project
```

Install: `composer global require dpt/mcp-rector-warm` puts it on `$PATH`; as a project dependency it is `vendor/bin/rector-warm-lsp`.

`--working-dir` works as for the MCP server: the server `chdir()`s there, and refuses any file outside it. `--config` works the same passive way too: it is left in `$_SERVER['argv']` for Rector's `RectorConfigsResolver` to pick up; without it, Rector finds `rector.php`/`rector.dist.php` in the working directory.

## Diagnostics

On `didOpen` and `didSave` the server runs Rector with `dryRun: true` on that file and publishes one diagnostic per changed hunk:

- severity Information, `source: "rector"`;
- message: the rule name(s) attributed to that hunk, or `"Rector fix"` when no rule could be pinned to it specifically;
- range: the lines the hunk actually changes, not the surrounding diff context;
- each rule is attributed to the hunk closest to where it reported its change.

A hunk with no real change at all (observed for a CRLF-only difference) is skipped rather than published as a no-op fix.

A failed call -- a syntax error, an out-of-root path, or any other Rector or tool-level error -- is published too, as an Error-severity diagnostic with no quickfix behind it. A broken file never looks the same as a clean one.

`didClose` clears a file's diagnostics.

## Quick-fixes

`textDocument/codeAction` over a diagnostic offers:

- `Apply Rector: <rule>` (or `Apply Rector: Rector fix` when no rule was pinned to the hunk) -- a `WorkspaceEdit` built straight from Rector's own unified diff, with no full-file read;
- "Apply all Rector fixes" for the whole file.

Code actions are offered only while the buffer is still at the version their diagnostics describe. For a client that declares `workspace.workspaceEdit.documentChanges`, each edit also names that version, so the client rejects it if the buffer has moved on.

## Unsaved buffers

The server declares full text sync (`textDocumentSync.change: 1`), so it
diagnoses what you are typing, not only what is saved. After each
`didChange` it waits for 500 ms of quiet, then runs Rector on the buffer.

How the buffer is run:

- The text is written to a temp copy **inside the project**, in a hidden
  `.rector-warm-<pid>/` directory next to the original and under the
  original's file name, so autoload, `rector.php` and the working-directory
  containment apply exactly as for the saved file.
- The server also passes the original path along, and the worker asks
  Rector's own `Skipper` about it: a `withSkip()` entry that matches the
  original (exact path, relative path, a glob, or a rule skipped for that
  path) is applied to the copy too.
- Diagnostics and quick fixes come back on the original URI, and error
  messages name the original path. The result is the one a cold
  `vendor/bin/rector process --dry-run` gives on a file with the same content
  at the same path.
- The copy and its directory are deleted when the run ends, including when
  Rector reports an error. A server killed outright (`kill -9`) cannot do
  that, so each server removes, at startup, any `.rector-warm-<pid>`
  directory whose pid is no longer running. The startup walk goes 8 levels
  deep and skips `vendor/`, `node_modules/` and VCS directories. Before each
  run, the server also removes such directories next to the file it is
  diagnosing. A directory whose pid is still running belongs to another
  server and is kept.

`rector.php` and `composer.lock` are configuration, not code Rector
refactors, so their unsaved buffers are not diagnosed. They take effect when
saved. A file-watcher event for a path inside a `.rector-warm-<pid>/`
directory is the server's own temp copy and is ignored.

Every result carries the document `version` it was computed for. A result is
published only if that version is still the latest: a change that arrives
while Rector runs on the previous version supersedes it, and the loop reads
that change before it publishes. `didSave` diagnoses the saved file and
cancels a pending buffer run; `didClose` drops the buffer.

### Limits of the buffer mode

- Applying the original path's skips relies on two private lists in
  Rector's skip resolvers. Their names are the same from Rector 2.4.0 to the
  locked 2.6.7 (checked in Rector's source). On 2.4 to 2.5.1, which have no
  `Skipper::matchSkip()`, rule-scoped skips are checked through
  `shouldSkipElementAndFilePath()` instead (reasoned from the source, not
  run). If a future Rector renames those lists, the buffer is diagnosed
  without those skips rather than failing; the notice goes to stderr only
  when the call runs cold, since the forked worker has no stderr.
- An exact-path `withSkip()` entry for a file that has **never been saved to
  disk** does not apply to its unsaved buffer: Rector drops non-glob skip
  paths that do not exist. Once the file is saved, the skip applies, as it
  does for a cold run.
- `didOpen` still reads the file from disk. A buffer that is already
  modified when it is opened is diagnosed from its first change.
- Only `file:` URIs are diagnosed. An `untitled:` buffer has no project
  path, so it gets no diagnostics.
- The debounce reads stdin with a timeout. On Linux and macOS that is
  `stream_select`. `select()` on Windows works only on sockets, so there the
  server polls instead: every 5 ms it checks PHP's read buffer and the bytes
  waiting in the pipe. The windows-latest CI leg runs the E2E test in which a
  change sent while Rector runs supersedes that run, and it passes there. If
  a pipe could not report waiting bytes, the debounce would still fire on
  time, and the only loss would be that superseding.
- Editors, watchers and `git status` can see the temp directory while a run
  is in progress.

## Config changes

On `initialized` the server asks the client (via
`client/registerCapability`) to watch `rector.php` and `composer.lock` and
report changes through `workspace/didChangeWatchedFiles`. When one of those
files changes, every open document is re-diagnosed, most recently opened or
saved first, so the document you are working in gets fresh diagnostics
before the others. The total time across every open document is the same.

The warm worker reloads the config on its next call by itself, but without
this nothing would trigger that call for a document the editor is not
re-saving, and its diagnostics would keep reflecting the old config until
the server restarted.

The registration is only sent to a client whose `initialize` declared
`workspace.didChangeWatchedFiles.dynamicRegistration: true`, as the LSP spec
requires. With any other client the config is still reloaded on the next
`didSave` (the warm worker compares a content hash of the config on every
call), just not pushed to documents the editor does not re-save.

## Fix the whole workspace

`workspace/executeCommand` advertises one command,
`rector-warm.fixWorkspace`, only when the client's `initialize` declared
`workspace.applyEdit`.

Running it dry-runs the warm worker over the whole working directory (the
same `RectorTool::process()`/`file_diffs` oracle `textDocument/codeAction`
uses per file) and sends every changed file back in ONE
`workspace/applyEdit` request -- `documentChanges` when the client declared
`workspace.workspaceEdit.documentChanges`, the plain `changes` map
otherwise. A workspace with nothing to fix still answers the request, just
with no `workspace/applyEdit` sent.

The fix is computed from disk, so a file the server knows has an unsaved
(dirty) buffer -- a `textDocument/didChange` the editor has not yet saved --
is skipped rather than fixed: applying a disk-derived edit there would
silently discard the unsaved edits. The command's result lists any skipped
URIs under `skippedDirtyBuffers`, so the client can tell. A file that is
open but not dirty is still fixed.

A client that never declared `workspace.applyEdit` never even sees the
command advertised (#141) -- running it anyway would only ever refuse.

A file that fails (e.g. a syntax error) alongside others that succeed is
skipped, not refused: the rest of the workspace is still fixed, exactly like
a cold `rector process` continues past one broken file. Since #141 the
skipped file(s) are named in a `window/showMessage` (Warning), so this no
longer looks like plain success with nothing wrong.

An unrecognised command name gets the standard "Method not found" error.

## Progress

When the client declares `window.workDoneProgress`:

- The very first diagnose -- the one cold-boot call, roughly 1.3-1.8s -- is
  wrapped in progress ("Rector: warming up" / "Rector: analysing" plus the
  file). Every later call is already warm and reports none.
- `rector-warm.fixWorkspace` reports "Rector: fixing workspace" /
  "Rector: analysing the workspace" on its own token, separate from the
  cold-boot one, so the two never mix in a client watching for either.

<details>
<summary>Protocol details: progress handshake, stale results, cancellation</summary>

- **Progress handshake.** `create` and `begin` reach the client *before* the
  diagnose runs, not batched with `end` and the diagnostics afterwards: the
  server writes each frame to the transport the moment it is ready. Before
  committing to `begin`, the server waits up to 200ms
  (`LspServer::CREATE_REPLY_TIMEOUT_SECONDS`, added to the one-time
  cold-boot cost) for the client's reply to `create`. Only an explicit
  success reply lets `begin`/`end` through. An explicit refusal, no reply
  within the window, or some other message arriving first all skip progress
  for that round, per the LSP spec.
- **Stale results.** Results are pinned to the document version that
  requested them, so a stale one is discarded if a newer `didSave` for the
  same document finishes first. The stdio loop is strictly synchronous, so
  nothing can race it today; this is defense-in-depth for an async or
  pipelined transport.
- **Cancellation.** A `$/cancelRequest` for a `textDocument/codeAction`
  whose id has not been dispatched yet is answered with a "Request
  cancelled" error rather than run. This is protocol-correct but inert in
  the shipped binary: a conforming client only cancels an id it already sent
  a request for, and this server answers one message at a time, so the
  cancellation always arrives after the response was written.

</details>

## Out of scope

`workspace/configuration` and multi-root workspaces, tracked in
[#107](https://github.com/Digital-Process-Tools/mcp-rector-warm/issues/107).

## Editor setup

Every editor below spawns the same command:
`rector-warm-lsp --working-dir=/path/to/project` (composer-global install) or
`vendor/bin/rector-warm-lsp --working-dir=/path/to/project` (local clone),
filetype `php`, root markers `composer.json` / `rector.php`.

Each editor says whether its snippet was run against a live install, and on
which versions. Neovim and Helix were tested (macOS, against a fixture
project: a file with a pending Rector change got one diagnostic plus an
`Apply Rector: ...` quickfix, and a clean file got none). Sublime Text and
PhpStorm/LSP4IJ were not.

### Neovim (0.11.3+, native `vim.lsp.config`)

Tested on Neovim 0.11.3, 0.11.4 and 0.12.5, including starting Neovim outside
the project directory.

<!-- snippet:nvim-native-config -->
```lua
-- ~/.config/nvim/lsp/rector.lua   (Neovim 0.11.3+)
-- `cmd` is a function, not a static list: `--working-dir` has to be the
-- resolved project root (matched against root_markers below), not whatever
-- directory Neovim happened to start in.
return {
  cmd = function(dispatchers, config)
    local root = config.root_dir or vim.fn.getcwd()
    return vim.lsp.rpc.start({ 'rector-warm-lsp', '--working-dir=' .. root }, dispatchers)
  end,
  filetypes = { 'php' },
  root_markers = { 'composer.json', 'rector.php' },
}
```
<!-- /snippet:nvim-native-config -->

<!-- snippet:nvim-native-enable -->
```lua
-- init.lua
vim.lsp.enable('rector')
```
<!-- /snippet:nvim-native-enable -->

This needs 0.11.3 or later: Neovim 0.11.0 to 0.11.2 do not pass `config` to a
function `cmd`, so the snippet fails there with
`attempt to index local 'config' (a nil value)`. On those versions, and on
0.10, use the nvim-lspconfig setup below.

### Neovim (0.10 to 0.11.2, via [nvim-lspconfig](https://github.com/neovim/nvim-lspconfig))

Tested on Neovim 0.10.4, 0.11.0 and 0.12.5 with nvim-lspconfig HEAD (a9bb4d5),
including starting Neovim outside the project directory. On 0.10,
nvim-lspconfig warns that it is dropping 0.10 support in its v3.

Register a custom server before calling `setup`, using `on_new_config` so
`cmd` picks up each resolved root rather than a fixed `vim.fn.getcwd()`:

```lua
local lspconfig = require('lspconfig')
local configs = require('lspconfig.configs')
if not configs.rector_warm then
  configs.rector_warm = {
    default_config = {
      cmd = { 'rector-warm-lsp' },
      filetypes = { 'php' },
      root_dir = lspconfig.util.root_pattern('composer.json', 'rector.php'),
    },
    on_new_config = function(new_config, new_root_dir)
      new_config.cmd = { 'rector-warm-lsp', '--working-dir=' .. new_root_dir }
    end,
  }
end
lspconfig.rector_warm.setup({})
```

### Zed

Zed's stable path for an arbitrary, non-bundled LSP is a small
[language server extension](https://zed.dev/docs/extensions/languages#language-servers)
rather than a plain `settings.json` entry -- unlike Neovim/Helix/Sublime, there
is no documented `settings.json` shape here yet to snippet honestly, so none
is given.

### Helix

Tested on Helix 25.07.1.

<!-- snippet:helix-languages -->
```toml
# ~/.config/helix/languages.toml
[language-server.rector-warm-lsp]
command = "rector-warm-lsp"
args = ["--working-dir=."]

[[language]]
name = "php"
roots = ["composer.json", "rector.php"]
language-servers = ["rector-warm-lsp"]
```
<!-- /snippet:helix-languages -->

Helix spawns language servers with the workspace root as the working
directory, so `--working-dir=.` resolves to it. `roots` is needed: Helix's
default PHP roots are `composer.json` / `index.php`, so a project that has
only a `rector.php` would otherwise resolve to the git root.

**Open `hx` from the project root, or from inside the project's git
checkout.** Started from a subdirectory with no `.git` above it, or from
outside the project, the server gets the wrong root and fails with
"No rector.php found" or "path is outside the configured working directory".
That is a limit of Helix's root search, which `roots` cannot fix.

`language-servers = [...]` replaces Helix's default PHP servers rather than
adding to them, so to keep your usual PHP server list both, e.g.
`language-servers = ["intelephense", "rector-warm-lsp"]` (reasoned from
Helix's docs, not run).

### Sublime Text ([LSP package](https://github.com/sublimelsp/LSP))

**Untested:** not run against a live Sublime Text install. The keys below
match the LSP package's documented client schema.

```json
// LSP.sublime-settings
{
  "clients": {
    "rector-warm-lsp": {
      "enabled": true,
      "command": ["rector-warm-lsp", "--working-dir=${folder}"],
      "selector": "source.php"
    }
  }
}
```

`${folder}` is the window's *first* folder only (reasoned, from
`window.extract_variables()`): in a multi-folder window the other folders'
files are outside the working directory, and with no folder open the server
gets an unusable `--working-dir`.

### PhpStorm / IntelliJ ([LSP4IJ](https://github.com/redhat-developer/lsp4ij) plugin)

**Untested:** not run against a live PhpStorm/LSP4IJ install; written from
LSP4IJ's docs.

LSP4IJ has no project-file snippet for an ad hoc server; it is wired through
its UI: **Settings > Languages & Frameworks > Language Servers > +**, define
a server with command `rector-warm-lsp --working-dir=$PROJECT_DIR$` and
file name pattern `*.php`.
