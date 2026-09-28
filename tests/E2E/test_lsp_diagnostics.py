"""E2E for #53 v1: diagnostics on didOpen/didSave, codeAction -> WorkspaceEdit,
and didClose clearing them -- over a real subprocess and real stdio, same
hand-rolled client as test_lsp_initialize.py (see its module docstring for why
this does not use mcp_harness.py or the mcp SDK).

The warm-vs-cold oracle applies here too (CLAUDE.md, e2e-harness.md): applying
the LSP's own WorkspaceEdit must produce the exact bytes a cold
`vendor/bin/rector process` (non-dry-run) produces on the same original file.
"""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import time
from pathlib import Path

import pytest

from mcp_harness import process_descendants
from test_lsp_initialize import BIN, frame, php_binary, read_frame

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
FIXTURE = REPO / "tests" / "Fixtures" / "lsp-project"
INSERT_ONLY_FIXTURE = REPO / "tests" / "Fixtures" / "lsp-insert-only-project"


def copy_fixture(dest: Path, source: Path = FIXTURE) -> None:
    shutil.copytree(source, dest)


def apply_edit(text: str, edit: dict) -> str:
    """Apply one LSP TextEdit {range, newText} to a string, line-based
    (every range this server builds has character 0 at both ends)."""
    lines = text.splitlines(keepends=True)
    start = edit["range"]["start"]["line"]
    end = edit["range"]["end"]["line"]
    return "".join(lines[:start]) + edit["newText"] + "".join(lines[end:])


@pytest.fixture
def project(tmp_path):
    dest = tmp_path / "project"
    copy_fixture(dest)
    return dest


