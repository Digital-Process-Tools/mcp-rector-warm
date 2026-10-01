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
- **`trap.d/100.leftover-rule-ambiguity.md`** (still true, checked against HEAD by curate
  2026-09-29) -- `RectorDiffParser::buildFixes()`'s leftover-rule gate (`src/Lsp/RectorDiffParser.php:191-212`)
  can still misattribute a hunk when one rule produces two hunks (only one reported by `changes[]`)
  and a second, unrelated rule produces zero hunks. One-off finding about the code, not an
  agent-facing lesson -- filed as #154.
- **`trap.d/110.adr-0001-stale-line-citations.md`** (still true, checked against HEAD by curate
  2026-09-29) -- `docs/decisions/0001-lsp-library-choice.md`'s line citations for `boot()`/
  `forkAndExecute()`/`readExactly()` are further stale than when filed (now at lines 2124/688/1863,
  not the fragment's own 358/591/1012). One-off doc-drift finding, not an agent-facing lesson --
  filed as #155.
- **`trap.d/125.collect-ini-override-args-cannot-tell-empty-from-failed.md`** (still true, checked
  against HEAD by curate 2026-09-29) -- `RectorRunner::collectIniOverrideArgs()` (`src/RectorRunner.php:1237`)
  still does `\ini_get_all(null, true) ?: []`, collapsing "nothing to forward" and "enumeration
  failed" into the same empty result with no distinguishing signal. One-off finding about the code
  -- filed as #156.
- **`trap.d/125.d-flag-values-with-spaces-or-quotes-untested-on-windows.md`** (already fixed,
  checked against HEAD by curate 2026-09-29) -- the exact shape this fragment names as untested (a
  forwarded `-d` value containing reserved characters AND a literal quote, round-tripped through a
  real child process) is now pinned by
  `RectorRunnerStandbyWorkerTest::testAForwardedValueWithReservedCharactersAndAQuoteSurvivesARealChildProcess`,
  and confirmed OBSERVED passing on `windows-latest` (job #109621807229, run #36631565059,
  `tests (windows-latest, 8.3)`, 11/11 steps green) -- not merely reasoned, as the fragment left it.
  No rule needed.
- **`trap.d/127.getmypid-false-silent-orphan-skip.md`** (still true, checked against HEAD by curate
  2026-09-29) -- `boot()`'s `$daemonPid = \getmypid();` / `$daemonPid !== false ? $daemonPid :
  null` pattern (`src/RectorRunner.php:418-429`) is unchanged; a `false` return still silently
  disables #127's orphan-kill detection with no log line. One-off finding about the code -- filed
  as #157.
- **`trap.d/131.buffered-mode-progress-frames-silently-dropped.md`** (still true, checked against
  HEAD by curate 2026-09-29) -- `LspServer::runDueDiagnostics()` (`src/Lsp/LspServer.php:334-383`)
  is still declared `void` and still never returns its own local `$frames`, so the cold-boot
  progress frames it computes are silently discarded whenever no `frameWriter` is wired. One-off
  finding about the code -- filed as #158.
- **`trap.d/134.parentof-null-ambiguous-on-ps-failure.md`** (still true, checked against HEAD by
  curate 2026-09-29) -- `ProcessTree::parentOf()` (`src/Support/ProcessTree.php:159`) still folds
  "pid gone" and "`ps` unavailable" into the same `null`, and the #134 orphan watchdog's fallback
  (`bin/rector-warm-orphan-watchdog.php:61-64`) is still unconditionally `false` on POSIX, so a
  `ps`-less container still makes the watchdog silently inert. One-off finding about the code --
  filed as #159.
- **`trap.d/134.supertool-config-missing-again.md`** -- confirms
  `.claude/jit-context/tools/00-manual/worktree-missing-supertool-config.md`'s existing rule exactly
  (a fresh worktree has no `.supertool.json`); no new information, already covered by that rule. No
  new rule needed.
- **`trap.d/53.crlf-line-endings-not-preserved.md`** (still true, checked against HEAD by curate
  2026-09-29) -- `RectorDiffParser::hunkNewText()` (`src/Lsp/RectorDiffParser.php:295-302`) still
  hardcodes `implode("\n", ...)` on rejoin; #96's CRLF E2E cases only cover `didOpen`/diagnostics,
  never `codeAction`/apply, so this join is still never exercised against a real CRLF file. One-off
  finding about test coverage -- filed as #160.
- **`trap.d/73.windows-exitcode-first-read-uncertainty.md`** (already fixed, checked against HEAD by
  curate 2026-09-29) -- the fragment's own #97 follow-up said this becomes checkable once a
  `windows-latest` leg runs `testColdCallReportsRealExitCodeWithoutPcntl`; that leg (`tests
  (windows-latest, 8.3)`, job #109621807229, run #36631565059) is now OBSERVED green, 11/11 steps.
  No rule needed.
- **`trap.d/96.windows-rmdir-race-fixed.md`** and **`trap.d/97.windows-tests-leg-warning-exit1.md`**
  (already fixed, checked against HEAD by curate 2026-09-29) -- both fragments' own root cause,
  #112 (a leaked orphan php process on Windows holding a directory handle, making any rmdir-retry
  budget a structural dead end), is closed via #119 (`ProcessTree::killTree()`, confirmed in
  `discardProcWorker()`). The retry-widening saga both fragments document is superseded history, not
  an open lesson. No rule needed.
- **`trap.d/161.isalive-ambiguous-errno-treated-as-dead.md`** (still true, checked against HEAD by
  curate 2026-09-30) -- `TempCopySweeper::isAlive()` (`src/Lsp/TempCopySweeper.php`) still collapses
  any `posix_kill()` failure other than EPERM straight to `false` ("known dead"), contradicting its
  own three-way "true/false/null" contract for an unrecognised errno. One-off finding about the code,
  not an agent-facing lesson -- filed as #168.
- **`trap.d/165.fopen-x-does-not-refuse-a-dangling-symlink.md`** (still true, checked against HEAD by
  curate 2026-09-30) -- `fopen($tempPath, 'x')` in `RectorDiagnosticsSource.php` still does not refuse
  a DANGLING symlink (it follows the link and creates the target instead), so the neighbouring
  comment's "refuses unconditionally if anything ... already exists" claim is still overbroad for that
  one case. The residual TOCTOU window this leaves (a dangling symlink appearing after
  `isLinkOrJunction()` already ran) is the same family as, and already carried by, #145 -- no new
  issue filed. The `changelog.d/161.fixed.md` half of this fragment's own claim (that the wording was
  corrected in the #165 commit) is confirmed: `CHANGELOG.md`'s `[0.7.0]` `#161` entry now names this
  exact residual gap by this fragment's own filename. One-off finding about the code, not an
  agent-facing lesson.
- **`trap.d/165.isreparsepoint-fail-closed-scope-unclear.md`** (still true, checked against HEAD by
  curate 2026-09-30) -- `TempCopySweeper::isReparsePoint()` (`src/Lsp/TempCopySweeper.php`) still
  reads any `fsutil reparsepoint query` exit other than a `proc_open()` spawn failure as "not a
  junction" via `proc_close($process) === 0`, a fail-open reading of an unconfirmed answer rather than
  the fail-closed direction #142/#144/#161/#162 established. Reasoned, not observed -- no Windows host
  available to confirm whether `fsutil` ever exits non-zero for a reason other than "genuinely not a
  junction". One-off finding about the code, not an agent-facing lesson -- filed as #169.
- **`trap.d/165.stderr-fallback-unreachable-in-forked-grandchild.md`** (still true, checked against
  HEAD by curate 2026-09-30) -- the class-level docblock above `RectorRunner::applySkipsOfOriginalPath()`
  still claims a fail-open "says so on stderr" unconditionally, which is only true on the non-forked
  path; the catch block itself already guards the `fwrite()` correctly and the inline comment beside
  it already documents the forked-grandchild case accurately. One-off doc-accuracy finding, not an
  agent-facing lesson -- filed as #170.
- **`trap.d/168.processtree-isalive-no-null.md`** (still true, checked against HEAD by curate
  2026-10-01) -- `ProcessTree::isAlive()` (`src/Support/ProcessTree.php:165`) still returns a
  bool-only `@\posix_kill($pid, 0) || \posix_get_last_error() === 1`, which can never produce the
  null its own three-way docblock contract promises. One-off finding about the code, not an
  agent-facing lesson -- filed as #235.
- **`trap.d/179.docs-lsp-startup-sweep-stale.md`** (already fixed, checked against HEAD by curate
  2026-10-01) -- `docs/lsp.md:66-78` now correctly describes the lock-based ownership model (no
  startup walk; a 0.7.1-or-earlier leftover is not swept automatically), and the stale "startup
  sweep" comments in `src/Lsp/RectorDiagnosticsSource.php` (around lines 226 and 277) now
  correctly describe the per-directory check and name #179 as the commit that dropped the
  startup walk. Fixed by #188 / PR #202 (commit `a0fb20dcc99d`), which explicitly named this
  fragment in its own fix list. No rule needed.
- **`trap.d/179.lock-failure-leaves-orphaned-tempdir.md`** (already fixed, checked against HEAD
  by curate 2026-10-01) -- `src/Lsp/RectorDiagnosticsSource.php`'s lock-failure branch (around
  lines 353-372) now unlinks `.lock` before `rmdir()`, exactly the fix this fragment named as
  missing, and `TempCopySweeper::reclaimStaleOwnLock()` (referenced at the `is_dir($tempDirectory)`
  guard) now reclaims a directory left holding only an empty, unlockable `.lock`. Fixed by #188 /
  PR #202 (commit `a0fb20dcc99d`). No rule needed.
