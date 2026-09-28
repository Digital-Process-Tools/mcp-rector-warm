"""End-to-end: the official Python MCP client drives bin/mcp-rector-warm over stdio,
exactly as an MCP host would -- spawn, initialize, tools/list, tools/call, close.

Each test copies tests/Fixtures/project to a temp dir and starts its own server, so
no test sees another's warm state and the fixture is never written to.
"""

from __future__ import annotations

import re
import shutil
from pathlib import Path

import anyio
import pytest
from mcp_types.version import HANDSHAKE_PROTOCOL_VERSIONS

from mcp_harness import (
    FIXTURE_PROJECT,
    NO_PCNTL_PLATFORM,
    REPO,
    exit_record,
    non_jsonrpc_lines,
    normalise,
    open_server,
    rector_report,
    stderr_tail,
    stdout_lines,
    structured,
)

# What mcp/sdk 0.7.x answers when the pinned client offers its latest handshake
# version. A bump on either side that changes this is worth a deliberate look.
EXPECTED_PROTOCOL = "2025-11-25"


@pytest.fixture
def project(tmp_path: Path) -> Path:
    dest = tmp_path / "project"
    shutil.copytree(FIXTURE_PROJECT, dest)
    return dest.resolve()


@pytest.fixture
def record(tmp_path: Path) -> Path:
    return tmp_path / "record"


def latest_released_version() -> str:
    # Independent oracle for server_info.version (#59): the bin script used
    # to hardcode a version string, and a test deriving its expectation from
    # that same literal would pass no matter how stale it was. CHANGELOG.md's
    # newest "## [x.y.z]" heading (never "## [Unreleased]") is what
    # ServerVersion.resolve() itself falls back to for a git checkout with no
    # reachable release tag, which is exactly this test's own environment.
    changelog = (REPO / "CHANGELOG.md").read_text(encoding="utf-8")
    match = re.search(r"^## \[(\d+\.\d+\.\d+)\]", changelog, re.MULTILINE)
    assert match, "could not find a released version heading in CHANGELOG.md"
    return match.group(1)


def test_initialize_negotiates_protocol_and_reports_server_info(project: Path, record: Path) -> None:
    async def scenario():
        async with open_server(project, record, project / "rector.php") as server:
            return server.init, server.session.protocol_version

    init, session_version = anyio.run(scenario)

    assert init.protocol_version in HANDSHAKE_PROTOCOL_VERSIONS
    assert init.protocol_version == EXPECTED_PROTOCOL
    assert session_version == init.protocol_version
    assert init.server_info.name == "mcp-rector-warm"
    assert init.server_info.version == latest_released_version()
    assert init.capabilities.tools is not None, "server must advertise the tools capability"


def test_tools_list_describes_rector_process(project: Path, record: Path) -> None:
    async def scenario():
        async with open_server(project, record, project / "rector.php") as server:
            return await server.session.list_tools()

    listing = anyio.run(scenario)

    assert [t.name for t in listing.tools] == ["rector_process"]
    tool = listing.tools[0]
    assert tool.description and "Rector" in tool.description

    schema = tool.input_schema
    assert schema["type"] == "object"
    assert schema["required"] == ["path"]
    assert schema["properties"]["path"]["type"] == "string"
    assert schema["properties"]["dryRun"]["type"] == "boolean"
    assert schema["properties"]["dryRun"]["default"] is True
    assert set(schema["properties"]) == {"path", "dryRun"}

    # The tool rewrites files when dryRun is false, so it must never claim to be
    # read-only, and the destructive/idempotent/open-world hints must be honest
    # (#21) rather than left to whatever the SDK defaults to.
    assert tool.annotations is not None
    assert tool.annotations.read_only_hint is False
    assert tool.annotations.destructive_hint is True
    assert tool.annotations.idempotent_hint is False
    assert tool.annotations.open_world_hint is False


def test_dry_run_returns_a_diff_and_leaves_the_file_alone(project: Path, record: Path) -> None:
    target = project / "src" / "Money.php"
    before = target.read_text()

    async def scenario():
        async with open_server(project, record, project / "rector.php") as server:
            return await server.process(target, dry_run=True)

    result = anyio.run(scenario)
    payload = structured(result)
    report = rector_report(payload["output"])

    assert payload["warm_boot"] is False, "first call of a session boots the container"
    assert report is not None, f"no Rector JSON report in: {payload}"
    # Positive control for the "file untouched" assertion below: Rector did find a change.
    assert report["totals"]["changed_files"] == 1
    assert report["changed_files"] == ["src/Money.php"]
    diff = report["file_diffs"][0]["diff"]
    assert "-final class Money" in diff
    assert "+final readonly class Money" in diff
    assert payload["exit_code"] == 2, "Rector exits 2 on a dry run that found changes"
    assert target.read_text() == before, "a dry run must not write the file"


