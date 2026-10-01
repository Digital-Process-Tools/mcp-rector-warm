---
title: "A fresh git worktree has no vendor/ -- run composer install before phpunit or the validators"
tool: Bash
match: "git worktree add"
mode: remind
---

`vendor/` is ignored, so `git worktree add` never checks it out. `./vendor/bin/phpunit` fails
outright, and the `phpstan` and `rector` supertool validators (which run `vendor/bin/mcp-phpstan-warm`
and this checkout's `bin/mcp-rector-warm`) report "not found" or "NOT CHECKED" until
`composer install --no-interaction --quiet` runs in the new worktree. Nothing else is needed.

`.supertool.json` is tracked since #204, so a worktree of any commit after that has it, and the
`gh-*`/`git-*` ops work there with no copy. A worktree of an older commit still lacks it: copy it
from the primary clone, and remove the copy when done.
