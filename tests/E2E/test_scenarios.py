r"""Data-driven warm-session scenarios with a differential oracle.

Every file in scenarios/ is one test, named after the file. A scenario builds a fake
codebase in a temp dir, starts ONE server on it (one warm session), and runs its
steps in order: filesystem edits, and 'call' steps that invoke rector_process
through the official MCP client.

The oracle needs no hand-written expected diff. Before each in-tree call the tree is
copied as it stands, and after the warm call a fresh 'vendor/bin/rector process'
runs on that copy with the same flags. The warm answer must equal the cold one:
exit code, changed files, per-file diffs, errors -- and, when the call applies
changes, the resulting file contents. An optional 'expect' block pins extra facts.

Scenario format (YAML) -- see CONTRIBUTING.md for a worked example:

  description: str                 # required, one line
  xfail: "#16"                     # optional: a filed issue this scenario hits (strict)
  fixture: tests/Fixtures/project  # optional: dir (repo-relative) copied in first
  config: <rector.php source>      # optional; null = no rector.php; omitted = the
                                   #   fixture's own, else DEFAULT_CONFIG below
  files: {rel/path.php: source}    # optional: files written on top
  steps:                           # required, run in order
    - write:  {path: rel, content: source}       # create or replace
    - edit:   {path: rel, old: text, new: text}  # 'old' must occur exactly once
    - delete: rel
    - rename: {from: rel, to: rel}
    - call: rel                    # rector_process on tree/rel (a file or a dir)
      dry_run: false               # optional, default true
      expect:                      # optional, all keys optional
        changed: 1                 # number of changed files
        changed_files: [src/A.php]
        diff_contains: '+#[\Override]'  # str or list, searched in all diffs
        diff_excludes: '...'       # str or list
        is_error: false            # the MCP isError flag
        error_class: SecurityError # the tool's error_class
    - call: /abs/path              # an absolute path is outside the tree: no oracle

Checked on every scenario, whatever its steps: every call after the first in-tree one
reports warm_boot = true (so the oracle compares a warm container, not a reboot),
stdout carries nothing but JSON-RPC, and the server exits 0 when the client closes.
"""

from __future__ import annotations

import os
import shutil
import time
from pathlib import Path
from typing import Any

import anyio
import pytest
import yaml

from mcp_harness import (
    REPO,
    exit_record,
    non_jsonrpc_lines,
    normalise,
    open_server,
    run_cold,
    stderr_tail,
    stdout_lines,
    structured,
    tree_contents,
)

SCENARIO_DIR = Path(__file__).resolve().parent / "scenarios"

DEFAULT_CONFIG = r"""<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\ValueObject\PhpVersion;

// Two rules, pinned rather than a whole set, so a Rector upgrade that grows a set
// cannot move a scenario's baseline. AddOverride reads the PARENT class (and the
// traits it uses), so it is the probe for stale reflection across edits.
// withAutoloadPaths stands in for a project's composer autoloader: without it, a
// single-file run cannot see a parent declared in another file, cold or warm.
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withAutoloadPaths([__DIR__ . '/src'])
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withRules([
        ReadOnlyClassRector::class,
        AddOverrideAttributeToOverriddenMethodsRector::class,
    ]);
"""

TOP_KEYS = {"description", "xfail", "fixture", "config", "files", "steps"}
STEP_KINDS = {"write", "edit", "delete", "rename", "call"}
CALL_KEYS = {"call", "dry_run", "expect"}
EXPECT_KEYS = {"changed", "changed_files", "diff_contains", "diff_excludes", "is_error", "error_class"}


