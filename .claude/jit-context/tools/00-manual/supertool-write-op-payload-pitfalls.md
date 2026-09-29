---
title: "supertool edit:@-/paste:@- payloads: cwd does not inherit across Bash calls, and a TOML literal block never consumes a backslash"
tool: Bash
match: ~(edit:@-|paste:@-)
mode: remind
---

**cwd does not inherit from an earlier `cd` in a different Bash call.** The harness resets cwd
between separate Bash tool calls, so a bare `supertool 'edit:@-' <<'TOML' ...` with no `cd` in
THAT SAME call resolves against the harness's default cwd -- typically the primary clone, not
whichever worktree an earlier call `cd`'d into. Confirmed cost: two edits landed silently in the
main clone instead of a lane's own worktree; both reported success, and the only tell was the
receipt's trailing `[branch: main]` line instead of the expected `[branch: fix/N]`.

- Fix: prefix `cwd:ABSOLUTE_PATH` as its OWN separate op in the same call --
  `supertool 'cwd:/abs/path' 'edit:@-' <<'TOML' ...`. `cwd:.:edit:@-` (colon-chained) fails with
  `not a directory`; `cwd:` must be a separate quoted argument, never colon-joined to the next op.
- Check: read the receipt's trailing `[branch: ...]` line on every write op and confirm it names
  the lane's own branch, not the repo's default branch.

**A TOML literal block (`'''...'''` / `"""..."""`) processes no escapes -- what you type is
what lands on disk.** Writing `\\n` (doubled backslash) in the `new`/`content` field, meaning
"one literal backslash then n", puts TWO characters on disk when the target language (e.g. PHP
`"...\n"`) needs the single two-character escape sequence `\n`. Write exactly ONE backslash for
a language-level `\n`, `\t`, etc. -- doubling it "to be safe" is wrong, and is refused by the
guard (which names the exact run and offers both readings) before it reaches disk.
