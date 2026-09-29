---
title: "mcp-rector-warm: a feature PR waits for the maintainer's OK; an LSP PR waits for an independent E2E"
tool: Bash
match: ~gh-pr-merge
mode: remind
---

Green CI and a passed review are not enough in this repo. Before `gh-pr-merge:N:...|force`:

| Check | How | If it hits |
| --- | --- | --- |
| Feature? | `supertool 'gh-issue:<closed issue>'`: label `enhancement`, or the PR adds a new LSP method/command, CLI flag, MCP tool or config option | **Do not merge.** Report `ready -- awaiting maintainer OK` and stop. |
| LSP? | `supertool 'gh-pr:N:diff'` touches `src/Lsp/` or `bin/rector-warm-lsp` | Merge only if the PR has an **independent** E2E result as a comment: warm == cold byte for byte, from a harness the lane did not write. The lane's own `tests/E2E/` run does not count. |

- Neither hits (bug fix, tests, docs, CI): merge on green as usual.
- #137 (`rector-warm.fixWorkspace`, issue #102, label `enhancement`) merged on green with neither gate. That is the case this rule exists for.
- The source rule is `CLAUDE.md` § "Before you merge a pull request".
