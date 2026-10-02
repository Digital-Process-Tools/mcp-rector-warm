---
title: "A PHPStan |false narrowing check must fail loudly, not silently shrink/degrade the value"
match: "(src/RectorRunner\.php|bin/rector-cold-call\.php|bin/rector-warm-orphan-watchdog\.php)"
mode: once
---

**#211.** Raising PHPStan's level forces a runtime check at every stdlib call that can return
`false` where a real type is expected (`getmypid()`, `sha1_file()`, `getcwd()`,
`stream_socket_pair()`, an `array_filter(..., is_string(...))` narrow). This codebase's own
established pattern at every OTHER such site is to fail loudly, not degrade silently:
`ruleSkippedFor()` throws `RuntimeException`; `$this->procWorker` gets
`\assert($this->procWorker !== null)`.

**Two sites still break that pattern (#211, not fixed there -- left as a trap.d finding):**

- `array_values(array_filter($request['argv'], is_string(...)))` -- 4 call sites in
  `src/RectorRunner.php` plus `bin/rector-cold-call.php`'s `$callArgv` -- silently DROPS any
  non-string element from argv instead of asserting the array was already all-strings. If the
  premise it relies on ("the daemon always builds this as list<string>") is ever wrong, warm's
  write path silently gets a shorter argv than cold would -- CLAUDE.md's "writes equal cold" rule,
  and CI's `--dry-run` cannot catch a shorter change.
- `bin/rector-warm-orphan-watchdog.php`'s `$selfPid = \getmypid(); ... $selfPid !== false ? [$selfPid] : []`
  -- a `false` return degrades the exclude-list to `[]`, which is the one case
  `ProcessTree::killTree()`'s own docblock names as the watchdog self-deadlock trigger (SIGSTOP
  mid-kill). Checked: NOT a regression from #211 -- the old code passed `[false]`, which
  `array_diff()` string-casts and never matched a real pid either, so old and new are
  behaviourally identical here. The dormant self-deadlock risk on an actual `getmypid() === false`
  was never fixed by anyone.

**Before adding the next PHPStan-forced narrowing check in these files:** match the loud-failure
pattern (`\assert()` or throw) rather than reaching for a filter/fallback that quietly produces a
smaller or different result -- a silent shrink is invisible to `--dry-run` and to every other
read-only check this repo relies on.
