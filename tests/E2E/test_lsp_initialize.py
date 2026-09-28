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
