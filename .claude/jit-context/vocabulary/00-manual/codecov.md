---
title: "Codecov: tokenless uploads are refused here -- CI uses the CODECOV_TOKEN repo secret"
description: "How coverage reaches Codecov for mcp-rector-warm, what was set up on 2026-10-01, and how to add another repo."
keywords: codecov, codecov token, coverage badge, coverage upload
mode: once
---

| Piece | State (set up 2026-10-01, #209 / PR #214; extended #218) |
| --- | --- |
| Codecov GitHub App | installed on the **Digital-Process-Tools** org, **selected repositories only** (not "All": that grants read on every private repo) |
| Token | **repository** upload token for mcp-rector-warm, stored as repo secret `CODECOV_TOKEN` (not the org-wide global token) |
| CI | `coverage` job in `.github/workflows/tests.yml` (ubuntu + macOS matrix, PHP 8.3, pcov) plus a simpler `coverage-windows` job: each uploads via `codecov/codecov-action@v5` with `token: ${{ secrets.CODECOV_TOKEN }}`, `fail_ci_if_error: false`, and its own `flags:` (`linux`/`macos`/`windows`) -- the `flags:` on the **action call** is what makes Codecov merge the three instead of the last upload winning; `codecov.yml`'s own `flags:` block just names them for the dashboard's per-OS breakdown, with no `paths` restriction (an explicit `paths: []` was tried and dropped -- Codecov's handling of an empty list for a flag is not clearly documented, and risks zeroing that flag's reported coverage instead of leaving it unrestricted) |
| Always available, with or without Codecov | the job summary: coverage % before and after merging forked/subprocess children (#218), plus the 10 slowest tests (`tools/slowest-tests.py` from `junit.xml`); `junit-log-<os>` artifact |
| Child/subprocess coverage (#218) | pcov only instruments the one process phpunit runs in. `tests/coverage/prepend.php`, loaded via `PHP_INI_SCAN_DIR`-injected `auto_prepend_file` only when `MCP_RECTOR_WARM_COVERAGE_DIR` is set, has every `pcntl_fork()` child and every `proc_open()` subprocess (`bin/*`) write its own `.cov` file; `vendor/bin/phpcov merge` folds them into the clover Codecov receives. No-op (zero added branches) when that env var is unset -- true of every non-coverage CI leg and of a plain local run. Not wired on Windows: `RectorRunner::canFork()` is always false there (#31), so there is no forked-child gap to close on that platform. |

- **Keep the token even though tokenless may work now.** Before the Codecov app was installed on the org, the first run logged `Upload queued for processing failed: {"message":"Token required - not valid tokenless upload"}`. Afterwards Codecov's dashboard said "Your org no longer requires upload tokens", which is an org setting an admin can flip back. With the token, the upload works either way. The first upload with the token (run on 7b98295) logged `Token length: 36` and `Upload queued for processing complete`.
- **A green coverage job does not mean the upload worked**, because `fail_ci_if_error: false` keeps the job green on a refused upload. Grep the job log: `supertool 'gh-job:<id>:grep:Upload queued'`.
- **The dashboard and the "Deactivated" label:** Codecov shows nothing, and lists the repo as "Deactivated", until a report on the default branch (`main`) has been processed. Uploads from a PR branch alone do not fill it.
- **Check the secret:** `gh secret list --repo Digital-Process-Tools/mcp-rector-warm` should show `CODECOV_TOKEN`. Never paste the token into chat or a file; set it with `gh secret set CODECOV_TOKEN --repo ...`, which prompts for it.
- **`codecov.yml` (repo root):** `comment: false` (no bot comment on PRs) and `informational: true` on the `project` and `patch` statuses, so they show and never fail a PR. To make coverage a gate later, replace `informational` with a `target`/`threshold`. PRs still upload; that is what gives patch coverage and the base-vs-head comparison.
- **The README badge** goes in only after one upload has succeeded with the token; before that it renders as "unknown".
- **Adding another org repo** (for example mcp-phpstan-warm): GitHub → org Settings → GitHub Apps → Codecov → Configure, and add the repo. Then either set that repo's own token as its `CODECOV_TOKEN`, or switch to Codecov's **Global upload token** (Codecov org Settings; needs org admin) as an org secret: `gh auth refresh -h github.com -s admin:org`, then `gh secret set CODECOV_TOKEN --org Digital-Process-Tools --visibility all`. The workflow line does not change.
- Codecov's setup page defaults its "Step 1" example to Jest. Ignore it; phpunit already writes `coverage.xml`.
