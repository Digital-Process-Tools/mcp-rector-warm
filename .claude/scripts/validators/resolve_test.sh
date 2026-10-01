#!/usr/bin/env bash
# Resolver for the `phpunit` validator and the `newTest` advice rule (#231).
#
# Contract (supertool docs/validators.md, "resolve - map a source file to
# its real target" / "The resolve contract"):
#   - called from the validator path:  {file} -> run the test, or skip
#       target exists -> print it to stdout, exit 0
#       no target     -> print nothing to stdout, exit 3 (validator skips)
#   - called from the advice path (resolveFromValidator reuses this cmd):
#       no target     -> print the would-be target to STDERR, exit 3
#         (stdout stays empty either way, so the validator path is identical;
#         only the advice rule reads stderr)
#
# Mapping is a plain, exact filename match -- never a glob:
#   src/X.php          -> tests/Unit/XTest.php
#   src/Sub/X.php       -> tests/Unit/Sub/XTest.php
#   tests/**/*Test.php  -> itself (editing a test runs that test)
#
# Why exact-name only, never `<Name>*Test.php`: several src files here have
# more than one *Test.php exercising them. src/RectorRunner.php alone has
# six -- RectorRunnerTest.php, RectorRunnerSessionTest.php,
# RectorRunnerSkipAsTest.php, RectorRunnerStandbyWorkerTest.php,
# RectorRunnerProcessTreeKillTest.php, RectorRunnerBootstrapRaceTest.php --
# and most of those are the fork/kill-deadline suites, the slowest in the
# repo. Globbing them all in on every edit of RectorRunner.php measured at
# ~87s total (JUnit log, 2026-10-01), well past the ~30-60s per-edit budget
# the validator's own timeout enforces. The exact-name mapping instead picks
# only RectorRunnerTest.php (~32s for 34 tests on the same run, the slowest
# single direct match in the repo) and leaves the other five to the normal
# CI phpunit run. src/RectorTool.php has the same shape the other way:
# tests/Unit/RectorToolTest.php does not exist at all (only
# RectorToolArgvTest.php, RectorToolCallTimeoutSafetyTest.php,
# RectorToolContainmentTest.php, RectorToolRecoveryTest.php do), so an edit
# to RectorTool.php correctly resolves to "no target" rather than guessing
# which of the four to run.
set -euo pipefail

file="${1:-}"
if [ -z "$file" ]; then
  exit 3
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$repo_root"

case "$file" in
  tests/*Test.php)
    # Already a test file: editing it runs it.
    printf '%s' "$file"
    exit 0
    ;;
  src/*.php)
    rel="${file#src/}"
    dir="$(dirname "$rel")"
    base="$(basename "$rel" .php)"
    if [ "$dir" = "." ]; then
      target="tests/Unit/${base}Test.php"
    else
      target="tests/Unit/${dir}/${base}Test.php"
    fi
    if [ -f "$target" ]; then
      printf '%s' "$target"
      exit 0
    fi
    >&2 printf '%s' "$target"
    exit 3
    ;;
esac

exit 3
