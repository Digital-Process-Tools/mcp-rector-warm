# Rector diagnostics-on-save: the LSP, five minutes for the Rector team

This page is written for the Rector maintainers (part of #51): what
`bin/rector-warm-lsp` does, how it is built, how its correctness is checked,
and what it currently costs versus a cold `rector process` call. It is a
digest of what already exists in this repo -- the [Language server
docs](lsp.md), [ADR
0001](decisions/0001-lsp-library-choice.md), and the test suite -- rather
than a new description of the same mechanism.

## What

`bin/rector-warm-lsp` is a second entry point on the same warm core as the
MCP server (`bin/mcp-rector-warm`), speaking
[LSP](https://microsoft.github.io/language-server-protocol/) over stdio
instead of MCP, for editors that want Rector diagnostics on save rather than
an agent calling a tool:

- **Diagnostics on save.** `didOpen`/`didSave` runs Rector (dry-run) on that
  one file and publishes one diagnostic per changed hunk, narrowed to the
  lines the hunk actually changes.
- **Quickfix per hunk, plus apply-all.** `textDocument/codeAction` over a
  diagnostic offers `Apply Rector: <rule>`, built straight from Rector's own
  unified diff (no full-file read needed), plus a whole-file "Apply all
  Rector fixes" action.
- **Config reload.** The server watches `rector.php` and `composer.lock`
  (`workspace/didChangeWatchedFiles`) and re-diagnoses every open document
  when either changes, so diagnostics never keep reflecting a stale config
  until the editor restarts the server (#101).
- **Error reporting.** A failed call (syntax error, out-of-root path, any
  other Rector/tool-level error) is published as an Error-severity
  diagnostic with no quickfix, so a broken file is never indistinguishable
  from a clean one (#90, #91).
- **Cold-boot progress and cancellation.** `window/workDoneProgress` fires
  around the first diagnose only (the cold-boot call), when the client
  declares the capability, with `create`/`begin` written to the transport
  BEFORE that diagnose runs (not batched with `end` afterwards). The server
  then WAITS up to `LspServer::CREATE_REPLY_TIMEOUT_SECONDS` (0.2s, added
  latency on that one cold-boot call) for the client's own reply to
  `create`; only an explicit success proceeds to `begin` -- an explicit
  refusal, the window elapsing with nothing, or an unrelated message
  arriving first all skip progress for that round instead (PR #128
  review, second round -- the first fix here only checked for a reply
  ALREADY sitting in the pipe, which a real client's millisecond-delayed
  one never was). `$/cancelRequest` for a `textDocument/codeAction` not
  yet dispatched is answered "Request cancelled" rather than run, but --
  same caveat as the stale-result discard above -- that can only fire in a
  future non-synchronous transport: a conforming client's cancellation for
  id X always reaches this one-message-at-a-time server after id X's own
  response was already written (#111).
- **Workspace-wide fix (#102).** `workspace/executeCommand` advertises
  `rector-warm.fixWorkspace` only when the client's `initialize` declared
  `workspace.applyEdit` (#141: gated on `canApplyWorkspaceEdit`, not only on
  the diagnostics source type -- a client that never declared it never even
  sees the command). It dry-runs the same warm oracle over the whole
  working directory and returns every changed file in one
  `workspace/applyEdit` request, framed by its own `$/progress` token
  (distinct from the cold-boot one above). Running the command anyway (a
  client that ignores the advertisement) still gets a JSON-RPC error rather
  than a silent no-op, via `executeCommand()`'s own independent check. The
  fix is computed from disk, so a file with an
  unsaved (dirty) buffer is skipped instead of being overwritten with a
  disk-derived edit that would silently discard the unsaved changes
  (#140); skipped URIs are reported back in the command's result under
  `skippedDirtyBuffers`. The result is `null` when nothing was skipped
  (there is no empty-array/`null` distinction to read anything into --
  both mean "nothing to report"). Each reported URI is the server's own
  form -- on-disk case, with any symlink in the path resolved -- which
  may not be byte-for-byte the URI the client itself sent on `didOpen`;
  the dirtiness check itself is robust to that difference (a buffer
  opened through a symlinked path, or through a differently
  percent-encoded URI for the same file, is still recognised as dirty,
  #147/#150), but the URI in `skippedDirtyBuffers` is the canonical one,
  not necessarily the client's own string. A buffer that is edited back
  to match the saved file's content is still considered dirty until the
  client sends `didSave` or `didClose` for it -- there is no
  content-equality check that would clear the flag early. A file that fails
  (e.g. a syntax error) alongside others that succeed is skipped, not
  refused; since #141 the failing file(s) are named in a `window/showMessage`
  (Warning) rather than looking like plain success.

Editor setup for six clients (Neovim, Helix, Sublime Text, PhpStorm/LSP4IJ,
plus the generic pattern) is in [Editor
setup](lsp.md#editor-setup).

Out of scope: `workspace/configuration` and multi-root workspaces (#107, one
warm worker per `rector.php` root).

## How

**The forked warm worker.** Every call to Rector -- from the MCP tool or the
LSP -- runs inside a forked child process (`RectorRunner::boot()`, in
`src/RectorRunner.php`), never in the long-lived daemon itself. The
parent blocks on a socket read until the child answers or times out. This
means re-editing `rector.php` between calls, or a warm container that
corrupts mid-session, can never crash the daemon -- only the forked child,
which the daemon then reboots.

**Windows / no-`pcntl` fallback.** Where `pcntl_fork()` is unavailable
(Windows always; `#18`'s `disable_functions` case elsewhere), there is no
copy-on-write snapshot of a booted container to isolate each call in, so by
default each call gets a fresh, pre-booted standby `php` subprocess started
right after the previous call returns (`runViaStandbyWorker()`, #108),
letting the caller pay only for the analysis. `runCold()` -- boot and run in
one fresh subprocess per call, with no pre-boot -- is now only the fallback
when `MCP_RECTOR_WARM_NO_PCNTL=cold` restores the pre-#108 behaviour. See
`RectorRunner`'s class docblock, in `src/RectorRunner.php`.

**JSON-RPC over stdio, hand-rolled.** [ADR
0001](decisions/0001-lsp-library-choice.md) is the full record; in short:
`phpactor/language-server` is actively maintained but built on Amp's
cooperative event loop, and this repo's fork/socket worker model
(`RectorRunner::boot()`/`forkAndExecute()`) has no event loop today.
Introducing one either means running two schedulers side by side or
rewriting the fork/socket core onto Amp -- both far more than v1's six LSP
methods need. `bin/rector-warm-lsp` instead frames `Content-Length:
N\r\n\r\n<json>` by hand (`src/Lsp/StdioLspTransport.php`, ~40 lines) and
dispatches through `src/Lsp/LspServer.php`, on the same synchronous
fork-per-call model the MCP tool already uses.

**`file_diffs` / `changes[]` / `errors[]`, mapped to diagnostics.**
`RectorDiagnosticsSource::diagnose()` (`src/Lsp/RectorDiagnosticsSource.php`)
runs `RectorTool::process($path, dryRun: true)` and reads Rector's own
`--output-format=json` report:

- A refused call (out-of-root path, `SecurityError`) or a call whose output
  never parses into a report becomes an `errors[]` entry with `line: 0`,
  rather than looking identical to "nothing to report" (#90).
- `report['file_diffs']` -- Rector's per-file diff, `applied_rectors`, and
  `changes[]` (its own per-change attribution, when it has one) -- is turned
  into one fix per hunk by `RectorDiffParser::buildFixes()`. Each hunk is
  attributed to the rule `changes[]` names closest to it; a hunk with no
  attributable rule gets the generic label `"Rector fix"` rather than being
  dropped (see **Known limits** below).

`RectorTool::process()` calls Rector with `--debug` for speed (parallel mode
adds ~14s of boot overhead per single-file call) -- re-verified against
`rector/rector ^2.4` that `--debug` does not suppress `file_diffs[]` in this
version (see the re-verification comment in `src/RectorTool.php`).

## Correctness

**The warm == cold oracle.** Every LSP/MCP-facing test compares the warm
server's answer against a fresh, cold `rector process --dry-run` on the same
input and asserts they match -- diff, `applied_rectors`, errors, exit code,
after normalising paths (`tests/E2E/mcp_harness.py`). This is the mechanism
this whole project exists to be trustworthy about: a warm process is only
useful if switching to it never silently changes what Rector reports.

**How CI enforces it.** `tests/E2E/test_scenarios.py` is a data-driven
harness: each fixture declares `expect` (changed files, diff contents,
errors) and by default also runs the cold comparison. `oracle: false` is the
one escape hatch, reserved for a fixture whose entire point is that warm
*deliberately* no longer matches an unpatched cold Rector on the same tree
(e.g. #14, where a missing config's own default behaviour is the bug being
fixed) -- see `CONTRIBUTING.md`'s "Checking against a real project" section
for the full fixture format. `tools/warm-vs-cold.py` runs the same oracle
against an arbitrary real project (see [Benchmark](benchmark.md#reproduce-it)
for the command).

**Known limits.**

- **Per-hunk sequential apply vs one pass.** Applying fixes hunk-by-hunk
  through separate `WorkspaceEdit`s is not the same operation as Rector's
  own single whole-file rewrite; the "Apply all Rector fixes" action exists
  so a user is not required to apply hunks one at a time and re-diagnose
  between each.
- **The generic label (#100).** `buildFixes()` attributes a hunk to the
  nearest rule name it can find in `changes[]`; when no rule can be pinned
  to a hunk it gets the generic `"Rector fix"` label instead of a wrong one.
  This heuristic has a known residual ambiguity: if one rule produces two
  hunks but `changes[]` only reports one of them, and a second, unrelated
  rule was applied but never produced a hunk of its own, the current
  "exactly one unattributed hunk + exactly one leftover rule" gate cannot
  tell that case apart from the genuinely-unambiguous one, and can attribute
  the wrong rule name to the hunk (originally recorded in
  `trap.d/100.leftover-rule-ambiguity.md`, since filed as #154 and the trap
  fragment removed; #154 is now pinned by a test). This is a
  presentation-layer severity (a diagnostic label), not a correctness or
  security issue, and Rector's own JSON report does not currently expose
  enough per-rule hunk detail to close it without a report format change
  (see **Asks for upstream** below).

## Numbers

The [Benchmark](benchmark.md) page has the
maintained, reproducible baseline (`tools/warm-vs-cold.py`, 30 files each of
laravel/framework and symfony/symfony, first call excluded): with
the warm session (on by default since #216): **740 ms cold vs 76 ms warm per
file on Laravel, 696 ms vs 100 ms on Symfony, at the median**; 224 ms and
159 ms warm with `MCP_RECTOR_WARM_SESSION=0`. Every warm answer was
byte-identical to cold -- including every write, which never goes through the
session regardless of its state. Reproduce it on any project:

```bash
python3 tools/warm-vs-cold.py --project /path/to/project --files 'src/**/*.php' --limit 30 --jobs 1 --out /tmp/wvc
```

A separate, LSP-focused round of measurements (2026-09-28, server at
`main@67b0134`, reported by the issue author in #110 and not yet
independently re-run by this page's author) puts numbers to the same effect
via the language server directly (latency = `didSave` until
`publishDiagnostics`, cold = one `rector process --dry-run` per file,
OPcache off, everything serial):

| Project | Cold CLI median | Warm save median / p90 | Speedup |
|---|---|---|---|
| Private production app (17 files, heavy custom ruleset) | 14.1s | 1.93s / 5.3s | ~7.3x |
| Small fixture project (11 files) | 1.54s | 0.33s / 0.59s | ~4.6x |

Both runs matched warm-vs-cold diagnostics exactly (17/17 and 11/11 files).
The same report notes that the warm/cold ratio tracks the project's own
**config boot cost** more than project size -- cold is almost all boot (on
the heavy-config app, a trivial file cost 13.2s against 14.1s for a real
one), and the warm server pays that boot once, on the first save or after a
config change, then only pays for analysis. Caveats on this second round,
as reported: the app's cold p90 was lost (n=3 for its first save); memory
readings were unreliable on macOS; a public project (e.g. Laravel) is still
to add; and it has not yet been re-run with OPcache on for the cold side,
which many real setups enable.

Config reload (#101) cost about 8s to re-diagnose one open document after a
`rector.php` change (a warm reboot), then 1.4s warm again; with 17 documents
open, about 45s, because they are currently re-diagnosed serially.

## Asks for upstream

**Per-hunk rule attribution in Rector's own JSON output** would remove the
#100 heuristic above outright: today `--output-format=json`'s `changes[]`
gives a per-change attribution that does not always cover every hunk a rule
produces, so a hunk with no reported change has to be guessed at by
proximity. If the report instead named, for every hunk in `file_diffs`,
which rule(s) produced it -- even just a count of hunks per rule -- the
"exactly one unattributed hunk, exactly one leftover rule" gate here could
be replaced with an exact match instead of an inference.
