---
title: "PHPUnit 10+: a test's own catch (\RuntimeException) can swallow its own self::fail()"
match: "tests/"
mode: once
---

**#230/#248.** `PHPUnit\Framework\AssertionFailedError` (what `self::fail()` and every
`assert*()` failure throw) extends `\RuntimeException` in PHPUnit 10+. A test shaped like:

```php
try {
    doSomething();
    self::fail('expected X to happen');
} catch (\RuntimeException $e) {
    self::assertStringContainsString('expected message', $e->getMessage());
}
```

catches its OWN `self::fail()` on the branch where `doSomething()` returned normally instead of
throwing -- the `assertStringContainsString` then passes against `self::fail()`'s own message
text, not against a real failure. The test reports green, or fails with a message that reads like
a genuine mismatch, either way hiding that nothing was ever thrown.

**Real cost paid for this once already:** masked the real #230 CI regression for a full
investigation round (PR #243) -- the failure read as "kill message not found" rather than
"self::fail() caught itself", and the first fix attempt (pcov.exclude) shipped and did nothing
before a second, deeper investigation found the true cause.

**Fix:** assert `!($e instanceof \PHPUnit\Framework\AssertionFailedError)` before trusting the
catch, or restructure so `self::fail()` cannot land in the same `catch` -- set a flag before the
`try` and assert it after, rather than relying on catch+assert. Give a negative "must not fire"
assertion a positive "must fire" control case too (CLAUDE.md's own rule) -- that pairing would
have caught this shape directly.

**Known still-open instance:** `tests/Unit/RectorRunnerProcessTreeKillTest.php`'s four
deadline/kill tests still use this exact shape (#248, closed by the maintainer as not-planned --
no real LSP/MCP user impact, test-harness-only). Not a reason to repeat it in a NEW test.
