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
from contextlib import asynccontextmanager
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, AsyncIterator

from mcp import ClientSession, StdioServerParameters
from mcp.client.stdio import stdio_client
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
) -> AsyncIterator[ServerRun]:
    """Launch bin/mcp-rector-warm over stdio and complete the initialize handshake.

    The server runs behind stdio_tap.py, which forwards bytes unchanged and keeps a
    copy of stdout; see stdio_tap.py for why.
    """
    record_dir.mkdir(parents=True, exist_ok=True)
    # php_ini: `-d key=value` for the SERVER process only (e.g. a short
    # default_socket_timeout, #32). The cold oracle runs with PHP defaults.
    ini_flags = [f"-d{key}={value}" for key, value in (php_ini or {}).items()]
    args = [str(TAP), str(record_dir), "--", php_binary(), *ini_flags, str(BIN), f"--working-dir={project}"]
    if config is not None:
        args.append(f"--config={config}")
    # call_timeout: --call-timeout=N on the SERVER only (#58), so a scenario can
    # pin a small deadline without waiting out the 600s production default.
    if call_timeout is not None:
        args.append(f"--call-timeout={call_timeout}")
    params = StdioServerParameters(command=sys.executable, args=args, cwd=str(project))

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
    return json.loads(path.read_text()) if path.exists() else None


def stderr_tail(record_dir: Path, limit: int = 2000) -> str:
    path = record_dir / "server.stderr"
    text = path.read_text(errors="replace") if path.exists() else ""
    return text[-limit:]


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
        p.relative_to(root).as_posix(): p.read_text(errors="replace")
        for p in sorted(root.rglob("*"))
        if p.is_file()
    }
