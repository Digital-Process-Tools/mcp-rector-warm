"""E2E: window/workDoneProgress around the cold-boot diagnose, and honouring
a client's refusal of window/workDoneProgress/create (#111, PR #128 review
findings 1 and 2) -- over a real subprocess and real stdio, same hand-rolled
client as test_lsp_initialize.py / test_lsp_diagnostics.py.

The unit-level tests in tests/Unit/LspServerTest.php already pin the byte-
level ORDER of "written vs. diagnose() called" via a shared instrumentation
log (a real subprocess gives no such hook mid-call). What this file adds
that the unit tests cannot: proof that the real binary, talking real
Content-Length-framed JSON-RPC over real OS pipes, produces the same shape
-- and, for the create-refusal case, that a reply genuinely sitting in the
kernel's pipe buffer by the time the server checks is honoured for real.
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

import pytest

from test_lsp_initialize import BIN, frame, php_binary, read_frame

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
FIXTURE = REPO / "tests" / "Fixtures" / "lsp-project"


# Deliberately NOT imported from test_lsp_diagnostics.py: that module pulls
# in mcp_harness.py, which imports the `mcp` SDK package -- an optional
# dependency this file (like test_lsp_initialize.py) has no need for. Same
# small helpers, copied rather than shared, to keep that import graph out of
# this file's own collection path.
@pytest.fixture
def project(tmp_path):
    dest = tmp_path / "project"
    shutil.copytree(FIXTURE, dest)
    return dest


def start_server(project: Path, capabilities: dict):
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


def test_progress_create_begin_end_surround_the_first_diagnose(project):
    # PR #128 second E2E review: isProgressCreateRefused() now genuinely
    # WAITS (up to ~200ms) for the client's reply to `create` before
    # sending `begin` -- a real, spec-compliant client always answers a
    # server-initiated request, so this pipelines that answer in the same
    # write as didOpen (same buffering technique as the refusal test
    # below), exactly what a conforming client's own event loop would do
    # well within the wait window.
    server = start_server(project, {"window": {"workDoneProgress": True}})
    try:
        uri = (project / "src" / "Fixable.php").as_uri()
        server.stdin.write(frame({
            "jsonrpc": "2.0",
            "method": "textDocument/didOpen",
            "params": {"textDocument": {"uri": uri, "languageId": "php", "version": 1, "text": ""}},
        }))
        server.stdin.write(frame({
            "jsonrpc": "2.0",
            "id": "rector-warm-lsp/progress-create",
            "result": None,
        }))
        server.stdin.flush()

        create = read_frame(server.stdout)
        begin = read_frame(server.stdout)
        end = read_frame(server.stdout)
        diagnostics = read_frame(server.stdout)

        assert create["method"] == "window/workDoneProgress/create"
        assert create["params"]["token"] == "rector-warm-lsp/cold-boot"
        assert begin["method"] == "$/progress"
        assert begin["params"]["value"]["kind"] == "begin"
        assert begin["params"]["value"]["title"] == "Rector: warming up"
        assert end["method"] == "$/progress"
        assert end["params"]["value"]["kind"] == "end"
        assert diagnostics["method"] == "textDocument/publishDiagnostics"
    finally:
        stop_server(server)


def test_progress_is_not_sent_at_all_without_the_client_capability(project):
    # Positive control for the test above: no window.workDoneProgress
    # capability declared, no progress frames of any kind -- only
    # publishDiagnostics for the same didOpen.
    server = start_server(project, {})
    try:
        uri = (project / "src" / "Fixable.php").as_uri()
        server.stdin.write(frame({
            "jsonrpc": "2.0",
            "method": "textDocument/didOpen",
            "params": {"textDocument": {"uri": uri, "languageId": "php", "version": 1, "text": ""}},
        }))
        server.stdin.flush()

        diagnostics = read_frame(server.stdout)

        assert diagnostics["method"] == "textDocument/publishDiagnostics"
    finally:
        stop_server(server)


def test_progress_is_suppressed_when_the_client_refuses_create(project):
    # PR #128 second E2E review pass: this test's own skip on Windows is
    # GONE, not just relaxed -- the windows-latest CI failure that pass
    # found was tryRead()'s own naive stream_select() call hanging
    # (job #109299103869, OBSERVED, not merely reasoned about). tryRead()
    # now delegates to StdioLspTransport::waitForInput(), the SAME
    # Windows-safe primitive #106's own debounce timer already uses in
    # production there (a non-socket, file-backed STDIN pipe polls via
    # PeekNamedPipe/fstat() instead of select() on that platform) -- so
    # this is expected to pass on all three OSes now, not just POSIX.
    server = start_server(project, {"window": {"workDoneProgress": True}})
    try:
        uri = (project / "src" / "Fixable.php").as_uri()

        # Pipeline didOpen followed IMMEDIATELY by an error response for the
        # create id the server is about to send, in one write before
        # reading anything back -- both frames sit buffered in the pipe by
        # the time the server's own non-blocking peek runs mid-didOpen.
        server.stdin.write(frame({
            "jsonrpc": "2.0",
            "method": "textDocument/didOpen",
            "params": {"textDocument": {"uri": uri, "languageId": "php", "version": 1, "text": ""}},
        }))
        server.stdin.write(frame({
            "jsonrpc": "2.0",
            "id": "rector-warm-lsp/progress-create",
            "error": {"code": -32800, "message": "client declined this progress token"},
        }))
        server.stdin.flush()

        create = read_frame(server.stdout)
        diagnostics = read_frame(server.stdout)

        assert create["method"] == "window/workDoneProgress/create"
        assert diagnostics["method"] == "textDocument/publishDiagnostics"
    finally:
        stop_server(server)
