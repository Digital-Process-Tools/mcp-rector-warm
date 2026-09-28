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
from pathlib import Path

import pytest

from test_lsp_initialize import BIN, frame, php_binary, read_frame

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
FIXTURE = REPO / "tests" / "Fixtures" / "lsp-project"


def copy_fixture(dest: Path) -> None:
    shutil.copytree(FIXTURE, dest)


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
    original = path.read_text()

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
    cold_applied = (cold_copy / "src" / "Fixable.php").read_text()

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
