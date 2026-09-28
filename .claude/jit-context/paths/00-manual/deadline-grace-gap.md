---
title: "src/RectorRunner.php: an inner/outer deadline pair needs a grace gap sized to the cleanup step's real worst case, not a guess"
match: "src/RectorRunner.php"
mode: once
---

**#58.** `forkAndExecute()`'s wait loop (worker waiting on its own forked grandchild) and
`runForked()`'s read on the worker<->daemon socket both time out on `$callTimeoutSeconds`. The
intent: the worker-side (inner) deadline fires first and cleans up; the daemon-side (outer) one is
only a backstop for a genuinely unresponsive worker. With both set to the identical value, a 1s
unit-test deadline killed the worker itself as collateral -- SIGKILL + `pcntl_waitpid` + one
`writeFrame()` back to the daemon is not free, and none of that overhead happens before the
worker's own deadline is already reached, so a same-instant outer deadline can trip concurrently
with, not only after, the inner one.

**Fix in place:** `callDeadlineNs(int $graceSeconds = 0)` -- `runForked()` calls it with
`self::RUN_FORKED_DEADLINE_GRACE_SECONDS` (5s), `forkAndExecute()` with none.

**The 5s is a guess, not a derived bound, and it has already flaked (#81).** It was tuned against
one green run of a 1s-deadline unit test, not against load. Under the conditions that make a call
look wedged in the first place (the host under CPU load, swapping, near OOM), the cleanup step's
own cost is more likely to exceed 5s, not less -- the exact case the grace period exists to rule
out. `RectorRunnerTest::testWedgedCallIsKilledAtTheDeadlineAndTheWorkerStaysUsable` already flips
which kill message it asserts (worker-side vs. daemon-side) under ordinary CPU contention from
running `tests/Unit/` back-to-back, confirmed by re-running the suite several times (the same test
alone never flakes) -- see #81.

**#81's own fix took the test-assertion route, not the grace-value route.** Both wedge tests now
assert the message substring common to BOTH kill sites ("rector call exceeded" / "--call-timeout")
instead of pinning which one fired, plus a new deterministic regression test
(`testReadExactlyOuterBackstopMessageSharesPatternButNotFigureWithInnerKillSite`) that reaches the
outer kill site directly via Reflection on an already-expired deadline, independent of contention.
**This closes the flake, not the underlying gap**: the 5s value is still a guess, not a derived
bound, and the paragraph above still applies in full to anyone touching either deadline.

**Before touching either deadline in this file:** any change to `$callTimeoutSeconds`,
`RUN_FORKED_DEADLINE_GRACE_SECONDS`, or either loop's timing needs the grace gap re-examined
against the cleanup step's real worst case, not just the happy path.

**#87 found the flake #81/#86 closed was only the message half of it.** The outer/daemon-side
backstop firing doesn't just report a different message -- `runForked()`'s own catch block
(`RectorRunner.php`) also `killAndReap($this->workerPid)`s and `forgetDeadWorker()`s the WHOLE
worker, not only the wedged grandchild the inner site kills, so `isWarm()` goes false. The two
wedge tests used to assert `isWarm()` true unconditionally after a kill regardless of which site
fired, and one real run under CPU contention hit the outer site and flaked on exactly that.
`RectorRunnerTest::testRunForkedOuterBackstopKillsTheWholeWorkerNotOnlyTheGrandchild` now pins
this deterministically (a real booted worker, daemon-side socket swapped for a loopback nobody
answers, so the outer backstop is guaranteed rather than merely likely to fire), and the two
wedge tests branch their `isWarm()` assertion on which kill site's message came back. This still
does not touch the 5s value itself -- it is still a guess, not a derived bound, and the paragraph
above still applies in full to anyone touching either deadline.
