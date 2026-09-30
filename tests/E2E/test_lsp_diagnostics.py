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
MULTI_HUNK_SINGLE_RULE_FIXTURE = REPO / "tests" / "Fixtures" / "lsp-multi-hunk-single-rule-project"


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


def test_code_action_edit_applied_to_a_crlf_file_matches_a_cold_rector_apply(server, project):
    # #160, self-review reversal (see RectorDiffParser.php's own docblocks
    # for the full story): this test originally asserted the applied edit
    # kept a UNIFORM CRLF convention throughout. CI caught that this is the
    # wrong bar -- a COLD, real `vendor/bin/rector process` apply on this
    # exact CRLF fixture ALSO writes its replacement lines with a bare
    # "\n" (Rector's own pretty-printer output, never normalized to the
    # file's original convention), so "uniform CRLF" is not what this
    # server is supposed to produce. This server's actual contract
    # (CLAUDE.md's warm-vs-cold oracle, and the module docstring above) is
    # byte-for-byte parity with cold -- same pattern as
    # test_code_action_edit_matches_a_cold_rector_apply above, just against
    # the CRLF fixture #91.3/#96 added (which #160 identified as never
    # actually having its own codeAction+apply path exercised at all,
    # CRLF-mixed-ending oracle included).
    path = project / "src" / "CrlfFixable.php"
    uri = path.as_uri()
    original = path.read_bytes().decode("utf-8")
    assert "\r\n" in original  # positive control: the fixture really is CRLF

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
    actions = read_frame(server.stdout)["result"]
    quickfix = next(a for a in actions if a["title"].startswith("Apply Rector:"))
    edit = quickfix["edit"]["changes"][uri][0]
    warm_applied = apply_edit(original, edit)

    # Cold oracle: apply Rector for real on a fresh copy of the ORIGINAL file.
    cold_copy = project.parent / "cold-crlf"
    copy_fixture(cold_copy)
    done = subprocess.run(
        [php_binary(), str(REPO / "vendor" / "bin" / "rector"), "process",
         "--config=rector.php", "--no-progress-bar", "--",
         "src/CrlfFixable.php"],
        cwd=cold_copy, capture_output=True, text=True, timeout=60,
    )
    assert done.returncode == 0, done.stderr
    cold_applied = (cold_copy / "src" / "CrlfFixable.php").read_bytes().decode("utf-8")

    assert warm_applied == cold_applied


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


@pytest.fixture
def multi_hunk_single_rule_project(tmp_path):
    dest = tmp_path / "multi-hunk-single-rule-project"
    copy_fixture(dest, source=MULTI_HUNK_SINGLE_RULE_FIXTURE)
    return dest


