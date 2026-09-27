# ADR 0001: hand-rolled JSON-RPC for `bin/rector-warm-lsp`, not `phpactor/language-server`

Status: decided (#52), 2026-09-27.

## Question

Before building `bin/rector-warm-lsp` (#53), what speaks LSP: a library, or a
hand-rolled minimal framer? Checked, as #52 asked:

1. `phpactor/language-server` as a standalone library.
2. Hand-rolled JSON-RPC over stdio, matching the six methods v1 needs.
3. A Phpactor extension instead of a standalone server.

And specifically: does the fork-per-call worker model (#43) conflict with the
library's event loop?

## What was found

**`phpactor/language-server` is actively maintained** (checked via the
Packagist and GitHub APIs on 2026-09-27): latest tagged release `8.0.0`,
last push 2026-09-19 -- eight days before this decision, not stale. It
requires PHP `^8.1` (this repo requires `>=8.2`, so no floor conflict) but
also `amphp/socket: ^1.1`. Amp is a cooperative event-loop library: the whole
point of using it is to run everything -- I/O, timers, concurrent requests --
on one non-blocking loop.

**This repo's worker model is the opposite of that.** `RectorRunner::boot()`
(`src/RectorRunner.php:203`) and `forkAndExecute()`
(`src/RectorRunner.php:350`) use `pcntl_fork()` to run each Rector call in a
forked child, then block the parent on a blocking socket read
(`readExactly()`, `src/RectorRunner.php:625`) until that child answers or
times out. There is no event loop today, and introducing Amp's
would mean either (a) running two schedulers in one process -- Amp's loop for
LSP I/O, and PHP's own blocking I/O for the fork/socket pair -- coordinated by
hand, or (b) rewriting `RectorRunner`'s fork/socket core onto Amp, which is
far more than a v1 language server needs and touches the MCP path too, since
both entry points would share that core. Neither is a "small prototype".

**v1's actual protocol surface is six methods**: `initialize`,
`didOpen`/`didSave`/`didClose`, `publishDiagnostics`, `codeAction`, `shutdown`
(per #53's own scope). Framing itself -- `Content-Length: N\r\n\r\n<json>` --
is roughly 40 lines (see `src/Lsp/StdioLspTransport.php`). The MCP server
already frames its own protocol over stdio (via `mcp/sdk`, not hand-rolled --
but proof this repo's fork/socket core is compatible with a synchronous,
blocking stdio loop, since that is exactly how `bin/mcp-rector-warm` runs
today).

**The Phpactor-extension route was not prototyped.** It would mean running
Phpactor itself as the language server and teaching it about Rector, which is
a much larger integration surface (a whole other project's extension API) for
a "does it work" spike, and it does not remove the Amp-loop question above --
Phpactor's own core still runs on Amp. Noted as the option to revisit if a
hand-rolled server turns out to need capabilities Phpactor already has
(workspace symbols, multi-request concurrency); out of scope for this decision.

## Decision

**Hand-rolled JSON-RPC over stdio.** `src/Lsp/StdioLspTransport.php` frames
messages; `src/Lsp/LspServer.php` dispatches them; `bin/rector-warm-lsp` is
the entry point, mirroring `bin/mcp-rector-warm`'s bootstrap
(`--working-dir`, dual-mode autoload, stderr-only error display). This
prototype answers `initialize` (with empty capabilities -- diagnostics and
code actions are #53's scope) and `shutdown`/`exit`, over the real stdio
path, driven end to end by `tests/E2E/test_lsp_initialize.py`.

This keeps the language server on the same synchronous fork-per-call model
`RectorTool`/`RectorRunner` already use, so `bin/rector-warm-lsp` can call
`RectorTool::process()` the same way the MCP tool does (#53), without a
second scheduler in the process and without a new dependency for six
methods.

## Consequence for #53

#53 is unblocked on the library question only. It is still blocked by #48
(open: PR #57's timeout check probably fixes it, but that has not been verified
by listing the daemon's children after a slow call) and #58 (open: PR #57
removed the only upper bound on a call, so a wedged worker now blocks the caller
forever). #32 is closed. Diagnostics, code actions, and the
`RectorTool::process()` wiring are #53's scope, not built here.

`bin/rector-warm-lsp` is deliberately left out of `composer.json`'s `bin` until
#53 ships, so no release installs a server that only answers the handshake.