- **`trap.d/185.phpstan-ships-as-phar.md`** (confirmed accurate against HEAD, checked by curate
  2026-10-01) -- not a defect report: it describes how `Warm\SessionHooks::installParserHook()`
  and `Warm\TrackingParser` hook PHPStan's live `pathRoutingParser` service rather than patching
  the phar-bundled `CachedParser`. That exact design is already documented in full in
  `SessionHooks.php`'s own class docblock and `TrackingParser.php`'s own docblock. No rule
  needed -- the lesson already lives next to the code it describes.
- **`trap.d/185.tracker-null-signature-collapse.md`** (still true, checked against HEAD by curate
  2026-10-01) -- `DependencyFileTracker::sig()` (`src/Warm/DependencyFileTracker.php`) still
  returns null both for a genuine stat() failure and for a path that was already unstat-able at
  `record()` time, and `checkStale()`'s plain `!==` comparison still cannot tell the two apart.
  One-off finding about the code -- filed as #236.
- **`trap.d/189.directorysnapshot-silent-unreadable-dir.md`** (still true, checked against HEAD
  by curate 2026-10-01) -- `DirectorySnapshot::walk()`/`scan()`
  (`src/Warm/DirectorySnapshot.php:154-157`, `:186-189`) still return silently, recording
  nothing, when a directory cannot be stat()'d or listed. One-off finding about the code, not an
  agent-facing lesson -- filed as #237.