def load(path: Path) -> dict[str, Any]:
    """Parse and validate one scenario. A typo in a key fails collection loudly
    rather than silently testing less than the file says."""
    data = yaml.safe_load(path.read_text())
    where = path.name
    if not isinstance(data, dict):
        raise ValueError(f"{where}: top level must be a mapping")
    unknown = set(data) - TOP_KEYS
    if unknown:
        raise ValueError(f"{where}: unknown keys {sorted(unknown)} (allowed: {sorted(TOP_KEYS)})")
    if not data.get("description"):
        raise ValueError(f"{where}: 'description' is required")
    if not isinstance(data.get("steps"), list) or not data["steps"]:
        raise ValueError(f"{where}: 'steps' must be a non-empty list")
    for i, step in enumerate(data["steps"]):
        kinds = STEP_KINDS & set(step)
        if len(kinds) != 1:
            raise ValueError(f"{where} step {i}: exactly one of {sorted(STEP_KINDS)} expected, got {sorted(step)}")
        if "call" in step:
            extra = set(step) - CALL_KEYS
            if extra:
                raise ValueError(f"{where} step {i}: unknown call keys {sorted(extra)}")
            extra = set(step.get("expect") or {}) - EXPECT_KEYS
            if extra:
                raise ValueError(f"{where} step {i}: unknown expect keys {sorted(extra)}")
        elif len(step) != 1:
            raise ValueError(f"{where} step {i}: a '{kinds.pop()}' step takes no sibling keys")
    if not any("call" in step for step in data["steps"]):
        raise ValueError(f"{where}: a scenario without a 'call' step tests nothing")
    return data


def collect() -> list[Any]:
    params = []
    for path in sorted(SCENARIO_DIR.glob("*.yaml")):
        data = load(path)
        marks = []
        if data.get("xfail"):
            marks.append(pytest.mark.xfail(strict=True, reason=str(data["xfail"])))
        params.append(pytest.param(path, data, id=path.stem, marks=marks))
    return params


class Tree:
    """The warm tree. Every write moves the file's mtime strictly forward, so a
    staleness check keyed on mtime is never fooled by a same-second edit -- a
    divergence is then about content, not about the clock."""

    def __init__(self, root: Path) -> None:
        self.root = root
        self.clock = time.time()

    def path(self, rel: str) -> Path:
        target = (self.root / rel).resolve()
        if self.root not in target.parents:
            raise ValueError(f"path escapes the scenario tree: {rel}")
        return target

    def touch_forward(self, target: Path) -> None:
        self.clock += 10
        os.utime(target, (self.clock, self.clock))

    def write(self, rel: str, content: str) -> None:
        target = self.path(rel)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content)
        self.touch_forward(target)

    def edit(self, rel: str, old: str, new: str) -> None:
        text = self.path(rel).read_text()
        count = text.count(old)
        if count != 1:
            raise ValueError(f"edit {rel}: 'old' occurs {count} times, expected exactly once: {old!r}")
        self.write(rel, text.replace(old, new))

    def delete(self, rel: str) -> None:
        self.path(rel).unlink()

    def rename(self, src: str, dst: str) -> None:
        target = self.path(dst)
        target.parent.mkdir(parents=True, exist_ok=True)
        self.path(src).rename(target)
        self.touch_forward(target)


def build_tree(root: Path, data: dict[str, Any]) -> Tree:
    if data.get("fixture"):
        shutil.copytree(REPO / data["fixture"], root)
    else:
        root.mkdir(parents=True)
    tree = Tree(root.resolve())
    if "config" in data:
        if data["config"] is not None:
            tree.write("rector.php", data["config"])
        elif (tree.root / "rector.php").exists():
            tree.delete("rector.php")
    elif not (tree.root / "rector.php").exists():
        tree.write("rector.php", DEFAULT_CONFIG)  # a fixture keeps its own rector.php
    for rel, content in (data.get("files") or {}).items():
        tree.write(rel, content)
    return tree


def as_list(value: Any) -> list[str]:
    return [value] if isinstance(value, str) else list(value)