@pytest.fixture
def multi_hunk_single_rule_server(multi_hunk_single_rule_project):
    proc = subprocess.Popen(
        [php_binary(), str(BIN), f"--working-dir={multi_hunk_single_rule_project}"],
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


def test_two_hunks_from_one_rule_both_get_the_specific_label(
    multi_hunk_single_rule_server, multi_hunk_single_rule_project,
):
    # #100 (reopened -- #103's fallback did not cover this case): verified
    # empirically while writing this test -- RemoveUnusedPrivatePropertyRector
    # on a file with two unused private properties far enough apart to land
    # in separate diff hunks produces exactly this shape from a real, cold
    # `vendor/bin/rector process --output-format=json` run: 2 hunks,
    # `applied_rectors` naming the one rule, and `changes[]` naming it only
    # ONCE (attributed to the first hunk by closest-hunk matching). Both
    # hunks must get the specific rule name, not "Rector fix" for the second.
    uri = (multi_hunk_single_rule_project / "src" / "TwoUnusedProperties.php").as_uri()

    notification = did_open(multi_hunk_single_rule_server, uri)

    diagnostics = notification["params"]["diagnostics"]
    assert len(diagnostics) == 2, notification
    for diagnostic in diagnostics:
        assert "RemoveUnusedPrivatePropertyRector" in diagnostic["message"], diagnostic


def test_watched_config_change_re_diagnoses_the_most_recently_active_document_first(project):
    # #115: verified over the real stdio transport and the real LspServer
    # (not just the unit-level DiagnosticsSource fake in LspServerTest) --
    # opening A then B, then a rector.php watched-file change, must
    # republish B (the more recently active document) before A.
    proc = start_server(project, {
        "workspace": {"didChangeWatchedFiles": {"dynamicRegistration": True}},
    })
    try:
        request = read_frame(proc.stdout)
        assert request["method"] == "client/registerCapability"
        proc.stdin.write(frame({"jsonrpc": "2.0", "id": request["id"], "result": None}))
        proc.stdin.flush()

        uri_a = (project / "src" / "Fixable.php").as_uri()
        uri_b = (project / "src" / "Clean.php").as_uri()
        did_open(proc, uri_a)
        did_open(proc, uri_b)

        proc.stdin.write(frame({
            "jsonrpc": "2.0",
            "method": "workspace/didChangeWatchedFiles",
            "params": {"changes": [{"uri": (project / "rector.php").as_uri(), "type": 2}]},
        }))
        proc.stdin.flush()

        first = read_frame(proc.stdout)
        second = read_frame(proc.stdout)

        assert first["params"]["uri"] == uri_b, (first, second)
        assert second["params"]["uri"] == uri_a, (first, second)
    finally:
        stop_server(proc)


# --- #106: diagnostics on unsaved buffers (didChange, full sync, debounced) --

FIXABLE_BUFFER_FOR_CLEAN = """<?php

declare(strict_types=1);

final class Clean
{
    public function isEmpty(array $items): bool
    {
        if (count($items) === 0) {
            return true;
        }

        return false;
    }
}
"""


def did_change(server, uri: str, version: int, text: str) -> None:
    server.stdin.write(frame({
        "jsonrpc": "2.0",
        "method": "textDocument/didChange",
        "params": {
            "textDocument": {"uri": uri, "version": version},
            "contentChanges": [{"text": text}],
        },
    }))
    server.stdin.flush()


def temp_leftovers(project: Path) -> list[str]:
    return sorted(str(p.relative_to(project)) for p in project.rglob("*") if "rector-warm" in p.name)


def cold_apply(project: Path, relative: str, content: str) -> str:
    """The oracle: a cold, non-dry-run rector on a fresh copy of the fixture
    with `content` written at `relative` -- same content, same path."""
    cold = project.parent / "cold-buffer"
    if cold.exists():
        shutil.rmtree(cold)
    copy_fixture(cold)
    (cold / relative).write_text(content)
    done = subprocess.run(
        [php_binary(), str(REPO / "vendor" / "bin" / "rector"), "process",
         "--config=rector.php", "--no-progress-bar", "--", relative],
        cwd=cold, capture_output=True, text=True, timeout=120,
    )
    assert done.returncode == 0, done.stderr
    return (cold / relative).read_text()


def cold_dry_run_report(project: Path, relative: str, content: str) -> dict:
    cold = project.parent / "cold-dry"
    if cold.exists():
        shutil.rmtree(cold)
    copy_fixture(cold)
    (cold / relative).write_text(content)
    done = subprocess.run(
        [php_binary(), str(REPO / "vendor" / "bin" / "rector"), "process", "--dry-run",
         "--config=rector.php", "--no-progress-bar", "--output-format=json", "--", relative],
        cwd=cold, capture_output=True, text=True, timeout=120,
    )
    return json.loads(done.stdout[done.stdout.index("{"):])


def test_initialize_advertises_full_sync(project):
    proc = subprocess.Popen(
        [php_binary(), str(BIN), f"--working-dir={project}"],
        stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    try:
        proc.stdin.write(frame({
            "jsonrpc": "2.0", "id": 1, "method": "initialize",
            "params": {"processId": None, "rootUri": None, "capabilities": {}},
        }))
        proc.stdin.flush()
        reply = read_frame(proc.stdout)
        assert reply["result"]["capabilities"]["textDocumentSync"]["change"] == 1
    finally:
        stop_server(proc)


def _pid_is_alive(pid: int) -> bool:
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    return True


def test_did_change_with_fixable_content_publishes_without_a_save_and_matches_cold(server, project):
    # Must fire: Clean.php is clean on disk; only the unsaved buffer is
    # fixable. No didSave is ever sent.
    path = project / "src" / "Clean.php"
    uri = path.as_uri()
    on_disk = path.read_text()
    assert did_open(server, uri)["params"]["diagnostics"] == []

    did_change(server, uri, 2, FIXABLE_BUFFER_FOR_CLEAN)
    notification = read_frame(server.stdout)

    assert notification["method"] == "textDocument/publishDiagnostics"
    assert notification["params"]["uri"] == uri
    assert notification["params"]["version"] == 2
    diagnostics = notification["params"]["diagnostics"]
    assert len(diagnostics) == 1
    assert "SimplifyIfReturnBoolRector" in diagnostics[0]["message"]

    # Oracle, dry-run form: cold rector on a file with the same content at
    # the same path reports the same rule and one changed file.
    report = cold_dry_run_report(project, "src/Clean.php", FIXABLE_BUFFER_FOR_CLEAN)
    assert report["totals"]["changed_files"] == 1
    assert any("SimplifyIfReturnBoolRector" in r for r in report["file_diffs"][0]["applied_rectors"])

    # Oracle, applied form: the buffer's own fix-all edit gives the exact
    # bytes a cold rector apply gives on that content at that path.
    server.stdin.write(frame({
        "jsonrpc": "2.0", "id": 3, "method": "textDocument/codeAction",
        "params": {"textDocument": {"uri": uri}, "range": diagnostics[0]["range"],
                   "context": {"diagnostics": diagnostics}},
    }))
    server.stdin.flush()
    actions = read_frame(server.stdout)["result"]
    fix_all = next(a for a in actions if a["title"] == "Apply all Rector fixes")
    applied = FIXABLE_BUFFER_FOR_CLEAN
    for edit in sorted(fix_all["edit"]["changes"][uri], key=lambda e: e["range"]["start"]["line"], reverse=True):
        applied = apply_edit(applied, edit)
    assert applied == cold_apply(project, "src/Clean.php", FIXABLE_BUFFER_FOR_CLEAN)

    # The file on disk was never touched, and no temp file is left behind.
    assert path.read_text() == on_disk
    assert temp_leftovers(project) == []


def test_a_buffer_changed_back_to_clean_content_publishes_an_empty_list(server, project):
    path = project / "src" / "Clean.php"
    uri = path.as_uri()
    did_open(server, uri)

    did_change(server, uri, 2, FIXABLE_BUFFER_FOR_CLEAN)
    assert len(read_frame(server.stdout)["params"]["diagnostics"]) == 1

    did_change(server, uri, 3, path.read_text())
    notification = read_frame(server.stdout)

    assert notification["params"]["version"] == 3
    assert notification["params"]["diagnostics"] == []
    assert temp_leftovers(project) == []


def test_a_syntax_error_buffer_publishes_the_error_and_leaves_no_temp_file(server, project):
    path = project / "src" / "Clean.php"
    uri = path.as_uri()
    did_open(server, uri)

    did_change(server, uri, 2, "<?php\n\nfinal class Clean\n{\n    public function (\n")
    notification = read_frame(server.stdout)

    diagnostics = notification["params"]["diagnostics"]
    assert len(diagnostics) >= 1
    assert all(d["severity"] == 1 for d in diagnostics)
    assert all("rector-warm" not in d["message"] for d in diagnostics)
    assert temp_leftovers(project) == []


def test_a_result_for_version_n_is_not_published_once_n_plus_1_arrived(server, project):
    # Must not fire. No didOpen first, so the version-2 run is the one that
    # boots Rector's container -- well over a second, every platform. Version
    # 3 is sent while it is in flight; the server must drain it before
    # publishing, so the first publish is version 3's, never version 2's.
    # Positive control: version 3's (clean) result IS published.
    path = project / "src" / "Clean.php"
    uri = path.as_uri()

    did_change(server, uri, 2, FIXABLE_BUFFER_FOR_CLEAN)
    time.sleep(0.8)
    did_change(server, uri, 3, path.read_text())

    notification = read_frame(server.stdout)
    assert notification["method"] == "textDocument/publishDiagnostics"
    assert notification["params"]["version"] == 3, notification
    assert notification["params"]["diagnostics"] == []
    assert temp_leftovers(project) == []


# --- #106 follow-up: independent E2E findings (reload loop, skips, leftovers) --

WATCHING_CLIENT = {"workspace": {"didChangeWatchedFiles": {"dynamicRegistration": True}}}


def start_watching_server(project: Path):
    """A server whose client declared file watching, with its
    registerCapability request answered, as a real editor would."""
    proc = start_server(project, WATCHING_CLIENT)
    request = read_frame(proc.stdout)
    assert request["method"] == "client/registerCapability"
    proc.stdin.write(frame({"jsonrpc": "2.0", "id": request["id"], "result": None}))
    proc.stdin.flush()
    return proc


def watched_change(server, uri: str) -> None:
    server.stdin.write(frame({
        "jsonrpc": "2.0",
        "method": "workspace/didChangeWatchedFiles",
        "params": {"changes": [{"uri": uri, "type": 2}]},
    }))
    server.stdin.flush()


def test_an_unsaved_rector_php_is_not_diagnosed(project):
    # Must not fire: rector.php's buffer used to be diagnosed through a temp
    # copy at <root>/.rector-warm-<pid>/rector.php, which the client's own
    # **/rector.php watcher reported back as a config change -- the reload
    # loop. The rector.php change is sent FIRST, so if it were diagnosed its
    # publish would arrive first. Positive control: Clean.php's publish does.
    proc = start_watching_server(project)
    try:
        rector = project / "rector.php"
        did_change(proc, rector.as_uri(), 2, rector.read_bytes().decode("utf-8") + "\n// unsaved\n")
        clean_uri = (project / "src" / "Clean.php").as_uri()
        did_change(proc, clean_uri, 2, FIXABLE_BUFFER_FOR_CLEAN)

        first = read_frame(proc.stdout)
        assert first["params"]["uri"] == clean_uri, first
        assert temp_leftovers(project) == []
    finally:
        stop_server(proc)


def test_a_watched_event_for_a_temp_copy_does_not_rediagnose(project):
    # Must not fire: an event for <root>/.rector-warm-<pid>/rector.php is the
    # server's own temp file, not a config change. If it re-queued the open
    # Clean.php buffer, Clean's publish (due at once) would arrive before
    # Fixable's (due after the 500 ms debounce). Positive control: the real
    # rector.php event does re-queue Clean.php.
    proc = start_watching_server(project)
    try:
        clean_uri = (project / "src" / "Clean.php").as_uri()
        fixable_uri = (project / "src" / "Fixable.php").as_uri()
        did_change(proc, clean_uri, 2, FIXABLE_BUFFER_FOR_CLEAN)
        assert read_frame(proc.stdout)["params"]["uri"] == clean_uri

        watched_change(proc, (project / ".rector-warm-99999" / "rector.php").as_uri())
        did_change(proc, fixable_uri, 2, (project / "src" / "Fixable.php").read_bytes().decode("utf-8"))
        assert read_frame(proc.stdout)["params"]["uri"] == fixable_uri

        watched_change(proc, (project / "rector.php").as_uri())
        assert read_frame(proc.stdout)["params"]["uri"] == clean_uri
    finally:
        stop_server(proc)


SKIP_FORMS = {
    "exact path": ("__DIR__ . '/src/Fixable.php'", 0),
    "relative path": ("'src/Fixable.php'", 0),
    "parent-dir glob": ("'*/src/Fixable.php'", 0),
    "rule-scoped exact path": (
        "\\Rector\\CodeQuality\\Rector\\If_\\SimplifyIfReturnBoolRector::class => [__DIR__ . '/src/Fixable.php']",
        0,
    ),
    "control: unrelated glob": ("'*/Other.php'", 1),
    "control: no skip": (None, 1),
}


@pytest.mark.parametrize("form", list(SKIP_FORMS))
def test_unsaved_diagnostics_honour_the_original_paths_skips(project, form):
    # Unsaved == saved == cold, for each withSkip() form that names the
    # ORIGINAL path, with two controls that must still report the fix.
    skip, expected = SKIP_FORMS[form]
    config = project / "rector.php"
    text = config.read_bytes().decode("utf-8")
    if skip is not None:
        text = text.replace(
            "->withPreparedSets(codeQuality: true);",
            f"->withPreparedSets(codeQuality: true)\n    ->withSkip([{skip}]);",
        )
    config.write_bytes(text.encode("utf-8"))

    path = project / "src" / "Fixable.php"
    content = path.read_bytes().decode("utf-8")
    uri = path.as_uri()
    proc = start_server(project, {})
    try:
        saved = len(did_open(proc, uri)["params"]["diagnostics"])
        did_change(proc, uri, 2, content)
        unsaved = len(read_frame(proc.stdout)["params"]["diagnostics"])
    finally:
        stop_server(proc)

    cold = subprocess.run(
        [php_binary(), str(REPO / "vendor" / "bin" / "rector"), "process", "--dry-run",
         "--config=rector.php", "--no-progress-bar", "--output-format=json", "--", "src/Fixable.php"],
        cwd=project, capture_output=True, text=True, timeout=120,
    )
    cold_changed = json.loads(cold.stdout[cold.stdout.index("{"):])["totals"]["changed_files"]

    assert (unsaved, saved, cold_changed) == (expected, expected, expected)
    assert temp_leftovers(project) == []


def test_startup_removes_a_dead_servers_temp_dir_and_keeps_a_live_ones(project):
    # A server killed mid-run (kill -9) never reaches its `finally`. The next
    # server sweeps .rector-warm-<pid> dirs whose pid is gone; one whose pid
    # is alive (here: this pytest process, standing in for another editor's
    # server) is kept.
    exited = subprocess.Popen([sys.executable, "-c", "pass"])
    exited.wait()
    dead = project / "src" / f".rector-warm-{exited.pid}"
    live = project / "src" / f".rector-warm-{os.getpid()}"
    for directory in (dead, live):
        directory.mkdir()
        (directory / "Clean.php").write_bytes(b"<?php\n")

    proc = start_server(project, {})
    try:
        # Any reply proves startup finished: initialize was answered.
        assert did_open(proc, (project / "src" / "Clean.php").as_uri())["method"] == "textDocument/publishDiagnostics"
        assert not dead.exists()
        assert live.exists()
    finally:
        stop_server(proc)
