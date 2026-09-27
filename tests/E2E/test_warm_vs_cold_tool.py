"""tools/warm-vs-cold.py: pure helpers, then one real end-to-end smoke run.

The helper tests need no PHP and no MCP client -- they pin the sampling and
formatting logic the rest of the tool depends on. The smoke test is the real
thing: it runs the actual script as a subprocess against tests/Fixtures/project
and checks it reports a clean match, the same way a human would run it against
a real project (see CONTRIBUTING.md, "Checking against a real project").
"""

from __future__ import annotations

import argparse
import importlib.util
import json
import subprocess
import sys
from pathlib import Path

from mcp_harness import FIXTURE_PROJECT, REPO, php_binary

TOOL = REPO / "tools" / "warm-vs-cold.py"

_spec = importlib.util.spec_from_file_location("warm_vs_cold", TOOL)
warm_vs_cold = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(warm_vs_cold)


# --------------------------------------------------------------------------- php_str

def test_php_str_quotes_and_escapes():
    assert warm_vs_cold.php_str("plain") == "'plain'"
    assert warm_vs_cold.php_str("a'b") == "'a\\'b'"
    assert warm_vs_cold.php_str("a\\b") == "'a\\\\b'"
    # No interpolation: a literal $ or {} must survive untouched inside the quotes.
    assert warm_vs_cold.php_str("$x{y}") == "'$x{y}'"


# --------------------------------------------------------------------------- select_files

def test_select_files_glob(tmp_path):
    (tmp_path / "src").mkdir()
    (tmp_path / "src" / "A.php").write_text("<?php")
    (tmp_path / "src" / "B.txt").write_text("not php")
    files, candidates = warm_vs_cold.select_files(tmp_path, ["src/*"], None, seed=1)
    assert candidates == 1
    assert [f.name for f in files] == ["A.php"]


def test_select_files_limit_is_deterministic_and_bounded(tmp_path):
    for d in "abc":
        (tmp_path / d).mkdir()
        for i in range(5):
            (tmp_path / d / f"F{i}.php").write_text("<?php")
    files1, candidates = warm_vs_cold.select_files(tmp_path, ["**/*.php"], 6, seed=42)
    files2, _ = warm_vs_cold.select_files(tmp_path, ["**/*.php"], 6, seed=42)
    assert candidates == 15
    assert len(files1) == 6
    assert files1 == files2  # same seed -> same sample
    # A different seed is allowed to pick a different sample -- only the count is pinned.
    files3, _ = warm_vs_cold.select_files(tmp_path, ["**/*.php"], 6, seed=7)
    assert len(files3) == 6


def test_select_files_listfile(tmp_path):
    (tmp_path / "src").mkdir()
    (tmp_path / "src" / "A.php").write_text("<?php")
    (tmp_path / "src" / "B.php").write_text("<?php")
    listfile = tmp_path / "list.txt"
    listfile.write_text("src/B.php\nsrc/A.php\n")
    files, candidates = warm_vs_cold.select_files(tmp_path, [f"@{listfile}"], None, seed=1)
    assert candidates == 2
    # @LISTFILE keeps its own order -- not sorted like a glob.
    assert [f.name for f in files] == ["B.php", "A.php"]


# --------------------------------------------------------------------------- pct / timing

def test_pct_and_timing_on_empty_and_populated():
    assert warm_vs_cold.pct([], 0.5) is None
    assert warm_vs_cold.timing([])["n"] == 0
    assert warm_vs_cold.timing([])["mean"] is None
    t = warm_vs_cold.timing([1.0, 2.0, 3.0])
    assert t["n"] == 3
    assert t["p50"] == 2.0
    assert t["mean"] == 2.0
    assert t["sum"] == 6.0


# --------------------------------------------------------------------------- warm_timing_buckets

