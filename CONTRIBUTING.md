# Contributing

Thanks for the interest. This project is small and intentionally focused.

## Reporting issues

Open a GitHub issue with:

- Rector version (`composer show rector/rector`)
- PHP version (`php -v`)
- MCP client (Claude Desktop, Cline, ...)
- Repro: minimal `rector.php` + the failing command

## Pull requests

1. Fork, branch from `main`.
2. Add a test for the change (`tests/Unit` for logic, `tests/Integration` for stdio behavior,
   `tests/E2E` for what an MCP host sees -- see [End-to-end suite](#end-to-end-suite-a-real-mcp-client)).
3. Run the suites:
   ```bash
   ./vendor/bin/phpunit --no-coverage
   tests/E2E/run.sh
   ```
4. Open the PR with a one-paragraph summary of the change.

## What we'll merge

- Bug fixes with a regression test.
- Rector version compatibility shims.
- New MCP tools (e.g. `rector_list_rules`) that have a clear use case from an MCP client.
- Doc / README improvements.

## What we won't merge

- Features that re-enable parallel mode without a clean solution for the worker-spawn problem (it would break the warm guarantee).
- Wrappers that just shell out to `vendor/bin/rector` — defeats the whole purpose.

## Warm-state internals

Things that cost time to rediscover. Read before touching `RectorRunner`.

- **Every warm call runs isolated in a forked child.** `run()` forks (`pcntl_fork`) after the container is booted, so the analysis in each call mutates only the forked child's copy-on-write memory; the parent's container is never touched by any call and stays exactly as it was right after `boot()`. This is what makes a class edited on disk between calls visible on the very next call — including changes PHPStan's own class reflection would otherwise cache for the whole process (#8).
- **No pcntl (e.g. Windows): the fallback is a full `reboot()` before every warm call.** Slower — the container rebuild cost is paid on every call instead of once — but correct, because a fresh container is the only complete reset available without forking.
- **There is no per-call reset of `ResettableInterface` services any more.** The old `resetReflectionState()` (claude-supertool#273) was removed with #8: with the fork, the parent's container never analyses anything, so there is nothing to reset, and the no-pcntl path reboots anyway. It had also never run: it guarded on `tagged()`, which Rector's container (`Rector\Config\RectorConfig`, an entropy `Container`) does not have; it has `findByContract()`. If a per-call reset is ever reintroduced, call `findByContract(ResettableInterface::class)` as `AbstractRectorTestCase` does, and remember it never touched PHPStan's reflection/scope caches.
- **Warm-state bugs only reproduce through the subprocess.** Rector bypasses its source-locator cache when `isPHPUnitRun()` is true, so an in-process test cannot see them. Staleness tests belong in `tests/Integration/ServerStdioTest.php`, which spawns the real bin.
- **Staleness test recipe.** Write the files, call, edit a *different* file, `touch()` it past the 1s mtime granularity, call the first file again, assert the diff follows the edit. Same-file edits are already covered by the AST re-parse and prove nothing about reflection.
- **Cross-file type fixtures need `withAutoloadPaths()`.** A rule that reads a type from another file (e.g. `ReturnTypeFromStrictTypedCallRector`) silently does nothing unless the fixture's `rector.php` lists `src` under `withAutoloadPaths()` as well as `withPaths()`.
- **`--config` resolution.** `RectorConfigsResolver` reads `--config` from the server's own `$_SERVER['argv']`, not from anything the runner passes. Pinned by `testNonDefaultConfigNameIsHonoured`.

## Local development

```bash
git clone https://github.com/Digital-Process-Tools/mcp-rector-warm.git
cd mcp-rector-warm
composer install
./vendor/bin/phpunit --no-coverage
```

Smoke test the binary against the fixture project:

```bash
bin/mcp-rector-warm --working-dir=tests/Fixtures/project --config=tests/Fixtures/project/rector.php
# (then paste MCP JSON-RPC on stdin)
```

## End-to-end suite (a real MCP client)

`tests/E2E/` drives `bin/mcp-rector-warm` with the official Python MCP SDK
(`mcp.ClientSession` over `stdio_client`), the way an MCP host does: spawn over stdio,
`initialize`, `tools/list`, `tools/call`, close. It shares no code with the PHP SDK the
server is built on, so a protocol bug cannot hide behind the same bug on both sides.

```bash
composer install            # once
tests/E2E/run.sh            # creates tests/E2E/.venv from the pinned requirements.txt
tests/E2E/run.sh -k parent  # extra arguments go to pytest
```

Needs Python 3.10+ and PHP. Set `PHP_BINARY=/path/to/php` when the `php` on your `PATH`
is not the one to test with. CI runs the same script in the `e2e` job of
`.github/workflows/tests.yml`.

- `test_mcp_client.py` -- handshake (protocol version, server info), `tools/list`
  (schema), dry run with a diff, apply, a warm second call, a refused call, clean exit.
- `test_scenarios.py` -- one test per file in `tests/E2E/scenarios/` (below).
- Every server runs behind `stdio_tap.py`, which keeps a byte copy of stdout, so each
  test also asserts that nothing but JSON-RPC reached stdout and that the server exited 0
  when the client closed.

A known defect is a strict `xfail` naming its issue: the suite is green while the defect
stands, and turns red (XPASS) the day the fix lands, so the marker goes with the fix.

### Adding a scenario

A scenario checks that the warm server reacts to code changes the way a fresh Rector
would. Drop a YAML file in `tests/E2E/scenarios/`; its file name becomes the test id.
It describes a small codebase and a list of steps run in ONE warm session. After every
`call`, the harness runs a cold `vendor/bin/rector process` on an identical copy of the
tree and asserts the two results match (exit code, changed files, diffs, errors, and the
written files when `dry_run: false`). You do not write the expected diff. This example is
`tests/E2E/scenarios/readme-example.yaml`:

```yaml
description: Editing a file between two calls is seen by the warm container.
files:
  src/Probe.php: |
    <?php
    final class Probe
    {
        public function __construct(private int $value) {}
    }
steps:
  - call: src/Probe.php
  - edit:
      path: src/Probe.php
      old: "private int"
      new: "private readonly int"
  - call: src/Probe.php
    expect: {changed: 1}   # optional: pin a fact on top of the warm == cold check
```

Steps: `write` (path, content), `edit` (path, old, new; `old` must occur once), `delete`
(path), `rename` (from, to), `call` (a path relative to the tree, a file or a directory;
`dry_run: false` to apply). `expect` takes `changed`, `changed_files`, `diff_contains`,
`diff_excludes`, `is_error`, `error_class`. At the top level, `config` replaces the
default `rector.php` (`null` for none), `fixture: tests/Fixtures/project` starts from a
directory, `xfail: "#N: ..."` marks a scenario that hits a filed issue, and
`oracle: false` skips the cold-`rector process` comparison for every in-tree call --
only for a fix whose whole point is that warm deliberately no longer matches what a
cold, unpatched Rector does on the same tree (e.g. #14: a missing config's own default
behaviour is exactly the bug). `php_ini: {default_socket_timeout: 3}` passes
`php -d key=value` to the server process only (the cold oracle keeps PHP defaults), so a
limit that takes a minute at its default can be reproduced in seconds (e.g. #32). The
full format is in the docstring of
`tests/E2E/test_scenarios.py`; unknown keys fail loudly.

## Checking against a real project

The scenarios use small synthetic trees. `tools/warm-vs-cold.py` asks the same question
of a real codebase: on a seeded sample of files, does one warm session return what a
fresh `rector process` returns for each file?

```bash
pip install -r tests/E2E/requirements.txt          # the official MCP client
tools/warm-vs-cold.py --project ../app --project-autoload ../app/vendor/autoload.php \
    --files 'src/**/*.php' --files 'tests/**/*Test.php' --limit 300 --seed 1 \
    --jobs 4 --php "$(command -v php)" --out /tmp/wvc
```

- Every call is a dry run on both sides. A generated wrapper config loads the project's
  `rector.php` once and sends Rector's cache to `--out`, so the project's cache is never
  written and cannot hide a difference.
- `--project-autoload` runs this checkout's `bin/` and `src/` on top of the project's own
  Composer autoloader, the way a project with the package in `require-dev` runs it.
  Without it, the checkout's own `vendor/` is used.
- If the project's `rector.php` writes anything when it loads (clears a cache, creates a
  directory), run against a copy of the project, not the original.
- The report is `report.md` (plus `report.json`): matches, mismatches with both diffs and
  the rules each side applied, warm and cold errors, and per-call p50/p95 timings. The
  exit code is 1 on any mismatch and 2 when only errors were found.
- A mismatch is a finding. Reduce it to one rule and one cross-file dependency, check it
  against the open issues, and file it with an xfail scenario (see above).