async def call_step(server: Any, step: dict[str, Any], cold_tree: Path | None, warm_root: Path, first_call: bool, where: str) -> None:
    """One rector_process call, checked against the cold oracle and the step's 'expect'."""
    rel = step["call"]
    dry_run = step.get("dry_run", True)

    result = await server.process(rel if cold_tree is None else warm_root / rel, dry_run=dry_run)
    payload = structured(result)
    warm = normalise(payload, warm_root)

    if cold_tree is not None:
        if not first_call:
            assert payload.get("warm_boot") is True, f"{where}: expected a warm container, got a cold boot: {payload}"
        cold = normalise(run_cold(cold_tree, rel, dry_run), cold_tree)
        assert warm == cold, (
            f"{where}: warm MCP result differs from a cold 'rector process' on the same tree\n"
            f"  warm: {warm}\n  cold: {cold}\n  server stderr: {stderr_tail(server.record_dir)}"
        )
        if not dry_run:
            assert tree_contents(warm_root) == tree_contents(cold_tree), f"{where}: applied files differ warm vs cold"

    expect = step.get("expect") or {}
    diffs = "\n".join((warm.get("diffs") or {}).values())
    if "changed" in expect:
        assert len(warm.get("changed_files", [])) == expect["changed"], f"{where}: {warm}"
    if "changed_files" in expect:
        assert warm.get("changed_files") == sorted(expect["changed_files"]), f"{where}: {warm}"
    for needle in as_list(expect.get("diff_contains", [])):
        assert needle in diffs, f"{where}: diff lacks {needle!r}; diffs: {diffs!r}"
    for needle in as_list(expect.get("diff_excludes", [])):
        # Positive control: an absent needle only means something in a diff that exists.
        assert diffs, f"{where}: diff_excludes needs a non-empty diff to look in; got {warm}"
        assert needle not in diffs, f"{where}: diff carries {needle!r}; diffs: {diffs!r}"
    if "error_class" in expect:
        assert payload.get("error_class") == expect["error_class"], f"{where}: {payload}"
    if "is_error" in expect:
        assert bool(result.is_error) is expect["is_error"], f"{where}: isError={result.is_error}, payload {payload}"


@pytest.mark.parametrize(("scenario_file", "data"), collect())
def test_scenario(scenario_file: Path, data: dict[str, Any], tmp_path: Path) -> None:
    tree = build_tree(tmp_path / "warm", data)
    record = tmp_path / "record"
    config = tree.root / "rector.php"
    call_steps = [step for step in data["steps"] if "call" in step]

    async def run() -> tuple[int, list[Exception]]:
        calls_made = 0
        in_tree_calls = 0
        async with open_server(tree.root, record, config if config.exists() else None) as server:
            for i, step in enumerate(data["steps"]):
                where = f"{scenario_file.name} step {i}"
                if "write" in step:
                    tree.write(step["write"]["path"], step["write"]["content"])
                elif "edit" in step:
                    tree.edit(step["edit"]["path"], step["edit"]["old"], step["edit"]["new"])
                elif "delete" in step:
                    tree.delete(step["delete"])
                elif "rename" in step:
                    tree.rename(step["rename"]["from"], step["rename"]["to"])
                else:
                    cold_tree = None
                    if not os.path.isabs(step["call"]):
                        # Snapshot BEFORE the warm call: an applying call rewrites the warm tree.
                        cold_tree = tmp_path / f"cold-{i}"
                        shutil.copytree(tree.root, cold_tree)
                        cold_tree = cold_tree.resolve()
                    await call_step(server, step, cold_tree, tree.root, in_tree_calls == 0, where)
                    calls_made += 1
                    in_tree_calls += cold_tree is not None
            return calls_made, server.transport_errors

    calls_made, transport_errors = anyio.run(run)

    # Session-wide checks. Positive controls first: every call ran, and the tap saw
    # the traffic, so "no stray line" is a statement about real output.
    assert calls_made == len(call_steps)
    lines = stdout_lines(record)
    assert len(lines) >= 1 + calls_made, f"tap recorded too little stdout: {lines!r}"
    assert non_jsonrpc_lines(lines) == [], f"non-JSON-RPC bytes on stdout: {non_jsonrpc_lines(lines)!r}"
    assert transport_errors == [], f"the MCP client hit transport errors: {transport_errors!r}"
    status = exit_record(record)
    assert status == {"exited": True, "returncode": 0}, f"server did not exit cleanly: {status}; stderr: {stderr_tail(record)}"