def test_second_call_in_the_same_session_is_warm_and_agrees_with_the_first(project: Path, record: Path) -> None:
    target = project / "src" / "Money.php"

    async def scenario():
        async with open_server(project, record, project / "rector.php") as server:
            first = await server.process(target)
            second = await server.process(target)
            return structured(first), structured(second)

    first, second = anyio.run(scenario)

    assert first["warm_boot"] is False
    if NO_PCNTL_PLATFORM:
        assert second["warm_boot"] is False, "no pcntl on this platform (#97) -- every call must be a cold boot"
    else:
        assert second["warm_boot"] is True, "the second call must reuse the warm container"
    assert normalise(second, project) == normalise(first, project)
    assert normalise(second, project)["changed_files"] == ["src/Money.php"]


def test_a_different_second_file_in_a_warm_session_is_refactored(project: Path, record: Path) -> None:
    async def scenario():
        async with open_server(project, record, project / "rector.php") as server:
            first = await server.process(project / "src" / "Sample.php")
            second = await server.process(project / "src" / "Money.php")
            return structured(first), structured(second)

    first, second = anyio.run(scenario)

    assert normalise(first, project)["changed_files"] == []
    if NO_PCNTL_PLATFORM:
        assert second["warm_boot"] is False, "no pcntl on this platform (#97) -- every call must be a cold boot"
    else:
        assert second["warm_boot"] is True
    assert normalise(second, project)["changed_files"] == ["src/Money.php"]


def test_apply_rewrites_the_file(project: Path, record: Path) -> None:
    target = project / "src" / "Money.php"

    async def scenario():
        async with open_server(project, record, project / "rector.php") as server:
            return await server.process(target, dry_run=False)

    payload = structured(anyio.run(scenario))

    assert normalise(payload, project)["changed_files"] == ["src/Money.php"]
    assert "final readonly class Money" in target.read_text()


def test_path_outside_the_working_dir_is_refused(project: Path, record: Path, tmp_path: Path) -> None:
    outside = tmp_path / "Outside.php"
    outside.write_text((project / "src" / "Money.php").read_text())
    before = outside.read_text()

    async def scenario():
        async with open_server(project, record, project / "rector.php") as server:
            refused = await server.process(outside, dry_run=False)
            # Positive control: the same session still refactors a contained path,
            # so the refusal above is the containment check and not a dead server.
            allowed = await server.process(project / "src" / "Money.php")
            return structured(refused), structured(allowed)

    refused, allowed = anyio.run(scenario)

    assert refused["exit_code"] == -1
    assert refused["error_class"] == "SecurityError"
    assert "outside the configured working directory" in refused["error"]
    assert outside.read_text() == before, "a refused call must not write the file"
    assert normalise(allowed, project)["changed_files"] == ["src/Money.php"]


def test_a_failed_call_is_flagged_is_error(project: Path, record: Path) -> None:
    async def scenario():
        async with open_server(project, record, project / "rector.php") as server:
            return await server.process("/definitely/not/under/the/working/dir.php")

    result = anyio.run(scenario)

    assert structured(result)["exit_code"] == -1  # the call did fail...
    assert result.is_error is True  # ...and a host must be told so


def test_close_is_clean_and_stdout_carries_only_json_rpc(project: Path, record: Path) -> None:
    async def scenario():
        async with open_server(project, record, project / "rector.php") as server:
            await server.session.list_tools()
            await server.process(project / "src" / "Money.php")
            return server.transport_errors

    transport_errors = anyio.run(scenario)

    lines = stdout_lines(record)
    # Positive controls: the tap saw the traffic (initialize, tools/list, tools/call
    # responses), so "no stray line" below is a statement about real output.
    assert len(lines) >= 3, f"tap recorded too little stdout: {lines!r}"
    assert any('"serverInfo"' in line for line in lines)
    assert any('"rector_process"' in line for line in lines)
    assert non_jsonrpc_lines(lines) == []
    assert transport_errors == []

    status = exit_record(record)
    assert status is not None, "tap never recorded the server's exit"
    assert status == {"exited": True, "returncode": 0}, f"server did not exit cleanly on stdin EOF; stderr: {stderr_tail(record)}"
