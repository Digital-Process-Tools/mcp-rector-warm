---
title: "A fresh git worktree has no .supertool.json -- gh-*/git-* project ops refuse with 'no project ops loaded'"
tool: Bash
match: "git worktree add"
mode: remind
---

`.supertool.json` is untracked (confirmed: `git ls-files` doesn't list it), so `git worktree add`
never checks it out into the new worktree -- for ANY worktree, not only one nested under the
primary clone. The PreToolUse hook that refuses a raw `git commit`/`git diff`/etc. still finds it
by walking up from cwd and points at the `supertool 'git-commit:...'` op as the fix, but that op
then refuses too: `.supertool.json was found and could not be loaded, so no project ops are
loaded` -- confirmed live in this worktree (`gh-issues`, `gh-issue-create` etc. all missing until
fixed).

**Fix, once per worktree:** `cp <primary-clone>/.supertool.json .` at the worktree root before
any `gh-*`/git-preset op. It's untracked, so it never shows in `git status` -- but remove it
again once you're done (`rm .supertool.json`) so it doesn't linger as a stray copy.

Plain `read`/`edit`/`paste`/`grep` work fine via `cwd:<worktree>` even without the copy -- only
the `git-*`/`gh-*` preset ops need it.
