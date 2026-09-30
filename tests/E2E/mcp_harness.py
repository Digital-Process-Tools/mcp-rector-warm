"""Shared plumbing for the end-to-end suite: launch the server behind the official
Python MCP client, run a cold Rector for the differential oracle, and read what the
server wrote to stdout.

Nothing here talks JSON-RPC by hand. Every request goes through mcp.ClientSession
over mcp.client.stdio.stdio_client, the same path an MCP host takes.
"""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import time
from contextlib import asynccontextmanager
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, AsyncIterator

import pytest
from mcp import ClientSession, StdioServerParameters
from mcp.client.stdio import get_default_environment, stdio_client
from mcp.types import Implementation

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
BIN = REPO / "bin" / "mcp-rector-warm"
RECTOR = REPO / "vendor" / "bin" / "rector"
TAP = HERE / "stdio_tap.py"
FIXTURE_PROJECT = REPO / "tests" / "Fixtures" / "project"

# The flags RectorTool::process() hands Rector. The cold oracle uses the same ones,
# so warm and cold differ in exactly one thing: the process is fresh.
RECTOR_FLAGS = ["process", "--output-format=json", "--debug", "--no-progress-bar"]

CALL_TIMEOUT = float(os.environ.get("E2E_CALL_TIMEOUT", "180"))

# #97/#31: PHP ships with no pcntl extension on Windows at all -- RectorRunner's own
# canFork() (function_exists('pcntl_fork') etc.) is therefore always False there, so
# run() always takes the runCold() branch and reports warm_boot=False for EVERY call
# in a session, never True, no matter how many calls share the session or whether the
# config changed (see RectorRunner's own class docblock). A warm_boot assertion that
# does not account for this fails on windows-latest not because the mechanism is
# broken, but because it is doing exactly what it is documented to do. Folding this
# in here (rather than skipping the assertion outright) keeps it a real check on
# Windows too: it still pins that every call actually goes cold there.
# E2E_DISABLE_PCNTL=1 (#108) runs the SERVER with pcntl disabled through php.ini --
# the same disable_functions list the CI no-pcntl job uses -- so the no-pcntl path can
# be exercised on a POSIX host, not only on windows-latest. The cold oracle is not
# affected: it never forks either way.
DISABLE_PCNTL = os.environ.get("E2E_DISABLE_PCNTL") == "1"
NO_PCNTL_INI = ["-ddisable_functions=pcntl_fork,pcntl_waitpid,stream_socket_pair"] if DISABLE_PCNTL else []
NO_PCNTL_PLATFORM = sys.platform.startswith("win") or DISABLE_PCNTL

# #108: without pcntl the server is warm too now (a pre-booted standby worker process
# per call), so warm_boot follows the same rules as on a pcntl platform. Only the
# escape hatch MCP_RECTOR_WARM_NO_PCNTL=cold (inherited by the server from this
# environment) brings back "every call is a cold boot".
EXPECT_COLD_EVERY_CALL = NO_PCNTL_PLATFORM and os.environ.get("MCP_RECTOR_WARM_NO_PCNTL", "").strip().lower() == "cold"

# #185: with pcntl, analysis calls are served by one long-lived session child
# (RectorRunner::serveSession()) unless MCP_RECTOR_WARM_SESSION=0 turns it off.
# Without pcntl there is no session at all (the #108 standby worker per call).
# Running the whole suite once with MCP_RECTOR_WARM_SESSION=0 compares the old
# fork-per-call path against the same cold oracle.
SESSION_OPT_OUT = os.environ.get("MCP_RECTOR_WARM_SESSION", "").strip().lower() in {"0", "off", "false", "no"}
SESSION_EXPECTED = not NO_PCNTL_PLATFORM and not SESSION_OPT_OUT

