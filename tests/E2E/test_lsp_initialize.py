"""E2E smoke test for bin/rector-warm-lsp (#52/#53).

Deliberately does NOT use mcp_harness.py or the mcp SDK: LSP framing
(Content-Length-framed JSON-RPC) is a different wire format from MCP's own,
and the whole point of #52's decision (docs/decisions/0001-lsp-library-choice.md)
is that this repo hand-rolls it rather than depending on a library. So this
test hand-rolls the client side too, over a real subprocess and real stdio --
the same path #52's "done when" asked to prove.
"""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import time
from pathlib import Path

import pytest

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
BIN = REPO / "bin" / "rector-warm-lsp"


def php_binary() -> str:
    php = os.environ.get("PHP_BINARY") or shutil.which("php")
    if not php:
        raise RuntimeError("no PHP interpreter: put php on PATH or set PHP_BINARY")
    return php


def frame(message: dict) -> bytes:
    body = json.dumps(message).encode("utf-8")
    return b"Content-Length: %d\r\n\r\n%s" % (len(body), body)


def read_frame(stream) -> dict:
    header = b""
    while not header.endswith(b"\r\n\r\n"):
        chunk = stream.read(1)
        if chunk == b"":
            raise EOFError(f"stream closed mid-header, got so far: {header!r}")
        header += chunk
    length = None
    for line in header.split(b"\r\n"):
        if line.lower().startswith(b"content-length:"):
            length = int(line.split(b":", 1)[1].strip())
    assert length is not None, f"no Content-Length header in {header!r}"
    body = stream.read(length)
    assert len(body) == length, f"truncated body: expected {length}, got {len(body)}"
    return json.loads(body)


@pytest.fixture
def server():
    proc = subprocess.Popen(
        [php_binary(), str(BIN)],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    try:
        yield proc
    finally:
        if proc.poll() is None:
            proc.kill()
        proc.wait(timeout=10)


def test_initialize_returns_server_info_and_capabilities(server):
    server.stdin.write(frame({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "initialize",
        "params": {"processId": None, "rootUri": None, "capabilities": {}},
    }))
    server.stdin.flush()

    response = read_frame(server.stdout)

    assert response["id"] == 1
    assert response["result"]["serverInfo"]["name"] == "rector-warm-lsp"
    # v1 (#53): no longer empty -- diagnostics + codeAction are real now.
    assert response["result"]["capabilities"]["codeActionProvider"] is True
    assert response["result"]["capabilities"]["textDocumentSync"]["openClose"] is True


def test_shutdown_then_exit_gives_a_clean_exit_code(server):
    server.stdin.write(frame({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}}))
    server.stdin.flush()
    read_frame(server.stdout)

    server.stdin.write(frame({"jsonrpc": "2.0", "id": 2, "method": "shutdown"}))
    server.stdin.flush()
    shutdown_response = read_frame(server.stdout)
    assert shutdown_response["result"] is None

    server.stdin.write(frame({"jsonrpc": "2.0", "method": "exit"}))
    server.stdin.close()

    assert server.wait(timeout=10) == 0


def test_exit_without_shutdown_gives_a_nonzero_exit_code(server):
    # Negative-control pairing for the clean-exit case above: skipping
    # `shutdown` must be visibly different, or a client could never tell a
    # graceful stop from a server that just died.
    server.stdin.write(frame({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}}))
    server.stdin.flush()
    read_frame(server.stdout)

    server.stdin.write(frame({"jsonrpc": "2.0", "method": "exit"}))
    server.stdin.close()

    assert server.wait(timeout=10) != 0


def test_initialize_does_not_walk_a_large_project_tree_before_reading_stdin(tmp_path):
    # #179's own motivation: a real 136k-file project measured `initialize`
    # at 1.4-3.6s versus 0.06s on this repo's own tiny fixture tree -- caused
    # by TempCopySweeper::sweepTree(getcwd()) walking the whole workspace
    # before the loop ever reads stdin (removed by #179's redesign, which
    # replaces the startup tree walk with an flock()-based check that never
    # touches directories it isn't already about to diagnose).
    #
    # This is a positive-control pair, not a bare "must not be slow": run
    # against the pre-#179 code (TempCopySweeper::sweepTree still present
    # and wired into bin/rector-warm-lsp), a 15,000-sibling-directory tree
    # measured 1.19s for this same round trip versus 0.03s for an empty
    # directory -- so a walk big enough to matter is what this asserts
    # against, not an accident of this one tree's shape.
    big_tree = tmp_path / "big-project"
    big_tree.mkdir()
    for i in range(15_000):
        (big_tree / f"d{i}").mkdir()

    proc = subprocess.Popen(
        [php_binary(), str(BIN)],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        cwd=big_tree,
    )
    try:
        started = time.monotonic()
        proc.stdin.write(frame({
            "jsonrpc": "2.0",
            "id": 1,
            "method": "initialize",
            "params": {"processId": None, "rootUri": None, "capabilities": {}},
        }))
        proc.stdin.flush()

        response = read_frame(proc.stdout)
        elapsed = time.monotonic() - started
    finally:
        if proc.poll() is None:
            proc.kill()
        proc.wait(timeout=10)

    assert response["id"] == 1
    # Observed on this machine: pre-#179 ~1.19s on this tree, ~0.03s on an
    # empty one; post-#179 ~0.05s on this tree. 0.5s leaves a wide margin
    # above the fixed code's own measurement while staying far below the
    # walk's, so a slower CI runner does not make this flaky in either
    # direction.
    assert elapsed < 0.5, (
        f"initialize took {elapsed:.3f}s against a 15000-directory tree -- "
        "this is the shape of a reintroduced startup tree walk (#179)"
    )
