---
title: "Changing src/ or bin/: prove it warm-vs-cold, flip the xfail, rebase before merge"
match: "(^|/)(src|bin)/"
mode: once
---

A change here is judged by one oracle: **the warm answer must equal a cold `vendor/bin/rector process` on the same tree**. Unit tests alone do not show that. The costly bugs return "0 changes" silently, so they look like a clean run.

**Before you change code:**
- Reproduce as an E2E scenario first: one YAML in `tests/E2E/scenarios/`, marked `xfail: "#NN: ..."` (strict). See `e2e-harness.md`.
- Run `tests/E2E/run.sh --runxfail -k <name>` and watch it fail for the stated reason.

**The fix:**
- It must turn the scenario green. `xfail_strict` makes a leftover marker fail as XPASS, so **delete the marker** in the same PR.
- If the full suite XPASSes an *unrelated* xfail, your fix closed that issue too: remove its marker and add `Closes #N`.
- Run both suites: `tests/E2E/run.sh` and `./vendor/bin/phpunit --no-coverage`. Set `PHP_BINARY=` if `php` is an alias.

**Warm-state rules** (`RectorRunner`):
- Every call runs in a child forked from the booted parent (`runForked`), so the parent must never analyse anything.
- Never `require` `rector.php` or a bootstrap file twice in one process. A config that declares a class or function makes that fatal (#31).
- Output hygiene: nothing but JSON-RPC on fd 1. Wrap boot in `ob_start()`; `display_errors=stderr` is set in `bin/mcp-rector-warm` (#14, #15).
- A failure returns `CallToolResult` with `isError: true` through `RectorTool::errorResult` (#16).

**Real project:** `tools/warm-vs-cold.py`, dry-run only (see `warm-vs-cold.md`).

**Merging:**
- Branch protection needs the branch up to date: `gh pr update-branch N --rebase`, wait for CI, then `supertool 'gh-pr-merge:N:squash|force|cleanup'`.
- Parallel lanes: split by file. `RectorTool.php`, `bin/` plus output handling, and boot/lifecycle are three separate lanes.
- When main moves, rebase and re-run the full E2E suite.
