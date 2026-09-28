"""Transparent stdio tap between the MCP client and the server under test.

    python stdio_tap.py RECORD_DIR -- COMMAND [ARGS...]

Runs COMMAND, forwards this process's stdin to it and its stdout back out, byte for
byte, and keeps a copy of every stdout byte in RECORD_DIR/stdout.raw. The server's
stderr is inherited, so it reaches whatever the client pointed stderr at.

Why a tap: the MCP client parses stdout and turns a non-JSON line into a logged
exception, so "nothing but JSON-RPC ever reached stdout" cannot be asserted from the
client's side alone. The raw copy lets a test check every byte the server wrote.

When the client closes stdin (how an MCP host ends a stdio session), the tap closes
the server's stdin and waits EXIT_GRACE seconds for it to exit, then writes
RECORD_DIR/exit.json: {"exited": true, "returncode": N} or {"exited": false}.
EXIT_GRACE stays below the client's own 2 s grace, so the record is written before
the client would start terminating the process tree.
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
import threading
from pathlib import Path

EXIT_GRACE = 1.5
CHUNK = 65536


def main() -> int:
    if len(sys.argv) < 4 or sys.argv[2] != "--":
        print("usage: stdio_tap.py RECORD_DIR -- COMMAND [ARGS...]", file=sys.stderr)
        return 64
    record = Path(sys.argv[1])
    record.mkdir(parents=True, exist_ok=True)
    child = subprocess.Popen(sys.argv[3:], stdin=subprocess.PIPE, stdout=subprocess.PIPE, bufsize=0)
    (record / "started.json").write_text(json.dumps({"pid": child.pid, "argv": sys.argv[3:]}), encoding="utf-8", newline="")

    raw = open(record / "stdout.raw", "wb")
    out_fd = sys.stdout.fileno()

    def pump_stdout() -> None:
        client_gone = False
        while True:
            chunk = os.read(child.stdout.fileno(), CHUNK)
            if not chunk:
                break
            raw.write(chunk)
            raw.flush()
            if client_gone:
                continue
            try:
                view = memoryview(chunk)
                while view:
                    view = view[os.write(out_fd, view):]
            except OSError:
                client_gone = True  # keep recording what the server still writes
        # The server's stdout is closed: it exited (or crashed, e.g. #31). Close ours too
        # so the client sees EOF now instead of waiting out its whole call timeout.
        try:
            os.close(out_fd)
        except OSError:
            pass

    reader = threading.Thread(target=pump_stdout, daemon=True)
    reader.start()

    in_fd = sys.stdin.fileno()
    try:
        while True:
            chunk = os.read(in_fd, CHUNK)
            if not chunk:
                break
            child.stdin.write(chunk)
            child.stdin.flush()
    except OSError:
        pass
    try:
        child.stdin.close()
    except OSError:
        pass

    try:
        code = child.wait(timeout=EXIT_GRACE)
        status = {"exited": True, "returncode": code}
    except subprocess.TimeoutExpired:
        status = {"exited": False}
        child.kill()
        child.wait()
    reader.join(timeout=2)
    raw.close()
    (record / "exit.json").write_text(json.dumps(status), encoding="utf-8", newline="")
    return 0


if __name__ == "__main__":
    sys.exit(main())
