---
title: "Raw php-code-coverage lineCoverage() values are arrays of ids, not hit counts"
match: "tests/coverage/"
mode: remind
---

`CodeCoverage::getData()->lineCoverage()` returns, per file, an array keyed by line number
where each **value is an ARRAY** of test/process-id strings that executed that line -- never
an integer count. An unexecuted-but-coverable line has value `array(0) {}` (empty array), not
`0` and not `null`.

**`$v > 0` to mean "was this line hit" is wrong, silently, in the dangerous direction**: PHP
compares an array as always greater than a non-array int (`[] > 0` is `true`). So
`array_filter($lines, fn($v) => $v !== null && $v > 0)` counts EVERY coverable line as "hit",
including ones that never ran.

Correct check: `$v !== null && count($v) > 0`.

Cost one lane ~40 minutes chasing a phantom "phpcov merge silently drops coverage" bug: raw
`.cov` files all appeared fully covered (the `$v > 0` bug), while the merged Clover XML
correctly showed 0% (Clover reads the real `count=` attribute, an actual integer) -- the two
were taken as contradictory when the Clover number was right all along.

Confirmed on phpunit/php-code-coverage 10.1.x via `fromXdebugWithoutPathCoverage()`; not
confirmed whether this is driver-specific or general across `RawCodeCoverageData`/
`ProcessedCodeCoverageData`.
