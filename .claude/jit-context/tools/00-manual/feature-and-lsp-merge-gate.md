---
title: "mcp-rector-warm: a feature PR waits for the maintainer's OK; a PR that can change what warm writes waits for an independent E2E"
tool: Bash
match: ~gh-pr-merge
mode: remind
---

Green CI and a passed review are not enough in this repo. Before `gh-pr-merge:N:...|force`:

| Check | How | If it hits |
| --- | --- | --- |
| Feature? | `supertool 'gh-issue:<closed issue>'`: label `enhancement`, or the PR adds a new LSP method/command, CLI flag, MCP tool or config option | **Do not merge.** Report `ready -- awaiting maintainer OK` and stop. |
| Can change a write? | `supertool 'gh-pr:N:diff'` touches `src/RectorRunner.php`, `src/RectorTool.php`, `src/Warm/`, `bin/rector-warm-worker.php`, `bin/rector-cold-call.php`, `src/Lsp/RectorDiffParser.php`, or LSP code-action / `fixWorkspace` handling | Merge only if the PR has an **independent** E2E result as a comment: warm's applied edits == cold `rector process` byte for byte, from a harness the lane did not write. The lane's own `tests/E2E/` run does not count. |

- Neither hits (bug fix, tests, docs, CI, an LSP change that cannot affect a write): merge on green as usual.
- Why writes and not reads (2026-10-01): a wrong read-only result costs one CI round trip, since users keep cold Rector in CI. A wrong write passes cold `--dry-run` silently.
- #137 (`rector-warm.fixWorkspace`, issue #102, label `enhancement`) merged on green with neither gate. That is the case this rule exists for.
- The source rule is `CLAUDE.md` § "Before you merge a pull request".
