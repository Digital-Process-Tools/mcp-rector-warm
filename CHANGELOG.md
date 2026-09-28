# Changelog

All notable changes to this project will be documented in this file.

This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/Digital-Process-Tools/mcp-rector-warm/compare/v0.6.0...HEAD
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
