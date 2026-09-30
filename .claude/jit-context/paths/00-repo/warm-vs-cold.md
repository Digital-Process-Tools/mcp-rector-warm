---
title: "tools/warm-vs-cold.py: warm vs cold Rector on a real project, dry run only"
match: "tools/warm-vs-cold"
mode: once
---

One warm session (official Python `mcp` client, `rector_process` with `dryRun: true`, files in sample order) against a fresh `rector process --dry-run` per file (`--jobs` in parallel). Both sides use `RECTOR_FLAGS`, the flags `RectorTool::process()` builds.

**Run** (`php` is often an alias, so pass `--php`). System `python3` has no `mcp` module (`ModuleNotFoundError: No module named 'mcp'`, nothing measured). Make a venv first, outside the repo: `python3 -m venv V && V/bin/pip install -r tests/E2E/requirements.txt`, then run the script with `V/bin/python`.

| want | command |
| --- | --- |
| fixture sanity | `tools/warm-vs-cold.py --project tests/Fixtures/project --files 'src/*.php' --php /path/to/php --out /tmp/wvc` |
| real project, installed the way `require-dev` loads it | `tools/warm-vs-cold.py --project P --project-autoload P/vendor/autoload.php --php /path/to/php --files @src.txt --files @tests.txt --limit 300 --seed 20260927 --jobs 4 --progress --out /tmp/wvc` |
| smoke test first | the same with `--limit 30 --seed 1` |

- `--files` takes a glob relative to `--project` or `@LISTFILE`. Under `--limit`, `select_files` shuffles directories with `--seed` and takes one file per directory per round. Put `*Test.php` in the list: the old warm-state bugs showed up on test files.
- Without `--project-autoload` the mode is `checkout`: this repo's `bin/` + `vendor/`. With it, the mode is `project-vendor`: `build_project_vendor_shim` loads the project's Composer autoloader and puts this checkout's `src/` in front of it. `meta.shim_loaded` in the report shows the file each class came from. Check it.
- Env vars reach both sides (e.g. a config that switches rule sets on `RECTOR_MODE`).
- `--project-autoload` is `<composer.json dir>/<config.vendor-dir>/autoload.php`. A project can move `vendor-dir`, so read `composer.json` before assuming `vendor/`.

**Real-project rules:**
- Dry run only, on both sides. There is no flag to apply anything; do not add one.
- `WRAPPER_PHP` requires `--config` once and calls `withCache()` again, so the cache goes to `OUT/cache/{warm,cold/N}`. The project's cache dir is never written, and a stale entry cannot hide a difference.
- A config that declares functions or classes can only be required once per process. A warm reboot then crashes the server (#31). The script counts a crash as a warm error and starts a new session. `meta.warm_sessions` > 1 means that happened.
- Check what the config does when it loads. A config that deletes caches or creates dirs writes to the project on every boot. Point `--project` at a copy (`cp -Rc` clones instantly on APFS), and hash the original's working-tree status before and after.

**Read the report** (`OUT/report.md`, full detail in `report.json`):
- `match`: exit code, `changed_files`, diffs and errors are all equal after `normalise()` from `tests/E2E/mcp_harness.py` scrubs the root.
- `mismatch`: both sides produced a report and they differ. Both diffs and each side's `applied_rectors` are listed. Exit 1.
- `warm_error` / `cold_error` / `both_error`: that side had no JSON report (tool `isError`, crash, timeout). Exit 2 if there is no mismatch. The cold stderr tail is in the report.
- "Files with a diff" counts diffs. Rector can list a file under `changed_files` with no diff (totals 0). That list is still compared.
- Timings: the first warm call includes the boot. Any later call right after a restart (a
  new `session`) pays a boot cost too -- it is split into a separate `warm_restart_boot`
  bucket (report shows the row only when it happened), never folded into "warm, later
  calls". Compare "warm, later calls" p50/p95 with cold per call.
- **For a speed number, run `--jobs 1`.** Parallel cold runs share CPU, which makes each cold call slower and inflates the ratio.

**Speed baseline** (2026-09-30, Apple M3 Pro, PHP 8.3.11 NTS, no OPcache, 30 files per project, `--jobs 1`, 30/30 match). This is the number behind the README and docs/benchmark.md; method and file sampling are there:

| project, mode | warm later p50 / p95 | cold p50 / p95 | warm first call |
| --- | --- | --- | --- |
| laravel v13.34.0, `MCP_RECTOR_WARM_SESSION=1` | 76 / 1243 ms | 740 / 2271 ms | 1531 ms |
| laravel, default | 224 / 1672 ms | 749 / 2008 ms | 1570 ms |
| symfony v7.4.20, `MCP_RECTOR_WARM_SESSION=1` | 100 / 447 ms | 696 / 1286 ms | 2255 ms |
| symfony, default | 159 / 557 ms | 659 / 1172 ms | 1872 ms |

- The container is built **lazily, on the first `rector_process` call**, not at start. The first call costs the boot plus the first file: two to three cold runs on Laravel and Symfony.
- `warm_first` in `report.json` is **not the boot cost**. The timer (`started` in `run_warm`) starts after `session.initialize()`, and the first file's own work is included. With a heavy first file it read 10.1s. To measure boot, start each session on a small file, repeat over fresh sessions, and time spawn-to-initialize separately.
- Only the `warm_later` p50 against the `cold` p50 is a like-for-like comparison. If warm later-call p50 on Laravel or Symfony goes well above the table's, treat it as a regression. Update the README table and this baseline together.

**A mismatch means** the warm server answered differently from a fresh process on the same bytes. Reduce it:
1. Re-run that one file cold and warm-alone (`--files @one.txt`). If warm-alone matches, earlier files in the session are poisoning it: bisect the prefix of `files.txt`.
2. Narrow the config to the rule(s) in `applied_rectors` that differ, then to the cross-file dependency the rule reads (parent class, trait, autoload path).
3. Compare with #8/#19 (reflection state), #20/#33/#34 (config or bootstrap changed), #30 (project autoloader missing: `checkout` mode only), #31 (reboot), #32 (socket timeout), #27 (zero rules). If none fits, file an issue that describes the shape of the code, then add an xfail scenario under `tests/E2E/scenarios/`.
