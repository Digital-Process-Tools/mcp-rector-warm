---
title: "Codecov: tokenless uploads are refused here -- CI uses the CODECOV_TOKEN repo secret"
description: "How coverage reaches Codecov for mcp-rector-warm, what was set up on 2026-10-01, and how to add another repo."
keywords: codecov, codecov token, coverage badge, coverage upload
mode: once
---

| Piece | State (set up 2026-10-01, #209 / PR #214) |
| --- | --- |
| Codecov GitHub App | installed on the **Digital-Process-Tools** org, **selected repositories only** (not "All": that grants read on every private repo) |
| Token | **repository** upload token for mcp-rector-warm, stored as repo secret `CODECOV_TOKEN` (not the org-wide global token) |
| CI | `coverage` job in `.github/workflows/tests.yml` (ubuntu, PHP 8.3, pcov): `codecov/codecov-action@v5` with `token: ${{ secrets.CODECOV_TOKEN }}`, `fail_ci_if_error: false` |
| Always available, with or without Codecov | the job summary: coverage % plus the 10 slowest tests (`tools/slowest-tests.py` from `junit.xml`); `junit-log` artifact |

- **Keep the token even though tokenless may work now.** Before the Codecov app was installed on the org, the first run logged `Upload queued for processing failed: {"message":"Token required - not valid tokenless upload"}`. Afterwards Codecov's dashboard said "Your org no longer requires upload tokens", which is an org setting an admin can flip back. With the token, the upload works either way. The first upload with the token (run on 7b98295) logged `Token length: 36` and `Upload queued for processing complete`.
- **A green coverage job does not mean the upload worked**, because `fail_ci_if_error: false` keeps the job green on a refused upload. Grep the job log: `supertool 'gh-job:<id>:grep:Upload queued'`.
- **The dashboard and the "Deactivated" label:** Codecov shows nothing, and lists the repo as "Deactivated", until a report on the default branch (`main`) has been processed. Uploads from a PR branch alone do not fill it.
- **Check the secret:** `gh secret list --repo Digital-Process-Tools/mcp-rector-warm` should show `CODECOV_TOKEN`. Never paste the token into chat or a file; set it with `gh secret set CODECOV_TOKEN --repo ...`, which prompts for it.
- **The README badge** goes in only after one upload has succeeded with the token; before that it renders as "unknown".
- **Adding another org repo** (for example mcp-phpstan-warm): GitHub → org Settings → GitHub Apps → Codecov → Configure, and add the repo. Then either set that repo's own token as its `CODECOV_TOKEN`, or switch to Codecov's **Global upload token** (Codecov org Settings; needs org admin) as an org secret: `gh auth refresh -h github.com -s admin:org`, then `gh secret set CODECOV_TOKEN --org Digital-Process-Tools --visibility all`. The workflow line does not change.
- Codecov's setup page defaults its "Step 1" example to Jest. Ignore it; phpunit already writes `coverage.xml`.
