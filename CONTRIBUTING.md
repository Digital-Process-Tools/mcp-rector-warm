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
directory, and `xfail: "#N: ..."` marks a scenario that hits a filed issue. The full
format is in the docstring of `tests/E2E/test_scenarios.py`; unknown keys fail loudly.
