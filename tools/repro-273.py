#!/usr/bin/env python3
"""
Deterministic-repro harness for claude-supertool #273.

Spawns ONE warm mcp-rector-warm server and feeds it a long sequence of real
project files over a single connection, hunting for the first `System error:`
— the warm-only ClassReflection failure (a stale AggregateSourceLocator from an
earlier file poisoning a later one). On a hit it cold-checks the same file
(fresh server, single call) to prove the failure is warm-only, not file-local.

The trigger needs real framework base classes (e.g. a test-case base) extended
from files outside the configured Rector paths; trivial synthetic classes
resolve cleanly. Point <working_dir> at such a project (e.g. a DVSI checkout)
and feed its *Test.php files.

Usage: repro-273.py <bin> <working_dir> <config> <max_files> [start]
File list on stdin (one path per line, relative to working_dir).
"""
import json
import os
import subprocess
import sys
import time


def spawn(bin_path, working_dir, config):
    proc = subprocess.Popen(
        [bin_path, "--working-dir=" + working_dir, "--config=" + config],
        cwd=working_dir,
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        bufsize=0,
    )
    return proc


def send(proc, msg):
    proc.stdin.write((json.dumps(msg) + "\n").encode())
    proc.stdin.flush()


def read_id(proc, want_id, timeout=120):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        line = proc.stdout.readline()
        if not line:
            return None
        line = line.strip()
        if not line or line[:1] != b"{":
            continue
        try:
            d = json.loads(line)
        except Exception:
            continue
        if d.get("id") == want_id:
            return d
    return None


def init(proc):
    send(proc, {"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {
        "protocolVersion": "2024-11-05", "capabilities": {},
        "clientInfo": {"name": "repro", "version": "1.0.0"}}})
    send(proc, {"jsonrpc": "2.0", "method": "notifications/initialized"})
    read_id(proc, 1)


def call(proc, mid, abs_path):
    send(proc, {"jsonrpc": "2.0", "id": mid, "method": "tools/call", "params": {
        "name": "rector_process", "arguments": {"path": abs_path, "dryRun": True}}})
    return read_id(proc, mid)


def structured(resp):
    if not resp:
        return {}
    return (resp.get("result", {}) or {}).get("structuredContent", {}) or {}


def has_system_error(sc):
    blob = json.dumps(sc)
    return "System error" in blob or "must be resolved" in blob


def main():
    bin_path, working_dir, config, max_files = sys.argv[1:5]
    start = int(sys.argv[5]) if len(sys.argv) > 5 else 0
    max_files = int(max_files)
    files = [l.strip() for l in sys.stdin if l.strip()][start:start + max_files]

    proc = spawn(bin_path, working_dir, config)
    init(proc)
    print(f"warm server up, feeding {len(files)} files (start={start})", flush=True)

    hit = None
    mid = 1
    for i, rel in enumerate(files):
        mid += 1
        ap = os.path.join(working_dir, rel)
        if not os.path.isfile(ap):
            continue
        resp = call(proc, mid, ap)
        sc = structured(resp)
        if resp is None:
            print(f"[{i}] NO RESPONSE on {rel} (server died?)", flush=True)
            print((proc.stderr.read() or b"")[-2000:].decode(errors="replace"), flush=True)
            break
        if has_system_error(sc):
            hit = (i, rel, sc)
            print(f"\n*** HIT at #{i} (warm_boot={sc.get('warm_boot')}): {rel}", flush=True)
            print(json.dumps(sc, indent=2)[:3000], flush=True)
            break
        if i % 50 == 0:
            print(f"[{i}] ok warm={sc.get('warm_boot')} exit={sc.get('exit_code')} {rel}", flush=True)
    try:
        proc.stdin.close()
        proc.terminate()
    except Exception:
        pass

    if not hit:
        print("\nNo System error across the fed sequence.", flush=True)
        return 0

    # Cold-confirm: fresh server, single call on the culprit file.
    i, rel, _ = hit
    ap = os.path.join(working_dir, rel)
    cold = spawn(bin_path, working_dir, config)
    init(cold)
    csc = structured(call(cold, 2, ap))
    try:
        cold.stdin.close()
        cold.terminate()
    except Exception:
        pass
    print(f"\n=== COLD check on culprit {rel} ===", flush=True)
    print(f"cold warm_boot={csc.get('warm_boot')} exit={csc.get('exit_code')} "
          f"system_error={has_system_error(csc)}", flush=True)
    if not has_system_error(csc):
        print(">>> CONFIRMED WARM-ONLY: fails warm, clean cold. Deterministic repro found.", flush=True)
    else:
        print(">>> Fails cold too — not warm-specific; keep looking.", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
