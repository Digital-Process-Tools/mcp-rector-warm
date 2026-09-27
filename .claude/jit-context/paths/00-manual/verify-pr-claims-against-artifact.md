---
title: "A PR/self-review claim about what a CI leg runs or a vendor class exposes: read the artifact, never trust the prose"
match: "(\.github/workflows/tests\.yml|vendor/rector/rector)"
mode: once
---

Two confirmed cases of a PR body's own "confirmed against ..." or "runs X end to end" claim being
wrong, caught only because a reviewer re-read the actual file instead of trusting the sentence:

- **#8 (PR #17).** Body claimed `$container->tagged(...)` didn't exist on "Rector's real container
  (entropy/entropy)" and cited `AbstractRectorTestCase.php` as confirming `findByContract()`
  instead. Neither is true against this repo's installed vendor: `entropy/entropy` isn't even
  installed, `RectorConfig extends` Illuminate's `Container`, which defines `tagged()` and has no
  `findByContract()` at all -- `AbstractRectorTestCase::setUp()` itself calls `->tagged(...)`. The
  PR's "fix" swapped the two, making the guard always fail against the real container. Confirmed
  only by reading the four vendor files directly (`grep`/`read`), not the PR's own citation. The
  two unit tests added for it used hand-rolled fake containers, so they passed either way -- a
  test built on a fake object is not evidence about a real third-party class's shape.
- **#18 (PR #35).** Body and the workflow's own comment described the new `no-pcntl` CI job as
  running "the real `boot()`/`reboot()`/`execute()` sequence ... end to end over a real
  subprocess." The job itself runs only `--testsuite unit,integration` -- it never runs the E2E
  Python suite; that has its own `e2e` job with no `ini-values` override, so it always runs WITH
  pcntl. Confirmed by reading the workflow file's literal `--testsuite` argument, not the prose
  around it.

**Before trusting either kind of claim** (a vendor class's method set, or which CI job/testsuite
line actually covers a code path): read the cited file or the workflow's own command line
directly. A citation to a vendor path or a job name is not evidence until you have read it
yourself.
