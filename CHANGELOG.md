# Changelog

All notable changes to this project will be documented in this file.

This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