# The server's own debug log of what served each call (RectorRunner::sessionLog()).
SESSION_LOG_ENV = {"MCP_RECTOR_WARM_SESSION_LOG": "1"}
SESSION_LOG_PREFIX = "mcp-rector-warm: session "
SESSION_TERMINAL_EVENTS = {"serve", "fork", "decline"}


def php_binary() -> str:
    """The PHP interpreter. Invoked explicitly rather than through the bin's
    shebang, so the suite does not depend on `php` being first on PATH."""
    php = os.environ.get("PHP_BINARY") or shutil.which("php")
    if not php:
        raise RuntimeError("no PHP interpreter: put php on PATH or set PHP_BINARY")
    return php


@dataclass
class ServerRun:
    """One live server session plus what the tap recorded about it."""

    session: ClientSession
    init: Any
    record_dir: Path
    transport_errors: list[Exception] = field(default_factory=list)

    async def process(self, path: str | Path, dry_run: bool = True) -> Any:
        return await self.session.call_tool(
            "rector_process",
            {"path": str(path), "dryRun": dry_run},
            read_timeout_seconds=CALL_TIMEOUT,
        )


@asynccontextmanager
async def open_server(
    project: Path,
    record_dir: Path,
    config: Path | None = None,
    php_ini: dict[str, Any] | None = None,
    call_timeout: int | None = None,
    extra_env: dict[str, str] | None = None,
) -> AsyncIterator[ServerRun]:
    """Launch bin/mcp-rector-warm over stdio and complete the initialize handshake.

    The server runs behind stdio_tap.py, which forwards bytes unchanged and keeps a
    copy of stdout; see stdio_tap.py for why.
    """
    record_dir.mkdir(parents=True, exist_ok=True)
    # php_ini: `-d key=value` for the SERVER process only (e.g. a short
    # default_socket_timeout, #32). The cold oracle runs with PHP defaults.
    ini_flags = [f"-d{key}={value}" for key, value in (php_ini or {}).items()]
    args = [str(TAP), str(record_dir), "--", php_binary(), *NO_PCNTL_INI, *ini_flags, str(BIN), f"--working-dir={project}"]
    if config is not None:
        args.append(f"--config={config}")
    # call_timeout: --call-timeout=N on the SERVER only (#58), so a scenario can
    # pin a small deadline without waiting out the 600s production default.
    if call_timeout is not None:
        args.append(f"--call-timeout={call_timeout}")
    # The SDK hands the server only a short whitelist of the environment (HOME, PATH,
    # ...) unless told otherwise, so the #108 strategy switch has to be forwarded
    # explicitly, or MCP_RECTOR_WARM_NO_PCNTL set for this suite never reaches it.
    # Same for #185's session switch, and for any variable a test asks for.
    forwarded = {name: os.environ[name] for name in ("MCP_RECTOR_WARM_NO_PCNTL", "MCP_RECTOR_WARM_SESSION") if name in os.environ}
    forwarded.update(extra_env or {})
    env = {**get_default_environment(), **forwarded} if forwarded else None
    params = StdioServerParameters(command=sys.executable, args=args, cwd=str(project), env=env)

    transport_errors: list[Exception] = []

    async def on_message(message: Any) -> None:
        if isinstance(message, Exception):
            transport_errors.append(message)

    with open(record_dir / "server.stderr", "w", encoding="utf-8") as errlog:
        async with stdio_client(params, errlog=errlog) as (read, write):
            async with ClientSession(
                read,
                write,
                message_handler=on_message,
                client_info=Implementation(name="mcp-rector-warm-e2e", version="1.0.0"),
            ) as session:
                init = await session.initialize()
                yield ServerRun(session, init, record_dir, transport_errors)


# --------------------------------------------------------------------------- stdout

def stdout_lines(record_dir: Path) -> list[str]:
    raw = (record_dir / "stdout.raw").read_bytes()
    return [line for line in raw.decode("utf-8", "replace").split("\n") if line.strip() != ""]


