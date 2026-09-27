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
