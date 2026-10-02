---
title: "ProcessTree::killTree() SIGSTOPs $rootPid FIRST, before $excludePids is consulted -- never pass your own pid or an ancestor"
match: "(src/Support/ProcessTree\.php|tests/Unit/ProcessTreeTest\.php|tests/Unit/RectorRunnerProcessTreeKillTest\.php)"
mode: once
---

**#245.** `killTree($rootPid, $excludePids)`'s POSIX path sends `SIGSTOP` to `$rootPid` as its
very first action, before `$excludePids` or anything else is read. Calling it with the CURRENT
process's own pid (e.g. `getmypid()`), or any ancestor of the calling process, SIGSTOPs the
caller immediately -- it never resumes itself, because the code that would resume it never runs
once it is stopped.

**Paid for once already:** an early test draft called `killTree(getmypid(), [...])` meaning to
target a grandchild but passing the wrong pid as root. It froze a live SHARED `phpunit-mcp`
validator daemon worker in state T, recovered manually with `kill -CONT <pid>` across several
pids.

**Rule for any new killTree() test or caller:** always target a disposable child spawned via
`proc_open()`. Never `getmypid()`, never an ancestor of the calling process.

**Related, still-open edge case in the function itself (documented, not fixed):** `$excludePids`
is meant to protect a caller that is itself $rootPid's own descendant (`bin/rector-warm-orphan-
watchdog.php`'s own use), but if `$rootPid` itself is ever passed inside its own `$excludePids`,
the unconditional `SIGSTOP`-first ordering still stops it, and the final `KILL` pass explicitly
excludes it too (`array_diff(..., $excludePids)`) -- nothing later ever sends it `SIGCONT`, so it
stays frozen in state T forever. No current caller does this, so it is not a live defect today;
treat it as a constraint when adding a new caller, not as safe to rely on.
