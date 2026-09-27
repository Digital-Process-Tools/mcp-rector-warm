---
title: "tests/E2E: a real MCP client plus a warm-vs-cold oracle; add a case as one YAML file"
match: "tests/E2E/"
mode: once
---

The official Python `mcp` SDK (pinned in `requirements.txt`) drives `bin/mcp-rector-warm` over stdio.
`stdio_tap.py` passes bytes through and keeps a raw copy of stdout, so "only JSON-RPC on stdout" is asserted at byte level.

**Run:**

| want | command |
| --- | --- |
| whole suite | `tests/E2E/run.sh` |
| one scenario | `tests/E2E/run.sh -k two-files` (extra args go to pytest) |
| prove an xfail fails for its stated reason | `tests/E2E/run.sh --runxfail -k <name>` |
| `php` on PATH is an alias or the wrong version | `PHP_BINARY=/path/to/php tests/E2E/run.sh` |

- `composer install` must already have run at the repo root; otherwise `run.sh` exits 2.
- Without `E2E_PYTHON`, `run.sh` makes `tests/E2E/.venv` and reinstalls only when `requirements.txt` changes. CI sets `E2E_PYTHON=python`.

**Files:**
- `test_mcp_client.py`: handshake, `tools/list`, dry run, apply, refused path, clean close.
- `test_scenarios.py`: one test per `scenarios/*.yaml`, named after the file.

**The scenario oracle:** after every `call` step in the warm session, a cold `vendor/bin/rector process` runs on an identical copy of the tree, and the changed files and diffs must match. A new case needs no hand-written expected diff; `expect` only pins extra facts.

**Scenario keys** (validated in `test_scenarios.py`: `TOP_KEYS`, `STEP_KINDS`, `CALL_KEYS`, `EXPECT_KEYS`; an unknown key is an error, not ignored):
- top level: `description`, `xfail`, `fixture` (a repo-relative dir copied in first), `config` (`rector.php` source; `null` means no config; omitted means the fixture's own config, else `DEFAULT_CONFIG`), `oracle` (false skips the cold comparison), `php_ini` (a mapping passed as `php -d key=value` to the server only, never the cold oracle; e.g. `default_socket_timeout: 3` makes #32 reproduce in seconds), `files`, `steps`.
- a server that dies mid-call (e.g. #31) surfaces as `MCPError: Connection closed` right away: `stdio_tap.py` closes the client's stdout when the server's closes, rather than leaving the call to hit `E2E_CALL_TIMEOUT`.
- each step has exactly one of `write` {path, content}, `edit` {path, old, new}, `delete` (path), `rename` {from, to}, `call` (path).
- a `call` step may add `dry_run` (defaults to true) and `expect`: `changed`, `changed_files`, `diff_contains`, `diff_excludes`, `is_error`, `error_class`.
- at least one `call` step is required.
- the minimal example is `scenarios/readme-example.yaml`, which mirrors CONTRIBUTING.md "Adding a scenario". Change both together.

**A known defect is a strict xfail naming its issue**: `xfail: "#19: ..."` in YAML, or `@pytest.mark.xfail(strict=True, reason="#16: ...")` in `test_mcp_client.py`.
- `pytest.ini` sets `xfail_strict = true`, so when a fix lands the test XPASSes and **fails**. The fix PR must delete that marker. `grep -rn '#<issue>' tests/E2E` finds every one.
- An xfail with no issue number is not allowed. File the issue first.
- A new warm-vs-cold divergence is a real finding: file an issue, then add the scenario with its xfail.