def non_jsonrpc_lines(lines: list[str]) -> list[str]:
    """Lines that are not a JSON-RPC 2.0 message: any of these corrupts a stdio host."""
    bad = []
    for line in lines:
        try:
            message = json.loads(line)
        except ValueError:
            bad.append(line)
            continue
        if not isinstance(message, dict) or message.get("jsonrpc") != "2.0":
            bad.append(line)
    return bad


def exit_record(record_dir: Path) -> dict[str, Any] | None:
    path = record_dir / "exit.json"
    return json.loads(path.read_bytes().decode("utf-8")) if path.exists() else None


def stderr_tail(record_dir: Path, limit: int = 2000) -> str:
    path = record_dir / "server.stderr"
    text = path.read_bytes().decode("utf-8", errors="replace") if path.exists() else ""
    return text[-limit:]


def stderr_size(record_dir: Path) -> int:
    path = record_dir / "server.stderr"
    return path.stat().st_size if path.exists() else 0


def session_events(record_dir: Path, since: int, *, wait_for_terminal: bool, timeout: float = 5.0) -> list[tuple[str, str]]:
    """#185: the (event, detail) pairs the server logged since byte offset `since`
    of its stderr. The log reaches server.stderr through the MCP client's own
    stderr pump, which can lag the tool result by a moment, so when a call is
    known to have been routed (wait_for_terminal) this polls until the call's
    terminal event (serve / fork / decline) shows up or `timeout` passes."""
    deadline = time.monotonic() + timeout
    while True:
        path = record_dir / "server.stderr"
        raw = path.read_bytes()[since:] if path.exists() else b""
        events = []
        for line in raw.decode("utf-8", "replace").splitlines():
            if line.startswith(SESSION_LOG_PREFIX):
                event, _, detail = line[len(SESSION_LOG_PREFIX):].partition(":")
                events.append((event.strip(), detail.strip()))
        if not wait_for_terminal or any(e in SESSION_TERMINAL_EVENTS for e, _ in events) or time.monotonic() >= deadline:
            return events
        time.sleep(0.05)


# ----------------------------------------------------------------- process tree

def daemon_pid(record_dir: Path) -> int:
    """The PHP daemon's own OS pid -- stdio_tap.py spawns it directly (see
    stdio_tap.py's own docstring: `command` IS `php ... bin/mcp-rector-warm ...`,
    not a shell wrapping it), and records it verbatim in started.json the
    moment it starts. This is the pid every RectorRunner::boot()/forkAndExecute()
    fork happens underneath -- the root #48's "list the daemon's children"
    check needs."""
    data = json.loads((record_dir / "started.json").read_bytes().decode("utf-8"))
    return int(data["pid"])