def test_warm_timing_buckets_splits_restart_boot_from_later():
    # 3 sessions: [0]=first boot, [1,2]=later same session, [3]=restart boot, [4]=later.
    warm = [
        dict(session=1, seconds=1.0),
        dict(session=1, seconds=0.1),
        dict(session=1, seconds=0.2),
        dict(session=2, seconds=0.9),
        dict(session=2, seconds=0.15),
    ]
    buckets = warm_vs_cold.warm_timing_buckets(warm)
    assert buckets["first"] == [1.0]
    assert buckets["restart_boot"] == [0.9]
    assert buckets["later"] == [0.1, 0.2, 0.15]


def test_warm_timing_buckets_no_restart():
    warm = [dict(session=1, seconds=1.0), dict(session=1, seconds=0.1)]
    buckets = warm_vs_cold.warm_timing_buckets(warm)
    assert buckets["first"] == [1.0]
    assert buckets["restart_boot"] == []
    assert buckets["later"] == [0.1]


# --------------------------------------------------------------------------- md_fence

def test_md_fence_lengthens_around_embedded_backtick_runs():
    assert warm_vs_cold.md_fence("plain diff, no backticks") == "```"
    assert warm_vs_cold.md_fence("a run of ``` inside the diff itself") == "````"
    # Longest run across ALL given texts wins, not just the first.
    assert warm_vs_cold.md_fence("short", "``````") == "```````"


# --------------------------------------------------------------------------- run_cold_one

def test_run_cold_one_reports_unspawnable_php_instead_of_raising(tmp_path):
    args = argparse.Namespace(
        php="/no/such/php-binary-anywhere", working_dir=tmp_path, timeout=5.0, server_config=tmp_path / "wrapper.php",
    )
    result = warm_vs_cold.run_cold_one(args, Path("/no/such/rector"), tmp_path / "F.php", tmp_path / "cache")
    assert result["exit_code"] is None
    assert "could not start" in result["stderr"]


# --------------------------------------------------------------------------- smoke: the real tool

def test_smoke_run_against_fixture_project(tmp_path):
    """The tool actually runs: one warm session plus a cold rector, on the two
    files in tests/Fixtures/project, and reports a clean match (checkout mode).
    Kept to the small fixture already used by the rest of the E2E suite, so
    this test costs seconds -- a run against a real project is a manual step,
    see CONTRIBUTING.md."""
    out = tmp_path / "out"
    done = subprocess.run(
        [sys.executable, str(TOOL), "--project", str(FIXTURE_PROJECT),
         "--files", "src/*.php", "--php", php_binary(), "--out", str(out)],
        capture_output=True, text=True, timeout=120,
    )
    assert done.returncode == 0, f"stdout={done.stdout!r} stderr={done.stderr!r}"
    report = json.loads((out / "report.json").read_text())
    assert report["summary"]["mismatch"] == 0
    assert report["summary"]["warm_error"] == 0
    assert report["summary"]["cold_error"] == 0
    assert report["meta"]["mode"] == "checkout"
    assert report["meta"]["sampled"] == 2
    assert report["timings"]["warm_restart_boot"]["n"] == 0  # one session, no restart
    assert (out / "report.md").is_file()


def test_smoke_run_rerun_into_same_out_does_not_reuse_stale_cache(tmp_path):
    """A second run into the same --out must not silently reuse the first run's
    Rector cache: re-running immediately still reports the same clean match (the
    cache is cleared, so this exercises the actual cache path rather than skipping
    it), and a leftover cache directory from run 1 is gone before run 2 starts."""
    out = tmp_path / "out"
    for _ in range(2):
        done = subprocess.run(
            [sys.executable, str(TOOL), "--project", str(FIXTURE_PROJECT),
             "--files", "src/*.php", "--php", php_binary(), "--out", str(out)],
            capture_output=True, text=True, timeout=120,
        )
        assert done.returncode == 0, f"stdout={done.stdout!r} stderr={done.stderr!r}"
    report = json.loads((out / "report.json").read_text())
    assert report["summary"]["mismatch"] == 0