- **`trap.d/189.session-handshake-timeout-no-fallback.md`** (still true, checked against HEAD by
  curate 2026-10-01) -- `RectorRunner::spawnSession()`'s handshake read (around lines 1298-1307)
  still throws the generic "the analysis was killed" message and never calls `disableSession()`
  when the deadline elapses during session-child startup, so the fork fallback #189 documents
  elsewhere does not run on this path. One-off finding about the code -- filed as #238.
- **`trap.d/189.tracker-record-never-passes-toctou-hash.md`** (still true, checked against HEAD
  by curate 2026-10-01) -- `WarmSession::recordRead()` (`src/Warm/WarmSession.php:101-106`) is
  still the only production caller of `DependencyFileTracker::record()`, and it still always
  passes `null` for `$readSha`, leaving the TOCTOU guard permanently dark for
  `afterInSessionCall()`'s post-call recording. One-off finding about the code -- filed as #239.
- **`trap.d/195.no-e2e-fixture-for-project-owns-rector.md`** (still true, checked against HEAD by
  curate 2026-10-01) -- no fixture under `tests/Fixtures` ships a real `vendor/rector/rector`
  directory, so the #195 `projectShipsOwnRector()`/`ensureProjectAutoloaded()` guard is still
  exercised only via `ReflectionMethod` unit tests, never through the real
  `RectorTool -> RectorRunner::run()` call path. One-off finding about test coverage -- filed as
  #240.
- **`trap.d/216.codeaction-recompute-error-shape.md`** (still true, checked against HEAD by
  curate 2026-10-01) -- `LspServer::codeAction()`'s #216 recompute (around lines 1077-1086)
  still reads only `$fresh['fixes']` and drops `$fresh['errors']` on the floor, so a genuine
  Rector failure on the forced recompute still reports identically to "nothing to fix here".
  One-off finding about the code -- filed as #241.
- **`trap.d/225.scratch-probe-needs-paste-not-heredoc.md`** (confirmed accurate against HEAD,
  checked by curate 2026-10-01) -- both halves are already covered by existing rules rather than
  needing a new one. The `cat >`/`python3 - <<EOF` refusal
  (`.claude/jit-context/tools/01-oss/python-heredoc-writes-are-unvalidated.md`, owned layer, not
  editable here) matches on the command shape alone, with no exception for a path outside the
  project tree, so it already fires on a `/tmp` scratch probe exactly as this fragment found by
  trying it -- the fragment's complaint is about the rule's own framing reading as project-only,
  which this pass cannot fix (owned layer). The TOML triple-quoted-literal backslash gotcha is
  already stated in
  `.claude/jit-context/tools/00-manual/supertool-write-op-payload-pitfalls.md`. No new rule
  needed.