def process_descendants(root_pid: int) -> list[tuple[int, int, str]]:
    """Every OS process descended from root_pid (not including root_pid itself),
    as (pid, ppid, stat) triples -- `stat` is `ps`'s own state column, e.g. 'S',
    'R+', or 'Z'/'Z+' for a zombie (#48: "list the daemon's children after the
    call... a <defunct> entry confirms it"). One `ps -eo pid,ppid,stat` snapshot
    (not `ps --ppid`, which some `ps` builds refuse for a pid with no children at
    all rather than returning empty) walked as a tree from root_pid, POSIX `ps`
    output, portable across the ubuntu-latest runners this repo's CI used to
    run exclusively on (#79). #97/#98 self-review correction: CI now also has
    a macos-latest leg (see below) and a windows-latest leg (see the skip
    guard below) -- this sentence used to say CI never ran anything but
    ubuntu-latest, which the workflow this diff ships makes false.

    #79 follow-up, observed rather than reasoned: `ps -eo pid,ppid,stat` was run
    directly against a real macOS (Darwin/BSD `ps`) process tree, including one
    deliberately left as a zombie (a fork()ed child that exited without being
    wait()ed on by its parent). BSD `ps` accepts the same `-eo pid,ppid,stat` flag
    combination and produces the same three left-to-right columns; the only
    difference observed is that its STAT column is fixed-width and carries
    trailing padding spaces (e.g. `"Z   "` instead of procps-ng's bare `"Z"`).
    `line.split(None, 2)` still yields exactly 3 parts (maxsplit=2 leaves the
    padding inside parts[2] rather than splitting on it), and `"Z" in d[2]`
    membership checks below are unaffected by trailing whitespace, so zombie
    detection was confirmed working on this platform. This does not extend to
    every BSD/macOS `ps` build or flag ordering, and only one real BSD-family
    `ps` has been checked by hand, not merely assumed. #98 adds a real
    macos-latest CI leg that now exercises this for real on every run; its
    own first execution there (reasoned, not yet observed, as of this diff)
    is what settles whether a GitHub-hosted macOS runner's `ps` build matches
    what was checked by hand above.

    #97: Windows has no `ps` at all (and no pcntl/fork either -- the daemon
    never has a forked descendant to look for there in the first place, see
    RectorRunner's own class docblock), so this is a named skip rather than
    letting a bare `FileNotFoundError` from spawning "ps" stand in for a
    result -- a crash there would abort the whole test, not read as "no
    descendants found", so it cannot silently pass either.
    """
    if sys.platform.startswith("win"):
        pytest.skip("ps -eo pid,ppid,stat has no Windows equivalent this harness parses (#79, #97)")
    proc = subprocess.run(["ps", "-eo", "pid,ppid,stat"], capture_output=True, text=True, timeout=10)
    # A failed `ps` invocation (nonzero exit, e.g. a sandboxed/restricted
    # environment) must never be read as "the daemon has no descendants" --
    # self-review caught that an unchecked returncode makes exactly that
    # mistake: empty/header-only stdout parses to an empty list either way, so
    # "checked and clean" and "couldn't check, said clean anyway" would be
    # indistinguishable to every caller of assert_no_zombie_descendants().
    if proc.returncode != 0:
        raise RuntimeError(
            f"ps -eo pid,ppid,stat exited {proc.returncode}, cannot determine the daemon's process tree: "
            f"stderr={proc.stderr!r}"
        )
    by_ppid: dict[int, list[tuple[int, int, str]]] = {}
    for line in proc.stdout.splitlines()[1:]:
        parts = line.split(None, 2)
        if len(parts) != 3:
            continue
        try:
            pid, ppid = int(parts[0]), int(parts[1])
        except ValueError:
            continue
        by_ppid.setdefault(ppid, []).append((pid, ppid, parts[2]))

    descendants: list[tuple[int, int, str]] = []
    seen: set[int] = set()
    frontier = [root_pid]
    while frontier:
        current = frontier.pop()
        for row in by_ppid.get(current, []):
            if row[0] in seen:
                continue
            seen.add(row[0])
            descendants.append(row)
            frontier.append(row[0])
    return descendants


def assert_no_zombie_descendants(record_dir: Path, *, max_live: int | None = None) -> list[tuple[int, int, str]]:
    """#48's own settling criterion, run for real: snapshot the daemon's process
    tree and fail if any descendant is a zombie ('Z' anywhere in its `ps` STAT
    column -- 'Z' alone or 'Z+', a foreground zombie). When max_live is given,
    also fail if MORE than that many non-zombie descendants remain -- an
    orphaned-but-still-running grandchild (reparented, not reaped, so it would
    not show up as a zombie at all) is exactly the narrower gap the #58 trap.d
    notes describe for the daemon-side backstop path, and a live process count
    over budget is the only way this harness can see it. Returns the live
    (non-zombie) descendants for the caller to log or assert further on.
    """
    pid = daemon_pid(record_dir)
    descendants = process_descendants(pid)
    zombies = [d for d in descendants if "Z" in d[2]]
    assert not zombies, (
        f"daemon (pid {pid}) has {len(zombies)} zombie/defunct descendant(s) after the call: {zombies} "
        f"-- full descendant list: {descendants}"
    )
    # The assert above only returns control when zombies == [], so descendants
    # IS the live list by this point -- no separate filter needed (self-review
    # caught the earlier version computing this redundantly).
    live = descendants
    if max_live is not None:
        assert len(live) <= max_live, (
            f"daemon (pid {pid}) has {len(live)} live descendant(s), expected at most {max_live} -- "
            f"an orphaned grandchild the daemon lost track of would show up here without ever being "
            f"a zombie: {live}"
        )
    return live


