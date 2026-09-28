---

# Declined trap.d fragments

One line per fragment this pass declined to turn into a rule -- never a bare "declined", always a
citation or a stated "could not tell" (per `commands/run/curate.md`'s #1736 checklist for a
fragment whose claim is about the code).

- **`trap.d/14.zero-rules-config-still-leaks.md`** (still true when filed, checked against HEAD by
  curate 2026-09-27) -- the fragment itself already states the gap it describes (`ProcessCommand`'s
  own `areSomeRectorsLoaded()` check bypassing #14's `boot()` guard) was resolved by #27, commit
  `3d99bd3`: `RectorRunner::execute()` now checks the built container's
  `ConfigInitializer::areSomeRectorsLoaded()` before `$application->run()`. Already fixed, kept
  only as the record of why `boot()`'s own #14 guard could never have covered it -- no rule needed.
- **`trap.d/33.bootstrap-hash-fail-open.md`** (still true, checked against HEAD by curate
  2026-09-27) -- `resolveBootstrapFileHashes()` in `src/RectorRunner.php` still catches
  `\Throwable` and returns `[]` silently, with no stderr line distinguishing "no bootstrap files"
  from "resolution failed". Filed as #63 rather than turned into an agent-facing rule: this is a
  one-off finding about the code (a suggested diagnostic improvement, not a lesson an agent should
  have learned before touching this file), so it belongs on the tracker, not here.
- **`trap.d/58.killAndReap-wnohang-branch-untested.md`** (still true, checked against HEAD by curate
  2026-09-28) -- `killAndReap()`'s WNOHANG branch in `src/RectorRunner.php` (taken when
  `posix_kill` is unavailable) is still reachable only when pcntl is present and posix is absent,
  and no CI leg forces that combination. Already filed as #71 by an earlier round; not turned into
  a rule since this is a one-off finding about test coverage, not a lesson an agent should have
  learned before touching this file.
- **`trap.d/58.zombie-check-ps-portability-and-boot-e2e-gap.md`** (still true, checked against HEAD
  by curate 2026-09-28) -- two independent gaps in `tests/E2E/mcp_harness.py`, both still present:
  `process_descendants()`'s `ps -eo pid,ppid,stat` column parsing is unverified on a non-procps-ng
  `ps` (already disclosed in the function's own docstring), filed as #79; and no E2E scenario
  drives a wedged BOOT (only a wedged CALL) through the real process-tree zombie check, filed as
  #80. Neither is an agent-facing lesson -- both are one-off coverage gaps, so they belong on the
  tracker rather than here.
- **`trap.d/72.wedged-write-now-hangs-the-whole-server-not-just-the-call.md`** (already fixed,
  checked against HEAD by curate 2026-09-28) -- the #72 release notes gap this fragment describes
  (README/changelog not disclosing that a wedged write now hangs the whole daemon indefinitely,
  where v0.5.0 timed out the socket read after ~60s) was fixed by commit `410118c` (PR #76,
  "Correct the #72 changelog/README wedged-write description"): `README.md`'s `--call-timeout` row
  now states this exact trade-off in full. No rule needed.
