# Changelog

All notable changes to this project will be documented in this file.

This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.7.0] - 2026-09-30

### Added

- Added `bin/rector-warm-lsp` v1 (#53): the language server now runs Rector diagnostics on `didOpen`/`didSave` (one `publishDiagnostics` entry per changed hunk, built from the same warm `RectorTool::process()` dry-run the MCP tool uses) and offers `textDocument/codeAction` quick fixes -- `Apply Rector: <rule>` per hunk, plus a whole-file "Apply all Rector fixes" -- each a `WorkspaceEdit` built straight from Rector's own unified diff, no full-file re-read needed. A stale in-flight diagnostics call is discarded if a newer `didSave` for the same document completes first (version pinning; currently inert under the strictly-synchronous stdio loop, kept as defense-in-depth for a future async transport). `didClose` clears a document's diagnostics. `bin/rector-warm-lsp` is back in `composer.json`'s `bin` array now that it does real work (PR #67 removed it while it was handshake-only). Unsaved buffers, workspace-wide scans and `workspace/configuration` stay out of v1 scope, same as the issue.

- Added editor setup snippets for `bin/rector-warm-lsp` (#55): Neovim (native `vim.lsp.config` plus an `nvim-lspconfig` alternative, both resolving `--working-dir` from the detected project root rather than a fixed `vim.fn.getcwd()`), Helix (`languages.toml`), and Sublime Text (LSP package `LSP.sublime-settings`), with PhpStorm/LSP4IJ documented as a UI wizard walkthrough since it has no project-file snippet to give. Zed is called out as an open gap rather than guessed at, since its stable path for an arbitrary LSP is a small extension, not a `settings.json` entry. The Neovim snippets (native on 0.11.3, 0.11.4 and 0.12.5; nvim-lspconfig on 0.10.4, 0.11.0 and 0.12.5) and the Helix snippet (25.07.1) were run against live editor installs, and the README records those versions; the Sublime Text and PhpStorm/LSP4IJ setups are flagged per editor as untested.

- Added (#96): eight new E2E cases in `tests/E2E/test_lsp_diagnostics.py`, ported from the manual LSP driver runs behind #90/#91/#93 -- a syntax-error file publishes an Error diagnostic (plus its positive control: a later fix is not stuck showing the old error), a file outside the working directory publishes a visible Error diagnostic instead of silently reporting nothing, a CRLF file's diagnostics cover only the real change and never a spurious no-op hunk, an insert-only hunk's quickfix is reachable from its own zero-width range (plus its negative control: a neighbouring line does not get it), a second `didSave` on the same warm worker is not stale, and `shutdown`+`exit` leaves no warm-worker process behind. Four of the eight fail on a checkout with the #90/#91/#93 fixes reverted, confirmed by running them against the pre-fix commits.

- Added (#97): CI now runs the unit and end-to-end suites on `windows-latest` too (one PHP version, plus the full end-to-end suite) -- the no-pcntl reboot-before-each-call fallback (#8) is the real path there, and `core.autocrlf` is set explicitly rather than inherited from the runner's own default.

- Added (#98): CI now runs the unit and end-to-end suites on `macos-latest` too (one PHP version, plus the full end-to-end suite) -- this is where #79's BSD `ps` column parsing is exercised for real instead of only being reasoned about. The leg itself was developed and tested on a real macOS/Darwin machine, but never yet on GitHub's own `macos-latest` hosted runner -- reasoned, not observed, until its first real CI run.

- Added (#102): a `workspace/executeCommand` command, `rector-warm.fixWorkspace`, advertised only when the client's `initialize` declared `workspace.applyEdit`. Running it dry-runs the warm worker over the whole working directory (the same oracle `textDocument/codeAction` already uses per file) and sends every changed file back in one `workspace/applyEdit` request, with its own `$/progress` token separate from the existing cold-boot one. A client that never declared `workspace.applyEdit` gets a visible JSON-RPC error rather than a command that silently does nothing.

- Added diagnostics on unsaved buffers to `bin/rector-warm-lsp` (#106). The server now declares full text sync (`textDocumentSync.change: 1`). After 500 ms without a `didChange`, it runs Rector on the buffer through a temp copy that keeps the file name, in a hidden `.rector-warm-<pid>/` directory next to the original inside the project, and deletes the copy afterwards, including on error. Results are published only while their document `version` is still the latest. A change that arrives during a run supersedes that run. Code actions are offered only for the version their diagnostics describe, and they carry that version in `documentChanges` when the client supports it. The main loop moved from `bin/rector-warm-lsp` to `Dpt\McpRectorWarm\Lsp\LspLoop`. The worker applies the original path's `withSkip()` entries to the temp copy through Rector's own `Skipper`, so unsaved diagnostics honour exact-path, relative, glob and rule-scoped skips like a saved file (on Rector 2.4 to 2.5.1 too, which lack `Skipper::matchSkip()`). If Rector's skip internals ever change, the buffer is diagnosed without those skips instead of failing. `rector.php` and `composer.lock` buffers are not diagnosed, and watcher events for the temp copy are ignored, which prevents a reload loop. At startup, a server removes temp directories left by a server that was killed mid-run.

- Added (#108): a warm path without `pcntl` (Windows, or a PHP build that disables `pcntl_fork`). Every MCP and LSP call there used to boot Rector cold. Now a standby worker process (`bin/rector-warm-worker.php`) boots the container in the background right after each call returns, and the next call is served by it, so `warm_boot` is `true` from the second call on, as with the fork. Each worker serves exactly one call and exits, so every call still runs in a container nothing else was analysed in, and the answers match a cold `rector process`: all 55 E2E warm-vs-cold scenarios pass with pcntl disabled, and 20/20 files match on a large real project. The speedup needs the boot to fit between calls: 0.75s against 7.4s cold per call with 15s between calls, but back-to-back calls wait for the rest of the boot (7.6s against 8.1s cold). A single long-lived worker reused for every call was measured and rejected: 25 of 55 scenarios diverged from cold. `MCP_RECTOR_WARM_NO_PCNTL=cold` restores the previous cold subprocess per call. The deadline kill (`--call-timeout`) takes the worker's whole process tree down (#112), and the next call boots a fresh worker.
- Added (#108): `E2E_DISABLE_PCNTL=1` runs the E2E suite's server with pcntl disabled on any OS, and a new `method-return-type-edited` scenario pins #8's own reproduction as a class method. `tools/warm-vs-cold.py` gains `--warm-gap SECONDS` (untimed think time between warm calls), and its project-vendor shim now also serves the scripts spawned without pcntl.

- Added a headless-Neovim CI job (#109) that extracts the native
  `vim.lsp.config` snippet straight out of README.md (marker comments,
  `tests/E2E/nvim/extract_snippet.py`) and drives `nvim --headless` against
  the existing `tests/Fixtures/lsp-project` fixture: the fixable file must
  attach, get at least one diagnostic and a code action, and the clean file
  must get none. Runs on pinned `v0.11.3` and `stable`. A separate
  `headless-helix` job runs `hx --health php` against the README's
  `languages.toml` snippet the same way. #94 found the native Neovim
  snippet broken on every Neovim with `vim.lsp.config`, and nothing before
  this caught it.

- Docs (#110): added `docs/lsp-for-rector-maintainers.md`, a five-minute
  architecture, correctness and benchmark page for `bin/rector-warm-lsp`,
  written for the Rector team ahead of #51. Draws together the existing
  README Language server section, ADR 0001 (hand-rolled JSON-RPC vs
  `phpactor/language-server`), the warm == cold correctness oracle, the
  #100 generic-label limit, current benchmark numbers, and an ask for
  per-hunk rule attribution in Rector's own JSON output.

- LSP: report `window/workDoneProgress` ("Rector: warming up" / "Rector: analysing" plus the file) around the first (cold-boot) diagnose when the client declares `window.workDoneProgress`, written to the transport before that diagnose runs. The server waits up to 200ms for the client's reply to the progress token before sending anything further, adding that much latency to the one cold-boot call in the worst case, and skips progress for the round -- no begin, no end -- on an explicit refusal, on the window elapsing with no reply, or on some other message arriving first, rather than assuming it is fine. Also answers a `$/cancelRequest` for a `textDocument/codeAction` not yet dispatched with "Request cancelled" instead of running it -- protocol-correct defense-in-depth, inert in today's strictly-synchronous binary the same way the existing stale-result discard is (#111).

### Changed

- Changed (#51): the README is now a short landing page that presents the two entry points side by side -- the MCP server for AI agents and the `rector-warm-lsp` language server for editors -- with a "who it's for" table, one quick start each and the benchmark headline. The full reference moved to `docs/`: `docs/mcp.md` (client setup, `rector_process`, options, the `--call-timeout` trade-off, compatibility), `docs/lsp.md` (behaviour, limits, editor setup), `docs/how-it-works.md`, `docs/benchmark.md` and `docs/faq.md`. The headless Neovim/Helix smoke test now extracts its snippets from `docs/lsp.md`.

### Fixed

- Fixed: `tests/Integration/ServerStdioTest.php` now documents why reproducing the CI `no-pcntl` leg locally with `php -d disable_functions=... vendor/bin/phpunit` does not actually reproduce it -- the `-d` flag only changes the outer `phpunit` process's ini, not the fresh PHP process `bin/mcp-rector-warm` starts via `proc_open()`, so `testWarmBootOnSecondCall` and `testEditedSourceIsReprocessedAcrossCalls` previously failed locally for a test-harness reason, not a product bug (confirmed: both pass once the three pcntl functions are disabled via a real `php.ini`/`PHPRC`, matching how CI's `setup-php` `ini-values` actually disables them) (#70).
- Fixed: `tests/E2E/mcp_harness.py`'s `process_descendants()` -- the real `ps`-based zombie/descendant check backing `assert_no_zombie_descendants()` -- had its BSD/macOS `ps` compatibility recorded as reasoned-but-unverified, since CI only ever runs it on `ubuntu-latest`. It is now confirmed working against a real Darwin/BSD `ps`, including a deliberately produced zombie process; the docstring notes the one observed difference (BSD `ps`'s fixed-width `STAT` column carries trailing padding, which the existing parsing and `"Z" in stat` check both already tolerate) (#79).
- Fixed: added `tests/E2E/scenarios/wedged-boot-over-call-timeout.yaml`, the first E2E scenario to drive a wedged `boot()` -- a hanging `rector.php` -- through the real ps-based zombie check in `assert_no_zombie_descendants()`. Previously only a wedged *call* (`wedged-call-over-call-timeout.yaml`) reached that check; the boot path shares the same `RectorRunner::killAndReap()` helper but was covered only by a unit test asserting the exception/timing/`isWarm()` state, never a real OS process-tree snapshot (#80).

- Added test coverage for `killAndReap()`'s WNOHANG branch (pcntl available, `posix_kill` not) by extracting an overridable `hasPosixKill()` seam -- the branch itself was already reasoned-correct but had no CI leg or test exercising it (#71).

- Fixed the no-pcntl cold call path always reporting exit code -1 on POSIX PHP < 8.3, discarding the real exit code that `proc_get_status()` had already observed before `proc_close()` was called (#73).

- Fixed a non-UTF-8 bootstrap file path making the warm worker's boot handshake silently fail to encode, which was then reported as a misleading "the warm worker failed to boot (exit status N)" instead of the real cause; the boot handshake frames now use the same `JSON_INVALID_UTF8_SUBSTITUTE` flag already used elsewhere in this file (#74).

- Fixed `ServerVersion` never consulting Composer's `InstalledVersions` record: the `class_exists()` check guarding it disabled autoloading, so the Composer-pretty-version branch was dead code and every resolve went through the CHANGELOG.md fallback (#77).

- Fixed `RectorRunnerTest::testWedgedCallIsKilledAtTheDeadlineAndTheWorkerStaysUsable` and its `dryRun:true` sibling flaking under CPU contention: both pinned the inner (worker-side) kill site's exact message ("exceeded 1s"), but the outer (daemon-side) `RUN_FORKED_DEADLINE_GRACE_SECONDS` backstop can fire instead when cleanup cost eats the 5s grace under load, throwing a different message that lacks that figure. Both assertions now match the substring common to both kill sites ("rector call exceeded" / "--call-timeout") instead of pinning which one fired, and a new deterministic regression test (`testReadExactlyOuterBackstopMessageSharesPatternButNotFigureWithInnerKillSite`) exercises the outer kill site directly via Reflection on an already-expired deadline, without relying on contention to reach it (#81).

- Fixed `RectorRunnerTest::testWedgedCallIsKilledAtTheDeadlineAndTheWorkerStaysUsable` and its `dryRun:true` sibling asserting `isWarm()` true unconditionally after a wedged call was killed: the outer (daemon-side) `RUN_FORKED_DEADLINE_GRACE_SECONDS` backstop firing does not merely report a different message than the inner (worker-side) kill site -- its own catch block in `RectorRunner::runForked()` also SIGKILLs and forgets the WHOLE worker, not only the wedged grandchild the inner site kills, so `isWarm()` genuinely goes false. Both assertions now branch on which kill site's message fired, and a new deterministic regression test (`testRunForkedOuterBackstopKillsTheWholeWorkerNotOnlyTheGrandchild`) reaches the outer backstop's worker-killing side effect directly, via a real booted worker whose daemon-side socket is swapped for a loopback nobody answers, without relying on real CPU contention to reproduce it (#87).

- Fixed the LSP silently dropping a broken file's diagnostics: `RectorDiagnosticsSource` read only the report's `file_diffs`, so a syntax error, an out-of-root path, an unparseable Rector report, or any other `SecurityError` refusal came back looking identical to "nothing to report" -- a file that goes from one diagnostic to a syntax error dropped to zero, and the editor showed it as clean. Every `errors[]` entry, the SecurityError/out-of-root refusal (previously only logged to stderr), and an unparseable report are now published as an Error-severity diagnostic, with no quickfix behind any of them (#90).

- Fixed three LSP diagnostic-quality defects, all follow-ups to the same real-file end-to-end session as #90: (1) a rule was attributed to every hunk in the file (rather than just the hunk it actually touched) whenever its reported change line fell outside a hunk's own span, which happened whenever the rule's change was reported one line past the hunk's context -- rules are now attributed to the closest hunk instead, and never broadcast to a hunk they were not near; (2) the diagnostic range covered the diff hunk's 3 lines of context on each side rather than only the lines actually changed; (3) a hunk with no `+`/`-` content at all (observed on a CRLF-only difference) produced a diagnostic and a quickfix that both did nothing -- such hunks are now skipped entirely. A hunk whose real change genuinely could not be pinned to any single rule now labels its diagnostic and quickfix `Rector fix` rather than showing a blank message. CI caught a follow-on defect in (2): the quickfix's replacement text still carried the FULL hunk (context lines included) while the range it replaced was narrowed, so applying the fix duplicated the context lines that were now outside the range but still on disk either side of it -- the replacement text is now narrowed to match the range exactly, and `test_code_action_edit_matches_a_cold_rector_apply` (warm vs. cold) is green again (#91).

- Fixed (#93): a pure-insertion Rector hunk (e.g. `NewlineAfterStatementRector` adding a blank line) gets a zero-width diagnostic range such as `66:0-66:0` since #92 narrowed diagnostic ranges to the changed lines. `LspServer::rangesOverlap`'s half-open interval test `[b.start, b.end)` never matches an empty `b`, so a `textDocument/codeAction` request using the diagnostic's own range -- what a real editor sends for a cursor on that line -- returned no per-hunk quickfix; only a request spanning the neighbouring lines still worked. `rangesOverlap` now widens a zero-width range's exclusive end by one line before comparing, so it covers its own line on either side of the comparison. Regression from #89, introduced by #92.

- Fixed (#96): on Windows, `RectorRunnerTest::testColdCallIsKilledAtItsDeadlineWithoutPcntl` is now an explicit skip naming #112 instead of a teardown `rmdir()` warning that failed the `tests (windows-latest)` leg. The timed-out cold call leaves an orphan php process on Windows (`proc_terminate()` kills only the `cmd.exe` wrapper), which is the real bug tracked in #112; the earlier `rmdirWithRetry()` retry-budget helper was removed, since no retry can outlast a process that is still alive.

- Fixed (#97): the first real `windows-latest` PHPUnit run this leg produced surfaced two genuine platform bugs, both fixed here rather than skip-guarded: `RectorTool::isWithinRoot()`'s POSIX branch used the host's `DIRECTORY_SEPARATOR` instead of a hardcoded `/`, so a Windows CI checkout built a mismatched `\` prefix against forward-slash test paths (symmetric to the `\` hardcode #99 already applied to the Windows branch); and `ServerStdioTest`'s two process-spawn helpers (`invoke()` and `spawnServer()`) each passed the shebang-only `bin/mcp-rector-warm` straight to `proc_open()` with no interpreter, which Windows cannot resolve (no shebang support, no `.bat`/`.exe`/`.cmd` extension) -- `proc_open()` returned `false` instead of a resource across all seven of that file's test methods. Both fixes are verified locally on macOS/Darwin only (POSIX behaviour unchanged, confirmed with the same test files green before and after, including a re-run after self-review caught `spawnServer()` still missing the prepend `invoke()` alone had received); the Windows-specific half of each fix is reasoned, not observed, until this leg's next real `windows-latest` CI run confirms it.

- Fixed (#97): the `e2e (windows-latest)` leg this issue added was still red (37 of 92 tests
  failing) even after the two PHPUnit-side windows-latest fixes landed. The cause is not a bug
  in warm-boot detection: PHP ships with no `pcntl` extension on Windows at all, so
  `RectorRunner::canFork()` is always `false` there, `run()` always takes the `runCold()`
  branch, and `warm_boot` is correctly `false` on every call, as `RectorRunner`'s own class
  docblock already documents. The end-to-end assertions in `tests/E2E/test_mcp_client.py` and
  `tests/E2E/test_scenarios.py::call_step()` had no platform awareness and asserted
  `warm_boot is True` unconditionally for any non-first call, which can never hold on a
  no-pcntl platform. Both files now branch on a shared `mcp_harness.NO_PCNTL_PLATFORM` flag:
  on a no-pcntl platform the assertion still runs, but expects `warm_boot is False` for every
  call -- keeping the check meaningful (it still pins that `runCold()` really ran) rather than
  skipping it outright. Verified green locally on macOS/Darwin (the `NO_PCNTL_PLATFORM=False`
  path, unchanged); the `NO_PCNTL_PLATFORM=True` path itself is reasoned, not observed, until
  this leg's next `windows-latest` CI run confirms it.

- Fixed three LSP defects (#99, #100, #101): (1) `uriToPath()` used to read only
`parse_url()`'s PATH component, so a UNC URI (`file://server/share/A.php`) silently
dropped its HOST component, collapsing to the host-free and wrong `/share/A.php` --
it now folds a non-`localhost` host back in as a `\\host\share` prefix, the form
Windows itself expects; the containment check in `RectorTool::process()` also used a
plain, case-sensitive `str_starts_with()`, which would have misjudged an in-root file
as out-of-root purely on a Windows drive-letter case difference (e.g. `C:\proj` vs.
`c:\proj`) -- the comparison is now case-insensitive whenever `DIRECTORY_SEPARATOR`
says the process is running on Windows (no Windows machine was available to observe
either fix directly; both are reasoned and pinned by unit tests that drive the string
logic on any platform). (2) `RectorDiffParser::buildFixes()` labelled a hunk `Rector
fix` (generic) whenever closest-hunk matching found no `changes[]` entry for it, even
when the file applied a second rule that never landed on any hunk -- when exactly one
hunk is unattributed and exactly one applied rule is unaccounted for anywhere, that
pairing is now unambiguous and gets attributed; two or more of either side still stays
generic, since assigning a rule to a hunk it cannot be proven to have produced would
break the "never attribute a rule that did not produce the hunk" invariant the E2E
suite already checks. (3) a `rector.php`/`composer.lock` edit made without the editor
re-saving any open document never reached already-open documents' diagnostics --
`RectorRunner` already reloads the config correctly on the next `process()` call, but
nothing triggered that next call. `LspServer` now sends a `client/registerCapability`
request on `initialized`, asking the client to report changes to those two files via
`workspace/didChangeWatchedFiles`, and re-diagnoses every currently-open document when
one arrives.

- Fixed the LSP still labelling two hunks generically as "Rector fix" when a
  single rule produced both of them (#100, reopened -- #103's leftover-rule
  fallback did not cover this case): `changes[]` reports only one line for
  the rule, so closest-hunk matching attributes it to one hunk, which then
  puts the rule into the "already attributed" set and takes it out of the
  set #103's fallback checks. `RectorDiffParser::buildFixes()` now also
  attributes an unmatched hunk to the file's rule when the file applied
  exactly one rule in total -- there is no other rule that could have
  produced it. Observed on two real consumer files
  (`NewlineAfterStatementRector`, `RemoveUnusedPrivatePropertyRector`).

- Fixed two JSON-RPC/LSP protocol defects in `bin/rector-warm-lsp` introduced by #101
(#105): (1) the client's *response* to the server's own `client/registerCapability`
request (`id` + `result`/`error`, no `method`) was treated as a request and answered
with a -32601 "Method not found" error -- JSON-RPC forbids replying to a response, and
it is now consumed silently while an unknown *request* still gets -32601; (2) the
`workspace/didChangeWatchedFiles` registration was sent to every client, including one
declaring `capabilities: {}` -- it is now sent only when the client declared
`workspace.didChangeWatchedFiles.dynamicRegistration: true`. Without it, a
`rector.php` edit still takes effect on the next `didSave`, through the warm worker's
own content-hash config reload.

- Fixed (#108): a no-`pcntl` standby worker whose daemon was killed without cleaning up (`kill -9`, a crash) used to wait forever for the next call that would never come -- it inherits the daemon's own listening socket, so it never sees EOF. The standby now polls its daemon's liveness every 2s while idle and exits once it is gone. It also stops treating a bootstrap file edited *during* its own background boot as unchanged: the hash is now taken after the boot, and a file touched at or after the boot started is recorded as changed regardless of its content, so the next call reboots instead of silently serving a stale container. The boot-wait accept loop also now checks the outer `--call-timeout` deadline on every pass (not only when nobody connected), so a local process that keeps connecting silently can no longer stall the boot past the deadline.

- Fixed (#112): a call killed at its `--call-timeout` deadline left orphan `php` processes behind. Rector's own config turns parallel mode on by default, so the analysis ran in Rector worker processes spawned by the process the deadline killed; `SIGKILL` / `proc_terminate()` reached only that direct child, and the workers kept running (CI runner cleanup: `Terminate orphan process: ... (php)` on macOS and Windows; on Windows the orphan also held the call's temp directory, failing `rmdir()`). Every deadline kill site (cold subprocess, forked grandchild, worker boot, outer backstop) now kills the whole process tree: freeze-then-`SIGKILL` of every descendant found via `ps` on POSIX, `taskkill /T /F` on Windows. The Windows skip on `testColdCallIsKilledAtItsDeadlineWithoutPcntl` is removed. Calls made through the MCP tool pass `--debug`, which disables parallel mode, so they were exposed only when a `rector.php` or bootstrap file starts processes of its own.

- Fixed (#113): a worker boot killed by `--call-timeout` reported itself as "rector call exceeded its configured --call-timeout waiting on the warm worker" -- the exact text of `runForked()`'s outer backstop, which only fires after the call deadline plus the 5s grace. A slow container build therefore read as the outer backstop tripping at ~1s, a kill site that cannot exist. `boot()` now reports its own message ("... before the warm worker finished booting; the worker was killed"). The boot deadline itself is unchanged; it moved into an overridable `bootDeadlineNs()` so the unit tests that measure a call's kill sites against a 1s budget no longer charge a real container build (~0.9s idle, 1.3-1.6s under load on macOS) to it.

- Fixed a `rector.php`/`composer.lock` config-change re-diagnosis queuing
  the document a developer is actively working in behind every other open
  document (#115; measured on a real project at ~45s for 17 open documents,
  ~8s per document, one after another). `LspServer` now tracks each
  document's own didOpen/didSave activity order and re-diagnoses the
  most-recently-active document first when a config change reaches every
  open document, so that document's diagnostics refresh before any document
  that merely happened to open earlier -- the total time across every
  document is unchanged.

- Fixed the no-pcntl standby worker (Windows, or #18's disable_functions
  case) silently dropping every `-d` ini override given to the daemon at
  startup (#125). Unlike the `pcntl_fork()` path, which clones the daemon's
  own process image (ini overrides included) for free, spawning the standby
  as a brand-new `proc_open([PHP_BINARY, ...])` process left it running under
  PHP's own defaults instead. The daemon now reconstructs its own `-d`
  overrides by comparing each ini directive's active value against a fresh
  `php -n` process's own compiled-in default (there is no portable way to
  read back the original command line, especially on Windows) and passes
  them to the standby worker process. An earlier version of this fix
  compared against the CURRENT process's own php.ini master value instead of
  a true `-n` baseline, which never caught a real `-d` flag at all: PHP's CLI
  SAPI folds a `-d` override into both values from process start, so they
  never diverge for exactly the case this exists to catch -- caught by an
  independent end-to-end review before merge. A second review round then
  caught the forwarded values themselves not being quoted: a `-d` value goes
  through the identical parser php.ini itself uses, where an unquoted value
  ending in a reserved character (e.g. a space, `(`, or `;`) truncates
  silently rather than raising a forwarding error -- reproduced with
  `-d user_agent=Mozilla/5.0 (X11; Linux)`, which truncated to
  `"Mozilla/5.0 "`. Every forwarded value is now wrapped in double quotes
  with backslash/quote escaping.

- Fixed two related bugs on the no-pcntl standby-worker path (#126). (1) A
  `--call-timeout` error payload always reported `warm_boot: false`, even
  when an already-warm standby genuinely served the call before the deadline
  hit: the timeout path discards the worker before the caller can ask, so
  `isWarm()` (which reports "is a worker booted right now") always said false
  by then regardless of what actually served that call. `RectorRunner` now
  tracks each call's own warm/cold outcome as it is decided, and the error
  payload reads that instead. (2) A worker that had already served its call
  and was exiting on its own (retired) left its 0-byte
  `rector-warm-worker-stderr-*` temp file behind forever if the daemon was
  `kill -9`'d before it got around to reaping it -- nothing else was ever
  going to unlink it. The worker now removes its own stderr file itself, as
  part of its own normal exit, rather than waiting for the daemon.

- Fixed a warm worker (the pcntl fork path) outliving a `kill -9` of the daemon by 60-180+ seconds when a call was in flight: the worker's wait loop for its own forked grandchild only checked its own call deadline, never whether the daemon that started it was still alive, so a daemon killed mid-call left an orphaned worker (holding a booted Rector container in memory) running until the in-flight analysis eventually finished on its own -- or never, with an unlimited `--call-timeout`. The worker now polls its daemon's liveness (`posix_getppid()`, or a Windows-safe fallback) during an in-flight call, the same mechanism the no-pcntl standby worker already used while idle, and exits within roughly a couple of seconds of the daemon dying (#127).

- Fixed a forked warm worker (pcntl path) surviving a `kill -9` of its daemon by carrying on as a second, bogus MCP/LSP server on the client's own stdin/stdout, instead of exiting: any exception escaping `serveWorker()`'s own boundary -- most commonly `writeFrame()` failing with a broken pipe once the daemon's end of the socket has already closed -- used to unwind the forked worker process straight back into its caller (`boot()`), because `pcntl_fork()` duplicates the whole call stack and every inherited file descriptor, including the real client's own pipes. `serveWorker()`'s entire body is now wrapped in one try/catch/finally with an unconditional `exit()`, so this worker can never return or unwind into its caller no matter what throws or when (#127, #133).

- Fixed a literal `${VAR}`-shaped sequence inside a forwarded `-d` ini override getting silently expanded by the no-pcntl standby worker's own INI parser instead of passed through as the literal text it was in the daemon: forwarded values are now quoted with single-quoted raw INI (`name='value'`) whenever the value contains no single quote, which PHP's ini parser never interpolates and never escapes, falling back to the original double-quoted+escaped form only when the value itself contains a literal `'` (#130, follow-up to #125/#126/#129).

- Fixed (#131): the LSP server's debounced `textDocument/didChange` path (`runDueDiagnostics()`) now reports the same cold-boot `window/workDoneProgress` create/begin/end sequence, and sets the same `hasBootedOnce` bookkeeping, that `didOpen`/`didSave` already got from #111/#128. A client that sends `didChange` for a document without ever sending `didOpen` for it (spec-legal) used to run its first diagnose with no progress notification and without ever flipping `hasBootedOnce`.

- Fixed (#134): on the no-pcntl warm path (Windows, or PHP built with pcntl
disabled), a standby worker running a call kept running to completion even
after its daemon was killed -9 mid-call, because the daemon-liveness poll only
ran while the worker was idle, before a call's request frame arrived -- once
`execute()` started there was no fork and no tick point to hang a check off.
`serveProcessWorker()` now spawns a small watchdog process
(`bin/rector-warm-orphan-watchdog.php`) right before running the call; it
watches the daemon and kills the worker's process tree if the daemon dies
while the call is in flight, instead of the analysis running until it
finishes (or its own `--call-timeout`, or never) for a client nobody is
waiting on any more.

- Fixed (#135): a forwarded `-d` ini override value containing BOTH a single
quote and a literal `${...}` sequence was still expanded by the standby
worker's own ini parser -- `it's ${HOME}` reached the worker as
`it's /Users/...` and `o'k ${precision}` as `o'k 14`. Fixing #130 avoided
interpolation by wrapping a value in ini single quotes, but a value
containing a single quote fell back to one double-quoted whole-value form,
which the ini parser does interpolate. `quoteIniValue()` now splits the
value on each literal single quote, wraps every other run in single quotes
(never interpolated), and represents each quote itself as its own
double-quoted one-character segment -- adjacent quoted ini segments
concatenate with nothing between them, the same trick a shell uses for
`'a'"'"'b'`.

- Fixed `rector-warm.fixWorkspace` (#140): the command computed its edits from
disk, so a file the client had an unsaved (dirty) buffer open for -- a
`textDocument/didChange` the editor had not yet saved -- was silently
overwritten with the disk-derived fix, discarding the unsaved edits, and the
resulting `OptionalVersionedTextDocumentIdentifier` was sent with
`"version": null`, giving the client no way to detect the mismatch. A file
with a dirty buffer is now skipped rather than fixed; the skipped URIs are
reported back in the command's own result under `skippedDirtyBuffers`. A file
that is open but not dirty is unaffected and still gets fixed.

- Fixed (#142): `TempCopySweeper`'s per-directory sweep (`sweepDirectory()`,
called from `RectorDiagnosticsSource::diagnoseBuffer()` on every debounced
`didChange` of an unsaved buffer) reached its destructive `removeIfStale()`
through `glob(..., GLOB_ONLYDIR)`, which follows a symlink -- unlike the
startup sweep's own `is_link()` check. A symlink named like a stale
`.rector-warm-<pid>` candidate (a pid above the platform's live range never
looks alive) could point anywhere, and `removeIfStale()` would unlink every
regular file at the top level of that target, outside the project.
`diagnoseBuffer()` had the same gap through its own `is_dir($tempDirectory)`
check and, separately, its `finally` block's unlink/rmdir -- the temp
directory is an intermediate path component there, not the final one, so an
early return out of the write path does not by itself keep `finally` from
following the same symlink on the way out. `removeIfStale()` now refuses a
symlinked candidate outright, closing both callers at the shared sink, and
`diagnoseBuffer()` refuses a symlinked temp directory both before writing
through it and, independently, before the `finally` block deletes through
it.

- Fixed (#144): the `is_link()` guards #142 added to `TempCopySweeper::
removeIfStale()` and `RectorDiagnosticsSource::diagnoseBuffer()` did not
catch an NTFS junction (`mklink /J`, which -- unlike `mklink /D` -- needs no
elevated privilege) planted at a `.rector-warm-<pid>` name: `is_link()` is
documented reliable for a POSIX symlink and a Windows symlink, but not for
this distinct Windows reparse-point type, confirmed on CI to slip past it
undetected. Both guard sites now also check via `fsutil reparsepoint query`
on Windows, which catches a junction the same way `is_link()` catches a
symlink.

- Fixed (#149): #142/#144's guards on `RectorDiagnosticsSource::diagnoseBuffer()`
only ever checked `$tempDirectory` -- the `.rector-warm-<pid>` directory --
for a symlink or NTFS junction, never `$tempPath`, the buffer's own temp file
inside it. A genuinely real (non-symlinked) directory planted ahead of time,
named after the live server's own pid (which `TempCopySweeper`'s startup
sweep never treats as stale, since it explicitly skips its own pid), passed
both existing checks -- and a symlink at the leaf path, named after the
buffer's basename, was then followed by `file_put_contents()`, overwriting
whatever it pointed to outside the workspace, before `RectorTool`'s own
containment check ever ran (that check only gates whether Rector is invoked
afterwards, too late to stop the write). Both call sites -- the write, and the
`finally` block's unlink -- now also refuse a symlinked or junctioned
`$tempPath`, mirroring the existing `$tempDirectory` guard.

- Fixed (#161): `diagnoseBuffer()`'s #149 leaf guard, `isLinkOrJunction($tempPath)`,
only detects a symlink or (on Windows) an NTFS junction -- it says nothing about a
HARD LINK, a second directory entry pointing at the same inode as a file elsewhere.
`is_link()` is correctly `false` for a hard link (it genuinely is not a symlink), so
one planted at the buffer's temp-file path slipped straight past the guard, and the
write that followed truncated-and-overwrote whatever it pointed at outside the
workspace. The write is now an exclusive creation (`fopen($tempPath, 'x')`, i.e.
`O_CREAT|O_EXCL`), which refuses if a regular file, a hard link, or a symlink whose
target already exists is already at that path, without needing to name what is
there; it also closes the check-then-write TOCTOU gap between the guard and the
write itself for those cases (same family as #145; a narrower residual gap for a
DANGLING symlink specifically is tracked in `trap.d/165.fopen-x-does-not-refuse-a-dangling-symlink.md`,
found and corrected here during #165's own audit round). A second, related gap in
the same method: a pre-existing `.rector-warm-<pid>` directory (e.g. one an attacker
plants ahead of time under this guessable name) was reused via `is_dir()` without
`mkdir()`'s own `0700` ever being reapplied, so it could keep whatever looser mode
its creator gave it. `chmod()` now reapplies `0700` to `$tempDirectory` on every
call, whether or not that call is the one that created it. (#165 later found this
reapply itself insufficient for a directory owned by a different user, and replaced
it with an outright refusal -- see `changelog.d/165.fixed.md`.)

- Fixed (#162): `TempCopySweeper::isLinkOrJunction()`'s Windows fallback shelled out to
`fsutil reparsepoint query <path>`, building the command as a shell STRING via
`escapeshellarg()`. On Windows, `escapeshellarg()` replaces the characters `%`, `!`
and `"` with spaces (documented `php-src` behaviour) -- for a path containing any of
those characters, `fsutil` was asked about a mangled path that does not exist, exited
non-zero, and was read as "not a junction", reopening #142/#144 for that narrower
path shape. The detection now runs through `proc_open()` with the command given as
an ARRAY rather than a string, which bypasses shell-string quoting (and its
mis-escaping) entirely -- PHP builds the Windows command line itself, from the argv
values exactly as given.

- Fixed (#165): #161's fix reapplied `chmod($tempDirectory, 0o700)` to a pre-existing
`.rector-warm-<pid>` temp directory on every call of `diagnoseBuffer()`, including one an
attacker plants ahead of time under this guessable, live-pid name (so the startup sweep skips
it). `chmod()` only succeeds when the calling process owns the target -- for a directory owned
by a different user, the reapply's return value was silently discarded and the guard was a
no-op for exactly the case its own comment named. A pre-existing `.rector-warm-<pid>` directory
is now refused outright rather than reused and repaired: `mkdir()` is already atomic and
exclusive, so refusing whenever it does not succeed closes the gap without needing to know who
owns what is already there. The legitimate repeat-call case (same buffer, same live pid, LSP
calls handled serially) is unaffected, since the method's own `finally` block always removes the
temp directory before returning. Also: the temp file's mode is now narrowed via `umask(0o077)`
around the `fopen(..., 'x')` call itself, so it is created at `0600` from the instant it exists
rather than at the ambient umask's default mode and only narrowed to `0600` afterwards.

## [0.6.0] - 2026-09-28

### Added

- Added: a `bin/rector-warm-lsp` prototype answering the LSP `initialize`/`shutdown` handshake over stdio, and the decision behind it -- hand-rolled JSON-RPC framing rather than `phpactor/language-server`, to stay on the same synchronous fork-per-call worker model as the MCP server instead of introducing a second, event-loop-based scheduler (#52). Diagnostics and code actions are not in this prototype; see #53. It is not installed as a Composer binary yet: run it from a checkout as `php bin/rector-warm-lsp`. It joins `bin` in `composer.json` once #53 ships.

### Fixed

- Fixed: the warm server now loads the target project's own `vendor/autoload.php`, the same way cold Rector does for a composer-global install, so a class that only resolves through the project's own Composer autoloader (most commonly a parent class) is no longer invisible to warm calls (#30).
- Fixed: a warm call whose analysis outlasts `default_socket_timeout` (60s by default) no longer fails with "forked rector call produced no output" -- a read timeout is now told apart from the analysis process actually closing its end of the socket (#32).
- Fixed: an edit to a `withBootstrapFiles()` file mid-session is now picked up on the next call, the same way an edit to `rector.php` itself already was (#20) -- the warm worker reboots when a bootstrap file's content changes instead of staying pinned to what it first required (#33).

- Fixed (#34): a mid-session edit to `composer.json` that raised the PHP constraint (e.g. `^8.1` to `^8.2`) never took effect when `rector.php` uses `withPhpSets()` with no argument -- that method reads `composer.json`'s `require.php` once, at boot, to pick its rule sets, and change detection previously only watched the resolved main config file and any `withBootstrapFiles()` file, so `composer.json` itself was invisible to it. The warm worker kept serving the PHP-set selection from the stale constraint until an unrelated `rector.php` edit forced a reboot. `RectorRunner::configFileChanged()` now also tracks `composer.json`'s own path and content hash, so an edit to it forces a reboot before the next call, exactly like `rector.php`. This is deliberately coarse, mirroring the main config file's own trade-off: any `composer.json` edit forces a reboot, not only one that changes `require.php`, since whether a given `rector.php` actually calls bare `withPhpSets()` is not knowable without executing it.

- Fixed (#45): the no-pcntl cold path no longer hangs forever when a `rector.php` bootstrap writes more than one pipe buffer's worth to stderr (PHP notices, deprecations). `RectorRunner::runCold()` used to read the cold child's stdout and stderr one after another with `stream_get_contents()`; once either pipe filled its OS buffer before reaching EOF, the parent and the child deadlocked, wedging the whole single-threaded stdio server. The cold child's stdout and stderr are now sent to temp files instead of pipes, which have no such bounded buffer -- avoiding the deadlock class on every platform, including Windows, where `stream_select()` over `proc_open()` pipes does not work at all.

- Fixed (#46): a successful cold-path `rector_process` call no longer fails with "produced no output" when Rector's own console output (e.g. a deprecated-set warning from `withSets()`) writes straight to the child's real stdout. That channel was also where `RectorRunner::runCold()` looked for the JSON result, so any such write corrupted a successful analysis into a spurious error. The cold child (`bin/rector-cold-call.php`) now writes its result to a temp file instead; stdout and stderr are read only for diagnostics, never decoded as data.

- Fixed (#58): a genuinely wedged warm call (not merely a slow one) now fails after a configurable `--call-timeout` (default 600s, 0 = unlimited) instead of blocking the caller forever. PR #57's own fix for #32 (retry a socket-read timeout forever instead of mistaking it for the worker dying) had no upper bound of its own, so a truly hung analysis regressed from "errors after ~60s" to "never returns." The killed analysis's grandchild process is reaped so it never lingers as a zombie, the worker itself stays up and serves the next call normally, and the no-pcntl `proc_open` fallback gets the same deadline via `proc_get_status()`/`proc_terminate()`. A worker that wedges DURING its own container build -- before it ever answers `boot()`'s handshake -- gets the same deadline and cleanup, so a hanging `rector.php`/bootstrap file no longer blocks the very first call forever either. The new E2E scenario snapshots the daemon's own process tree (`ps -eo pid,ppid,stat`) right after the killed call and asserts no zombie/defunct descendant and no more than the one persistent worker remains -- the actual "list the daemon's children after the call" check #48 asked for as its own settling criterion, closing #48. See the README's Options table for `--call-timeout`.

- Fixed (#59): `initialize` reported `serverInfo.version = "0.4.1"` even after the v0.5.0 release -- `bin/mcp-rector-warm` hardcoded that literal and `.oss.json`'s `version_sites` never named the file, so the release gate had nothing to bump. The version is now resolved at runtime by `Dpt\McpRectorWarm\Support\ServerVersion::resolve()`: `Composer\InstalledVersions::getPrettyVersion()` when it reports a real released semantic version (a tagged install), falling back to the latest `## [x.y.z]` heading in `CHANGELOG.md` (a plain git checkout of `main` between releases, where Composer only knows a branch pseudo-version like `dev-main`). Nothing needs bumping at release time any more.

- Fixed (#63): a failure resolving a `rector.php`'s `withBootstrapFiles()` paths now writes one line to stderr instead of failing silently. `RectorRunner::resolveBootstrapFileHashes()` still fails open (returns `[]`, so a boot never breaks because this one file list could not be resolved) -- the fix is only that the failure is now visible in server logs rather than looking identical to "this config simply registers no bootstrap files."

- Fixed (#72): `--call-timeout`'s kill sites (`RectorRunner::killAndReap()`, the worker backstop, the no-pcntl `proc_terminate()` path) now apply the deadline only to a `dryRun:true` (analysis-only) call. A `dryRun:false` (write) call is never killed by `--call-timeout` -- it runs to completion exactly as it did before #58 introduced the deadline, at any of the three kill sites, with `$dryRun` threaded explicitly from `RectorTool::process()` through `RectorRunner` and the warm worker's own wire protocol rather than inferred from `$argv`. An earlier version of this fix instead refused every `dryRun:false` call outright whenever a deadline was active -- a breaking change for every caller running under the (now default) 600s `--call-timeout`, and the cause of four e2e failures on PR #75 (`apply-then-recheck`, `apply-twice-idempotent`, `bom-crlf-spaces`, and `test_apply_rewrites_the_file`'s warm-vs-cold oracle). Trade-off: a genuinely wedged write call can now hang indefinitely, in exchange for a write call never being SIGKILLed mid-`file_put_contents()`-style truncate-then-write, which is what could leave a file truncated with no backup. This is a real behaviour change from v0.5.0, not merely "the pre-#58 status quo": before #58 introduced any deadline at all, a wedged write still timed out `RectorRunner::readExactly()`'s socket read after `default_socket_timeout` (~60s) and the daemon reported the call as closed and kept serving later calls. At this release, a wedged write blocks the single-threaded warm daemon indefinitely -- every later call from any client hangs too, not just the wedged one, until the daemon is restarted. The bounded alternative was rejected because it would let a second worker boot while the wedged grandchild still writes the same file, reopening the concurrent-write hazard this fix exists to close.

## [0.5.0] - 2026-09-27

### Added

- Added (#5): an E2E scenario (`tests/E2E/scenarios/abstract-parent-and-child-edited.yaml`) reproducing the shape reported in #5 -- an abstract base class and its child both edited between warm calls -- and asserting no `toMutatingScope()` crash and a clean warm-vs-cold match. The scenario passes on current `main` as written: the reboot+retry recovery from #3 (fb00629) and the per-call fork isolation from PR #17 (710303e, which itself closed #8) already cover this class of stale-scope bug, so #5 closes as fixed by that mechanism, with this test as regression coverage.

- CI leg exercising the no-pcntl warm-reboot fallback (#18): a dedicated `no-pcntl` job disables `pcntl_fork`, `pcntl_waitpid` and `stream_socket_pair` via a `php.ini` override, so the real `boot()`/`reboot()`/`execute()` sequence in `tests/Integration/ServerStdioTest.php` runs `RectorRunner`'s fallback path (#8) end to end over a real subprocess, instead of only through the existing stubbed unit test. That job's own step confirms the three functions are actually unavailable before running, so a future PHP image that silently re-enables them fails loudly rather than passing for the wrong reason. `ServerStdioTest`'s warm-boot assertions are now fork-aware (`expectsForkedWarmth()`) since the fallback legitimately reboots before every call and never reports a later call as warm — a gap the new leg surfaced: those two assertions previously hard-coded the forked-path expectation and would have failed under a genuine no-pcntl run.

- Added (#21): `rector_process` now declares MCP tool annotations (`destructiveHint: true`, `idempotentHint: false`, `openWorldHint: false`, `readOnlyHint: false`, since a non-dry-run call writes files), and its Rector invocation now inserts `--` before the path argument so a path beginning with `-` is never parsed as a Rector CLI flag.

- End-to-end test suite in `tests/E2E/` (#22): the official Python MCP SDK client launches `bin/mcp-rector-warm` over stdio and checks the handshake, `tools/list`, dry-run and applied `tools/call` results, the warm second call, a refused call, a clean exit, and that nothing but JSON-RPC reaches stdout. Run it with `tests/E2E/run.sh`; CI runs it in a new `e2e` job.
- Data-driven warm-session scenarios in `tests/E2E/scenarios/` (#22): each YAML file describes a small codebase and a sequence of edits and `rector_process` calls in one warm session, and every call is checked against a fresh cold `rector process` on an identical copy of the tree, so a new case needs no hand-written expected diff. Known defects (#8, #14, #15, #16, #19, #20) are strict expected failures that flip when fixed.

- Class-hierarchy E2E scenarios (#22): eleven warm-session cases in `tests/E2E/scenarios/` where an edit to a parent, grandparent, interface or a newly created parent file, or to the `extends`/`implements` clause, flips what `AddOverrideAttributeToOverriddenMethodsRector` or `AddTypeToConstRector` proposes: `extends-added`, `extends-removed`, `implements-added`, `parent-gains-existing-method`, `parent-method-removed`, `grandparent-edited`, `interface-extends-interface`, `parent-visibility-changed`, `new-parent-file-mid-session`, `abstract-parent-method-added` and `interface-constant`. Each checks the warm result against a cold `rector process` after every call, and at least one call per case must report a changed file.

- `tools/warm-vs-cold.py` (#22): checks mcp-rector-warm against cold Rector on a real project. It runs one warm session over the official Python MCP client and a fresh `rector process --dry-run` for each file (in parallel with `--jobs`), on a seeded sample spread across directories. It writes a JSON report and a Markdown report (matches, mismatches with both diffs, warm and cold errors, p50/p95 timings) and exits non-zero on any mismatch. Every call is a dry run. A generated wrapper config moves Rector's cache to scratch. `--project-autoload` runs this checkout the way a project that installs the package in `require-dev` loads it. See "Checking against a real project" in CONTRIBUTING.md.

- E2E regression scenarios: `apply-twice-idempotent`, `autoload-dir-created-later`, `bom-crlf-spaces`, `class-moved-namespace`, `composer-php-version-raised`, `file-outside-configured-paths`, `function-return-type-edited`, `parent-deleted-child-remains`, `readonly-parent-toggled`, `skip-path-created-later`, `syntax-error-then-fixed`.
- E2E strict xfails for filed defects: `project-composer-autoload` (#30), `config-declares-class-reboot` and `config-declares-class-edited` (#31), `slow-call-over-socket-timeout` (#32), `bootstrap-file-gains-class` (#33), `composer-php-sets-raised` (#34).
- E2E harness: a scenario-level `php_ini` key passes `php -d key=value` to the server only, so #32 reproduces in seconds with `default_socket_timeout: 3`; `stdio_tap.py` now closes the client side when the server exits, so a crash (#31) fails fast as `Connection closed` instead of waiting out the call timeout.

### Fixed

- Fixed (#8): the warm daemon no longer returns a class's stale types after that class is edited on disk. Every `rector_process` call now runs isolated in a forked child process (falling back to a full container reboot before each call where `pcntl` is unavailable, e.g. Windows), so a class edited between calls is seen with its current shape instead of the type PHPStan's own reflection cache had recorded for the process lifetime. The per-call `resetReflectionState()` is removed: it guarded on a `tagged()` method Rector's container does not implement, so it had never run, and with every call forked (or rebooted) it has nothing left to reset.

- Fixed (#14): a `rector_process` call in a project with no `rector.php` (and no `--config` given at server startup) now returns a real error instead of a silent, misleading success. Rector's own CLI treats a missing config as friendly onboarding -- it prints a warning via a `SymfonyStyle` that writes straight to the real stdout, bypassing `ob_start()`, and reports exit code 0 for a call that did nothing. `rector_process` now refuses before any of that runs, with `exit_code: -1` and a clear `error` naming the missing config.

- Fixed (#15): a project's `rector.php` printing while it loads (an `echo`, a notice, a deprecation) no longer corrupts the MCP JSON-RPC stream on stdout. Config resolution and container boot are now wrapped in the same output-buffer pattern already used around Rector's own analysis run, and PHP's error display now goes to stderr (`display_errors=stderr`) rather than wherever stdout happens to point.

- Fixed (#16): a failed `rector_process` call (a path outside the working directory, a nonexistent path, or an exception raised inside Rector's own processing) now comes back as an MCP tool error (`isError: true`) instead of an ordinary successful result, so a host can see the failure and self-correct. The structured details (`exit_code`, `error`, `error_class`, `trace`) are preserved in `structuredContent`.

- Fixed (#20): editing `rector.php`/`rector.dist.php` mid-session no longer waits for a server restart to take effect. Before every `rector_process` call, the warm daemon compares a sha256 of the resolved config file's current bytes against the one it booted from; a changed hash forces a container reboot before that call runs, so the new rules apply on the very next call. Content hash rather than mtime+size: mtime has whole-second resolution on common filesystems, so two edits within the same second (or a save strategy that writes the same mtime back) can be indistinguishable from "unchanged" for mtime+size, while a hash always reflects the exact bytes about to be `require()`'d. Only the main config file is tracked -- a `rector.php` that itself `require`s a shared file is a known limitation, not silently ignored (see the README); touching/editing the main config file is the reliable way to force a reboot after changing a file it includes. A config edit that breaks the container (e.g. an unknown rule class, or a `--config` path deleted since the last boot) is now reported as a failed MCP tool call (`isError: true`, per #16) with the real exception's class and message, instead of crashing the daemon or silently reporting no changes, and a later valid edit recovers on the next call.

- Fixed (#27): a `rector_process` call against a `rector.php` that loads fine but registers zero rules (and zero sets) now returns a real error instead of leaking Rector's onboarding text onto the MCP stdout stream. Rector's own `ProcessCommand` treats "no rules loaded" the same as "no config at all" and prints a warning via a `SymfonyStyle` bound straight to the real stdout, bypassing `ob_start()` (follow-up to #14). `rector_process` now refuses before `$application->run()` is ever called, with `exit_code: -1` and a clear `error` naming the empty rule set.

- Fixed: rebooting the warm container no longer crashes the server with a PHP fatal ("Cannot declare class/function already declared") when `rector.php` (or a `withBootstrapFiles` file) declares a class or function. Booting now always happens in a process that has never booted before -- a forked worker on platforms with pcntl, a fresh `php` subprocess per call otherwise -- rather than re-`require`ing the config in the same long-lived process on a config edit or on the no-pcntl fallback (#31).

- Fixed: removing a `withSkip()` entry (or any other Rector config parameter) mid-session no longer keeps applying it. Rector's `SimpleParameterProvider` statics (used for `Option::SKIP` and others) are merged, never reset, so an in-process reboot used to leave the earlier boot's parameters in effect even after the config changed. #31's fix already boots every reboot in a genuinely fresh process, which starts these statics clean, closing this as the same root cause (#41).

### Security

- Security: `trap.d/22.supertool-worktree-config-boundary.md` embedded the maintainer's absolute local home-directory path (`/Users/<name>/Documents/mcp-rector-warm/.supertool.json`). `trap.d/` ships in the dist archive of every tagged release (no `.gitattributes` export-ignore excludes it), so this disclosed the maintainer's local username and directory layout to anyone downloading the tag. Replaced with the repo-relative form `<repo>/.supertool.json`, and added a regression test (`tests/Unit/TrapDNoHostPathsTest.php`) that scans every `trap.d/*.md` fragment for an absolute `/Users/<name>/` or `/home/<name>/` path (#44).

## [0.4.2] — 2026-08-22

### Changed

- `mcp/sdk` requirement raised to `^0.7.1`, picking up the fix for the SSE transport's unbounded read buffer. This server speaks stdio, so the advisory never applied to it in practice; the bump keeps the dependency current and lets a consumer install all four DPT warm servers side by side on one SDK version.

## [0.4.0] — 2026-06-21

### Fixed

- **Warm reflection state no longer leaks between files (claude-supertool#273).** The warm container reused one Rector `Application` across every call but reset nothing between them. Rector's `DynamicSourceLocatorProvider` caches its `AggregateSourceLocator` for every non-PHPUnit run, so the second (and every later) file was analysed with a source locator that only knew the *first* file — and Rector emitted `System error: "ClassReflection must be resolved for class X"` on test classes whose hierarchy the stale locator could not resolve. A fresh `rector` CLI process never hit this; the warm daemon did, and the failure was being content-hash-cached and replayed (2100 poisoned entries). `RectorRunner::run()` now resets every service tagged `ResettableInterface` before each warm call — the same flush `AbstractRectorTestCase` performs between fixtures — so the warm daemon matches a cold CLI boot. The expensive bootstrap stays warm; only per-run reflection state is flushed (~no measurable per-call cost). This makes the rector-mcp adapter's `System error:` suppression defense-in-depth rather than load-bearing.

### Added

- **`testWarmReflectionMatchesColdForSequence` regression (env-gated).** Drives a sequence of files through one warm server and asserts no `System error` / stale-reflection failure. Self-contained synthetic classes cannot reproduce #273 (in-process PHPUnit disables the locator cache via `isPHPUnitRun()`, and the trigger needs real framework base classes extended from outside the configured paths), so the test points at a real project via `MCP_RECTOR_WARM_REPRO_DIR` / `MCP_RECTOR_WARM_REPRO_FILES` / `MCP_RECTOR_WARM_REPRO_BIN` and skips otherwise. `tools/repro-273.py` discovers a triggering sequence.

## [0.3.0] — 2026-05-23

### Security

- **Path containment on `rector_process`.** Previously the `$path` argument was forwarded straight to Rector. With `$dryRun=false` (toggleable from MCP), a hostile client could trigger refactor **rewrites** on arbitrary PHP files outside the configured working dir; with `$dryRun=true` the JSON `file_diffs` could leak file contents. `RectorTool::process()` now realpath-canonicalises `$path` against `realpath(getcwd())` (pinned at boot via `--working-dir`) and returns a `SecurityError` for out-of-cwd targets before Rector boots.

### Added

- Unit tests `RectorToolContainmentTest::testRejectsPathOutsideWorkingDir` + `testRejectsNonexistentPath`.

## [0.2.1] — 2026-05-22

### Fixed

- Re-added `--debug` to RectorTool argv. Without it, rector parallel mode scans all configured paths even for single-file analysis (~14s per call on a project with 677 paths). `--debug` forces single-thread, brings warm calls back to ~70ms. The argv[0] spoof + findRectorBin() helper stay so consumers wanting parallel mode can drop `--debug` themselves.
- Trade-off: `--debug` suppresses rector's `file_diffs[].applied_rectors` in JSON output. Consumers parsing the output get back only `changed_files: [path]` — the supertool adapter (`validators/rector-mcp/`) filters those bare entries client-side now.

## [0.2.0] — 2026-05-22

### Changed

- Re-enabled Rector parallel mode. Previous `--debug` workaround disabled both parallel + diff generation; output was just `changed_files: [path]` without `file_diffs` or `applied_rectors`.
- `RectorRunner` now spoofs `$_SERVER['argv'][0]` to the real rector binary path before `Application::run()`. Rector's parallel workers `proc_open(PHP_BINARY . ' ' . $_SERVER['argv'][0] . ' worker --port=X')`, so they now spawn rector correctly instead of trying to re-launch the MCP server bin.
- `RectorTool` drops `--debug` from argv — full parallel + diff generation enabled.
- New private `findRectorBin()` resolves the rector binary via `Composer\InstalledVersions` or vendor/bin fallbacks.

### Result

MCP `output` field now contains `file_diffs[].applied_rectors` + `diff` so consumers can show which rules want to refactor + what would change.

## [0.1.6] — 2026-05-22

### Fixed

- Capture Rector's `JsonOutputFormatter` raw `echo` via `ob_start()`. Previously the JSON body was lost (silent stdout leak that bypassed Symfony's BufferedOutput), leaving the MCP `output` field empty and adapters unable to parse `changed_files` / errors.

## [0.1.5] — 2026-05-22

### Fixed

- Resolve `scoper-autoload.php` via `ReflectionClass(RectorConfigsResolver)` instead of a hardcoded relative path. Fixes boot when mcp-rector-warm is installed as a project dependency (rector lives parallel in the project's vendor, not nested).

## [0.1.4] — 2026-05-22

### Fixed

- `composer.json` Rector constraint reverted to `^2.0` (was accidentally pinned to `2.2.7` in v0.1.2/v0.1.3 because `composer require` rewrote the spec during local testing).

## [0.1.3] — 2026-05-22

### Fixed

- `bin/mcp-rector-warm` is actually shipped 100755 this time. v0.1.2 still had 100644 in the published tree.

## [0.1.2] — 2026-05-22

### Fixed

- `bin/mcp-rector-warm` is now stored with `100755` (executable) mode in git. Previous releases shipped as `100644`, requiring a manual `chmod +x` on every fresh clone or composer install.
- Loosened `rector/rector` constraint to `^2.0` (was `^2.4`). Tested against 2.2.7 (DVSI's pinned version) — same API surface, prefix-detection still works.

## [0.1.1] — 2026-05-22

### Fixed

- `bin/mcp-rector-warm` now supports both local-clone and composer-global install paths. Previously the bin tried to load `__DIR__/../vendor/autoload.php` only, which fails when the package is installed as a dependency (project-local or global).

## [0.1.0] — 2026-05-22

### Added

- Warm-process MCP server `mcp-rector-warm` keeping Rector's container hot across calls.
- `rector_process` tool exposing dry-run and apply modes via MCP stdio.
- Auto-detection of Rector's runtime-prefixed `RectorPrefix<date>\\Symfony\\Console\\...` namespace for forward compatibility.
- Parallel mode forcibly disabled (`--debug`) so workers don't try to re-spawn the MCP binary.
- PHPUnit unit + integration tests covering boot, tool listing, warm reuse (`warm_boot: true` on second call).
- Standalone CLI: `--working-dir`, `--config` flags pinned at server start.

[Unreleased]: https://github.com/Digital-Process-Tools/mcp-rector-warm/compare/v0.7.0...HEAD
[0.7.0]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.7.0
[0.6.0]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.6.0
[0.5.0]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.5.0
[0.4.2]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.4.2
[0.4.0]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.4.0
[0.2.1]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.2.1
[0.2.0]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.2.0
[0.1.6]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.1.6
[0.1.5]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.1.5
[0.1.4]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.1.4
[0.1.3]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.1.3
[0.1.2]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.1.2
[0.1.1]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.1.1
[0.1.0]: https://github.com/Digital-Process-Tools/mcp-rector-warm/releases/tag/v0.1.0