# --------------------------------------------------------------------------- results

def structured(result: Any) -> dict[str, Any]:
    """The tool's structured payload, falling back to the JSON text block."""
    if result.structured_content:
        return dict(result.structured_content)
    for block in result.content:
        if getattr(block, "type", None) == "text":
            return json.loads(block.text)
    raise AssertionError(f"tool result carries neither structuredContent nor a text block: {result!r}")


def rector_report(output: str) -> dict[str, Any] | None:
    """Rector's JSON report out of a tool/CLI output, skipping any text before it.

    Anything Rector prints before the JSON (e.g. the no-config warning, #14) is
    ignored here on purpose: this function reads the result; stdout cleanliness is
    asserted separately, from the tap's raw copy.
    """
    decoder = json.JSONDecoder()
    start = output.find("{")
    while start != -1:
        try:
            report, _ = decoder.raw_decode(output, start)
        except ValueError:
            start = output.find("{", start + 1)
            continue
        if isinstance(report, dict) and "totals" in report:
            return report
        start = output.find("{", start + 1)
    return None


def normalise(payload: dict[str, Any], root: Path) -> dict[str, Any]:
    """Reduce a result to what warm and cold must agree on, with the tree root
    replaced so two copies of the same tree compare equal."""
    if payload.get("error"):
        return {"exception": payload.get("error_class"), "message": payload.get("error")}
    report = rector_report(payload.get("output", ""))
    if report is None:
        # No JSON report (e.g. Rector stopped at the no-config warning). The text is
        # not compared: where it went differs by design between a CLI (its stdout)
        # and the server (captured, or leaked onto the MCP stdout -- see #14).
        return {"exit_code": payload.get("exit_code"), "report": None}

    prefixes = sorted({str(root), os.path.realpath(root)}, key=len, reverse=True)

    def scrub(text: str) -> str:
        for prefix in prefixes:
            text = text.replace(prefix + os.sep, "").replace(prefix, "<ROOT>")
        return text

    errors = []
    for err in report.get("errors", []) or []:
        if isinstance(err, dict):
            errors.append({k: scrub(str(v)) for k, v in sorted(err.items())})
        else:
            errors.append(scrub(str(err)))
    return {
        "exit_code": payload.get("exit_code"),
        "changed_files": sorted(scrub(f) for f in report.get("changed_files", []) or []),
        "diffs": {scrub(d["file"]): d.get("diff", "") for d in report.get("file_diffs", []) or []},
        "errors": errors,
    }


def run_cold(tree: Path, rel_path: str, dry_run: bool) -> dict[str, Any]:
    """A fresh `vendor/bin/rector process` on `tree`: the oracle's ground truth."""
    argv = [php_binary(), str(RECTOR), *RECTOR_FLAGS]
    if dry_run:
        argv.append("--dry-run")
    argv.append(str(tree / rel_path))
    done = subprocess.run(argv, cwd=tree, capture_output=True, text=True, timeout=CALL_TIMEOUT)
    return {"exit_code": done.returncode, "output": done.stdout, "stderr": done.stderr}


def tree_contents(root: Path) -> dict[str, str]:
    return {
        p.relative_to(root).as_posix(): p.read_bytes().decode("utf-8", errors="replace")
        for p in sorted(root.rglob("*"))
        if p.is_file()
    }
