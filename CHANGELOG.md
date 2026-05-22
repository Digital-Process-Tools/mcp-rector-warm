# Changelog

All notable changes to this project will be documented in this file.

This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