@pytest.fixture
def server(project):
    proc = subprocess.Popen(
        [php_binary(), str(BIN), f"--working-dir={project}"],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    proc.stdin.write(frame({
        "jsonrpc": "2.0", "id": 1, "method": "initialize",
        "params": {"processId": None, "rootUri": None, "capabilities": {}},
    }))
    proc.stdin.flush()
    read_frame(proc.stdout)
    try:
        yield proc
    finally:
        if proc.poll() is None:
            proc.kill()
        proc.wait(timeout=10)


def start_server(project: Path, capabilities: dict):
    """A server past `initialize` AND `initialized`, the way a real client
    drives it -- the `server` fixture above stops after `initialize`, so it
    never sees what `initialized` sends (#105)."""
    proc = subprocess.Popen(
        [php_binary(), str(BIN), f"--working-dir={project}"],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    proc.stdin.write(frame({
        "jsonrpc": "2.0", "id": 1, "method": "initialize",
        "params": {"processId": None, "rootUri": None, "capabilities": capabilities},
    }))
    proc.stdin.write(frame({"jsonrpc": "2.0", "method": "initialized", "params": {}}))
    proc.stdin.flush()
    read_frame(proc.stdout)
    return proc


def stop_server(proc) -> None:
    if proc.poll() is None:
        proc.kill()
    proc.wait(timeout=10)


def did_open(server, uri: str, version: int = 1):
    server.stdin.write(frame({
        "jsonrpc": "2.0",
        "method": "textDocument/didOpen",
        "params": {"textDocument": {"uri": uri, "languageId": "php", "version": version, "text": ""}},
    }))
    server.stdin.flush()
    return read_frame(server.stdout)


def did_save(server, uri: str, version: int = 2):
    # Disk-based sync (LspServer's own class docblock): `save.includeText` is
    # false in the capabilities this server advertises, so no `text` field is
    # sent -- the server re-reads the file from disk, same as didOpen.
    server.stdin.write(frame({
        "jsonrpc": "2.0",
        "method": "textDocument/didSave",
        "params": {"textDocument": {"uri": uri, "version": version}},
    }))
    server.stdin.flush()
    return read_frame(server.stdout)


def test_did_open_on_a_fixable_file_publishes_a_diagnostic(server, project):
    uri = (project / "src" / "Fixable.php").as_uri()

    notification = did_open(server, uri)

    assert notification["method"] == "textDocument/publishDiagnostics"
    assert notification["params"]["uri"] == uri
    diagnostics = notification["params"]["diagnostics"]
    assert len(diagnostics) == 1
    assert diagnostics[0]["source"] == "rector"
    assert "SimplifyIfReturnBoolRector" in diagnostics[0]["message"]


def test_did_open_on_an_unchanged_file_publishes_empty_diagnostics(server, project):
    # Negative control paired with the test above: a file Rector does not
    # touch must publish an EMPTY diagnostics list, not skip publishing.
    uri = (project / "src" / "Clean.php").as_uri()

    notification = did_open(server, uri)

    assert notification["params"]["diagnostics"] == []


def test_code_action_edit_matches_a_cold_rector_apply(server, project):
    path = project / "src" / "Fixable.php"
    uri = path.as_uri()
    original = path.read_bytes().decode("utf-8")

    notification = did_open(server, uri)
    diagnostic = notification["params"]["diagnostics"][0]

    server.stdin.write(frame({
        "jsonrpc": "2.0",
        "id": 2,
        "method": "textDocument/codeAction",
        "params": {
            "textDocument": {"uri": uri},
            "range": diagnostic["range"],
            "context": {"diagnostics": [diagnostic]},
        },
    }))
    server.stdin.flush()
    response = read_frame(server.stdout)
    actions = response["result"]
    assert any(a["title"].startswith("Apply Rector:") for a in actions)
    assert any(a["title"] == "Apply all Rector fixes" for a in actions)

    quickfix = next(a for a in actions if a["title"].startswith("Apply Rector:"))
    edit = quickfix["edit"]["changes"][uri][0]
    warm_applied = apply_edit(original, edit)

    # Cold oracle: apply Rector for real on a fresh copy of the ORIGINAL file.
    cold_copy = project.parent / "cold"
    copy_fixture(cold_copy)
    done = subprocess.run(
        [php_binary(), str(REPO / "vendor" / "bin" / "rector"), "process",
         "--config=rector.php", "--no-progress-bar", "--",
         "src/Fixable.php"],
        cwd=cold_copy, capture_output=True, text=True, timeout=60,
    )
    assert done.returncode == 0, done.stderr
    cold_applied = (cold_copy / "src" / "Fixable.php").read_bytes().decode("utf-8")

    assert warm_applied == cold_applied


def test_registration_is_sent_and_the_clients_reply_is_not_answered(project):
    # #105, must fire: a client that declares dynamic registration for
    # watched files gets the client/registerCapability request. Its reply
    # (a RESPONSE: id + result, no method) must then produce no frame at
    # all -- the next frame on the wire has to be didOpen's diagnostics,
    # not a -32601 error aimed at the reply.
    proc = start_server(project, {
        "workspace": {"didChangeWatchedFiles": {"dynamicRegistration": True}},
    })
    try:
        request = read_frame(proc.stdout)
        assert request["method"] == "client/registerCapability"
        proc.stdin.write(frame({"jsonrpc": "2.0", "id": request["id"], "result": None}))
        proc.stdin.flush()

        uri = (project / "src" / "Fixable.php").as_uri()
        notification = did_open(proc, uri)

        assert "error" not in notification, notification
        assert notification["method"] == "textDocument/publishDiagnostics"
        assert len(notification["params"]["diagnostics"]) == 1
    finally:
        stop_server(proc)


def test_without_the_capability_nothing_is_registered_and_save_still_reloads_config(project):
    # #105, must not fire: `capabilities: {}` declares no dynamic
    # registration, so `initialized` must send nothing -- the first frame
    # after it is didOpen's diagnostics. The config still reloads without
    # the watcher: RectorRunner::configFileChanged() compares a content
    # hash of rector.php on every call, so the next didSave after an edit
    # to it publishes diagnostics under the NEW config.
    proc = start_server(project, {})
    try:
        uri = (project / "src" / "Fixable.php").as_uri()
        opened = did_open(proc, uri)
        assert opened["method"] == "textDocument/publishDiagnostics", opened
        assert len(opened["params"]["diagnostics"]) == 1

        config = project / "rector.php"
        config.write_text(config.read_text().replace(
            "->withPreparedSets(codeQuality: true);",
            "->withPreparedSets(codeQuality: true)\n"
            "    ->withSkip([\\Rector\\CodeQuality\\Rector\\If_\\SimplifyIfReturnBoolRector::class]);",
        ))

        proc.stdin.write(frame({
            "jsonrpc": "2.0",
            "method": "textDocument/didSave",
            "params": {"textDocument": {"uri": uri}},
        }))
        proc.stdin.flush()
        saved = read_frame(proc.stdout)

        assert saved["method"] == "textDocument/publishDiagnostics", saved
        assert saved["params"]["diagnostics"] == []
    finally:
        stop_server(proc)


def test_did_close_clears_diagnostics(server, project):
    uri = (project / "src" / "Fixable.php").as_uri()
    did_open(server, uri)

    server.stdin.write(frame({
        "jsonrpc": "2.0",
        "method": "textDocument/didClose",
        "params": {"textDocument": {"uri": uri}},
    }))
    server.stdin.flush()
    notification = read_frame(server.stdout)

    assert notification["method"] == "textDocument/publishDiagnostics"
    assert notification["params"]["diagnostics"] == []


# --------------------------------------------------------------- #96: manual LSP E2E cases


def test_did_open_on_a_syntax_error_file_publishes_an_error_diagnostic(server, project):
    # #90: a syntax error has no fix behind it (no quickfix), but must still
    # reach the editor as a Diagnostic at Error severity rather than being
    # dropped on the floor.
    uri = (project / "src" / "Broken.php").as_uri()

    notification = did_open(server, uri)

    diagnostics = notification["params"]["diagnostics"]
    assert len(diagnostics) == 1
    assert diagnostics[0]["severity"] == 1  # LSP Error
    assert diagnostics[0]["source"] == "rector"
    assert "Syntax error" in diagnostics[0]["message"]
    assert "data" not in diagnostics[0]  # no hunkIndex -- no quickfix to offer


def test_did_save_on_a_file_fixed_after_being_broken_does_not_drop_to_clean(server, project):
    # Positive control paired with the syntax-error test above: fixing the
    # syntax error and re-diagnosing (didSave) must publish a DIFFERENT
    # diagnostics list (the ordinary fixable-file one), not an empty one --
    # proving the error path above is read from the file's real content and
    # is not some permanently-stuck state.
    path = project / "src" / "Broken.php"
    uri = path.as_uri()
    did_open(server, uri)

    # Self-review finding: read_text()/write_text() with no `newline=` do
    # universal-newline translation on read AND (on Windows) LF -> os.linesep
    # on write -- on a Windows runner that would silently rewrite this
    # fixture's line endings mid-test. Bytes in, bytes out, no translation.
    path.write_bytes((project / "src" / "Fixable.php").read_bytes())
    notification = did_save(server, uri)

    diagnostics = notification["params"]["diagnostics"]
    assert len(diagnostics) == 1
    assert diagnostics[0]["severity"] == 3  # LSP Information/Warning-level hint, not Error
    assert "SimplifyIfReturnBoolRector" in diagnostics[0]["message"]


def test_did_open_on_a_file_outside_the_root_publishes_a_visible_diagnostic(server, project):
    # #90: a refused (out-of-root) call used to come back looking identical
    # to "nothing to report" -- only a stderr line traced it. It must be a
    # visible Error diagnostic instead.
    outside = project.parent / "outside.php"
    outside.write_text((project / "src" / "Fixable.php").read_bytes().decode("utf-8"), encoding="utf-8", newline="")
    uri = outside.as_uri()

    notification = did_open(server, uri)

    diagnostics = notification["params"]["diagnostics"]
    assert len(diagnostics) == 1
    assert diagnostics[0]["severity"] == 1
    assert "outside the configured working directory" in diagnostics[0]["message"]


def test_did_open_on_a_crlf_file_does_not_publish_a_no_op_diagnostic(server, project):
    # #91.3: Rector has been observed emitting a hunk with no `+`/`-` lines
    # at all (every line is context) for a CRLF-only difference alongside a
    # REAL hunk for the actual fix -- this fixture reproduces that shape for
    # real (verified empirically while writing this test: the cold `rector
    # process --output-format=json` diff for this exact file carries two
    # hunks, the first pure-context). Diagnostic ranges must cover only the
    # real change: exactly one diagnostic, not two.
    uri = (project / "src" / "CrlfFixable.php").as_uri()

    notification = did_open(server, uri)

    diagnostics = notification["params"]["diagnostics"]
    assert len(diagnostics) == 1
    assert "SimplifyIfReturnBoolRector" in diagnostics[0]["message"]


def test_did_open_on_an_already_clean_crlf_file_publishes_empty_diagnostics(server, project):
    # Negative control paired with the CRLF test above: a CRLF file Rector
    # does not need to touch at all must publish an EMPTY diagnostics list,
    # the same guarantee test_did_open_on_an_unchanged_file_publishes_empty_diagnostics
    # already gives for a plain-LF file.
    uri = (project / "src" / "CrlfClean.php").as_uri()

    notification = did_open(server, uri)

    assert notification["params"]["diagnostics"] == []


@pytest.fixture
def insert_only_project(tmp_path):
    dest = tmp_path / "insert-only-project"
    copy_fixture(dest, source=INSERT_ONLY_FIXTURE)
    return dest


@pytest.fixture
def insert_only_server(insert_only_project):
    proc = subprocess.Popen(
        [php_binary(), str(BIN), f"--working-dir={insert_only_project}"],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    proc.stdin.write(frame({
        "jsonrpc": "2.0", "id": 1, "method": "initialize",
        "params": {"processId": None, "rootUri": None, "capabilities": {}},
    }))
    proc.stdin.flush()
    read_frame(proc.stdout)
    try:
        yield proc
    finally:
        if proc.poll() is None:
            proc.kill()
        proc.wait(timeout=10)


def test_code_action_at_an_insert_only_hunks_own_zero_width_range_offers_its_quickfix(
    insert_only_server, insert_only_project,
):
    # #93 E2E: DeclareStrictTypesRector on a namespaced file with no
    # `declare(strict_types=1)` produces a pure-insertion hunk -- verified
    # empirically while writing this test: RectorDiffParser::buildFixes()
    # turns it into a zero-width range (start.line == end.line). A real
    # editor's codeAction request at exactly that zero-width range (the
    # cursor sitting on the fix's own line, no selection) must still offer
    # the quickfix -- #93 is exactly the half-open-interval overlap bug that
    # used to drop it here.
    uri = (insert_only_project / "src" / "NoStrict.php").as_uri()
    notification = did_open(insert_only_server, uri)
    diagnostic = notification["params"]["diagnostics"][0]
    assert diagnostic["range"]["start"] == diagnostic["range"]["end"]  # zero-width

    insert_only_server.stdin.write(frame({
        "jsonrpc": "2.0",
        "id": 2,
        "method": "textDocument/codeAction",
        "params": {
            "textDocument": {"uri": uri},
            "range": diagnostic["range"],
            "context": {"diagnostics": [diagnostic]},
        },
    }))
    insert_only_server.stdin.flush()
    actions = read_frame(insert_only_server.stdout)["result"]

    assert any(a["title"].startswith("Apply Rector:") for a in actions)


def test_code_action_on_a_neighbouring_line_near_an_insert_only_hunk_is_not_offered(
    insert_only_server, insert_only_project,
):
    # Negative control paired with the test above: a codeAction request at a
    # DIFFERENT zero-width range (a neighbouring line) must not receive the
    # insert-only hunk's quickfix -- otherwise the fix above could pass by
    # simply broadcasting the quickfix to every request.
    uri = (insert_only_project / "src" / "NoStrict.php").as_uri()
    notification = did_open(insert_only_server, uri)
    diagnostic = notification["params"]["diagnostics"][0]
    own_line = diagnostic["range"]["start"]["line"]
    neighbour_line = own_line + 5
    assert neighbour_line != own_line

    insert_only_server.stdin.write(frame({
        "jsonrpc": "2.0",
        "id": 2,
        "method": "textDocument/codeAction",
        "params": {
            "textDocument": {"uri": uri},
            "range": {
                "start": {"line": neighbour_line, "character": 0},
                "end": {"line": neighbour_line, "character": 0},
            },
            "context": {"diagnostics": [diagnostic]},
        },
    }))
    insert_only_server.stdin.flush()
    actions = read_frame(insert_only_server.stdout)["result"]

    assert not any(a["title"].startswith("Apply Rector:") for a in actions)


def test_a_second_save_on_the_same_warm_worker_is_not_stale(server, project):
    # The warm worker (RectorRunner) is reused across calls within one
    # daemon session -- a second didSave must reflect the file's CURRENT
    # content, not a cached copy of the first diagnose() result.
    path = project / "src" / "Fixable.php"
    uri = path.as_uri()
    original_bytes = path.read_bytes()
    did_open(server, uri)  # 1st call: 1 diagnostic

    # Same self-review fix as test_did_save_on_a_file_fixed_after_being_broken_
    # does_not_drop_to_clean above: bytes in, bytes out, no os.linesep
    # translation on write.
    clean_text = (project / "src" / "Clean.php").read_bytes().decode("utf-8").replace("Clean", "Fixable")
    path.write_bytes(clean_text.encode("utf-8"))
    first_save = did_save(server, uri, version=2)
    assert first_save["params"]["diagnostics"] == []  # now clean

    path.write_bytes(original_bytes)  # bring the fixable content back
    second_save = did_save(server, uri, version=3)

    diagnostics = second_save["params"]["diagnostics"]
    assert len(diagnostics) == 1, (
        "second save must re-diagnose fresh content, not return a stale "
        f"cached result from the first call: {second_save}"
    )
    assert "SimplifyIfReturnBoolRector" in diagnostics[0]["message"]


def test_shutdown_then_exit_leaves_no_warm_worker_process(server, project):
    if sys.platform.startswith("win"):
        pytest.skip("no pcntl/fork on Windows -- no warm worker is ever spawned here (#96, #97)")

    uri = (project / "src" / "Fixable.php").as_uri()
    did_open(server, uri)  # boots the warm worker (RectorTool -> RectorRunner)

    descendants = process_descendants(server.pid)
    worker_pids = [pid for pid, _ppid, _stat in descendants]
    # Positive control for the assertion below: proves the harness can see a
    # live descendant at all, so an empty result after shutdown means the
    # worker actually stopped, not that this check never worked.
    assert worker_pids, "didOpen should have booted a warm worker child of the LSP daemon"

    server.stdin.write(frame({"jsonrpc": "2.0", "id": 99, "method": "shutdown"}))
    server.stdin.flush()
    read_frame(server.stdout)
    server.stdin.write(frame({"jsonrpc": "2.0", "method": "exit"}))
    server.stdin.close()
    assert server.wait(timeout=10) == 0

    # The worker only notices its socket closed (EOF) on its NEXT blocking
    # read (serveWorker()'s own loop) -- give it a bounded moment to react
    # to the daemon disappearing rather than asserting in the same instant.
    deadline = time.monotonic() + 5
    still_alive = list(worker_pids)
    while still_alive and time.monotonic() < deadline:
        still_alive = [pid for pid in still_alive if _pid_is_alive(pid)]
        if still_alive:
            time.sleep(0.1)
    assert still_alive == [], f"warm worker pid(s) {still_alive} are still alive after shutdown+exit"


def _pid_is_alive(pid: int) -> bool:
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    return True
