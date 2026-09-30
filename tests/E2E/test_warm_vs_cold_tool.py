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
import os
import shutil
import subprocess
import sys
from pathlib import Path

import pytest

from mcp_harness import FIXTURE_PROJECT, NO_PCNTL_PLATFORM, REPO, SESSION_OPT_OUT, php_binary

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
    (tmp_path / "src" / "A.php").write_text("<?php", encoding="utf-8", newline="")
    (tmp_path / "src" / "B.txt").write_text("not php", encoding="utf-8", newline="")
    files, candidates = warm_vs_cold.select_files(tmp_path, ["src/*"], None, seed=1)
    assert candidates == 1
    assert [f.name for f in files] == ["A.php"]


def test_select_files_limit_is_deterministic_and_bounded(tmp_path):
    for d in "abc":
        (tmp_path / d).mkdir()
        for i in range(5):
            (tmp_path / d / f"F{i}.php").write_text("<?php", encoding="utf-8", newline="")
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
    (tmp_path / "src" / "A.php").write_text("<?php", encoding="utf-8", newline="")
    (tmp_path / "src" / "B.php").write_text("<?php", encoding="utf-8", newline="")
    listfile = tmp_path / "list.txt"
    listfile.write_text("src/B.php\nsrc/A.php\n", encoding="utf-8", newline="")
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
    report = json.loads((out / "report.json").read_bytes().decode("utf-8"))
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
    report = json.loads((out / "report.json").read_bytes().decode("utf-8"))
    assert report["summary"]["mismatch"] == 0


# --------------------------------------------------------------------------- #192: a foreign php-parser copy

FOREIGN_PHP_PARSER_PROJECT = REPO / "tests" / "Fixtures" / "foreign-php-parser-project"
FOREIGN_MARKER = "foreign nikic/php-parser copy used"


def test_foreign_php_parser_fixture_is_live():
    """Positive control for the test below: the fixture's own PhpParser\\NodeVisitorAbstract
    really is what its autoloader serves, and it really throws the marker. Without this, a
    fixture that silently stopped loading (a typo in the map, a moved file) would let the
    warm == cold test below pass with nothing left to catch."""
    script = (
        f"require {warm_vs_cold.php_str(str(REPO / 'vendor' / 'autoload.php'))};"
        f"require {warm_vs_cold.php_str(str(FOREIGN_PHP_PARSER_PROJECT / 'vendor' / 'autoload.php'))};"
        "$v = new class extends PhpParser\\NodeVisitorAbstract {};"
        "echo (new ReflectionClass(PhpParser\\NodeVisitorAbstract::class))->getFileName(), PHP_EOL;"
        "try { $v->beforeTraverse([]); echo 'no exception'; } catch (LogicException $e) { echo $e->getMessage(); }"
    )
    done = subprocess.run([php_binary(), "-r", script], capture_output=True, text=True, timeout=60)
    assert done.returncode == 0, f"stdout={done.stdout!r} stderr={done.stderr!r}"
    loaded_from, message = done.stdout.splitlines()
    assert Path(loaded_from).resolve().is_relative_to(FOREIGN_PHP_PARSER_PROJECT.resolve())
    assert message == FOREIGN_MARKER


def run_tool_from_empty_tmpdir(tmp_path: Path, project: Path, extra_env: dict[str, str] | None = None) -> tuple[dict, str]:
    """Run the tool on every src/*.php of `project` with TMPDIR (TMP/TEMP on Windows)
    pointing at a fresh empty directory -- a first run on a new machine or CI runner:
    PHPStan's own cache lives under sys_get_temp_dir()/cache/PHPStan, and the tool
    only redirects Rector's cache, not that one (#192). Returns the report and its
    markdown; asserts the run happened at all, so a caller's 'must not' is not vacuous."""
    empty_tmp = tmp_path / "tmp"
    empty_tmp.mkdir()
    env = {**os.environ, "TMPDIR": str(empty_tmp), "TMP": str(empty_tmp), "TEMP": str(empty_tmp), **(extra_env or {})}
    out = tmp_path / "out"
    done = subprocess.run(
        [sys.executable, str(TOOL), "--project", str(project),
         "--files", "src/*.php", "--php", php_binary(), "--out", str(out)],
        capture_output=True, text=True, timeout=120, env=env,
    )
    report_md = (out / "report.md").read_text(encoding="utf-8") if (out / "report.md").is_file() else ""
    assert (out / "report.json").is_file(), f"stdout={done.stdout!r} stderr={done.stderr!r}"
    # The PHPStan cache really was built from nothing, in the empty dir this run chose.
    assert (empty_tmp / "cache" / "PHPStan").is_dir(), sorted(p.name for p in empty_tmp.iterdir())
    report = json.loads((out / "report.json").read_bytes().decode("utf-8"))
    assert report["meta"]["mode"] == "checkout"
    return report, report_md


def assert_all_match_with_a_real_diff(report: dict, report_md: str) -> None:
    assert report["summary"]["mismatch"] == 0, report_md
    assert report["summary"]["warm_error"] == 0, report_md
    assert report["summary"]["cold_error"] == 0, report_md
    assert report["summary"]["match"] == 1, report_md
    # Not a vacuous match of two empty answers: cold really changes the file.
    assert report["summary"]["changed_cold"] == 1, report_md
    assert report["summary"]["changed_warm"] == 1, report_md


def test_foreign_php_parser_in_project_vendor_matches_cold_from_empty_tmpdir(tmp_path):
    """#192: the project's autoloader can resolve PhpParser\\* classes from its own copy
    (laravel/symfony ship one; phpstan.phar carries another) and is registered ahead of
    Rector's. Cold `rector process` requires Rector's preload.php before any of that, so
    every php-parser class comes from Rector's bundled copy; warm must too. Before the
    fix the warm worker took the fixture's copy and reported its marker as an error."""
    report, report_md = run_tool_from_empty_tmpdir(tmp_path, FOREIGN_PHP_PARSER_PROJECT)
    assert FOREIGN_MARKER not in report_md, report_md
    assert_all_match_with_a_real_diff(report, report_md)


STD_STREAMS_PROJECT = REPO / "tests" / "Fixtures" / "std-streams-project"


def test_std_stream_constants_stay_open_in_the_analysing_process_from_empty_tmpdir(tmp_path):
    """#192: on an empty PHPStan cache, resolving a native function's default argument
    (debug_backtrace()'s here) builds PhpStorm-stub reflection for every constant in
    the same stub file, STDIN/STDOUT/STDERR included, from their runtime values.
    Warm used to fclose() those three in the forked process that analyses the file,
    so the values were closed resources, is_resource() said false, and
    BuilderHelpers::normalizeValue() threw 'Invalid value' (line 216) where cold,
    with its streams open, returned the diff. A populated PHPStan cache hid it."""
    report, report_md = run_tool_from_empty_tmpdir(tmp_path, STD_STREAMS_PROJECT)
    assert "Invalid value" not in report_md, report_md
    assert_all_match_with_a_real_diff(report, report_md)


@pytest.mark.parametrize("fixture", [STD_STREAMS_PROJECT, FOREIGN_PHP_PARSER_PROJECT], ids=["std-streams", "foreign-php-parser"])
def test_the_session_child_follows_the_192_rules_from_empty_tmpdir(tmp_path, fixture):
    """#185 on top of #192: the two cases above, served INSIDE the session child
    (MCP_RECTOR_WARM_SESSION=1, and withAutoloadPaths so the session accepts the
    file instead of declining it to the pristine fork). The session must keep the
    std stream constants open too, and inherit the worker's php-parser preload."""
    if NO_PCNTL_PLATFORM or SESSION_OPT_OUT:
        pytest.skip("no session child here (no pcntl, or MCP_RECTOR_WARM_SESSION=0)")
    project = tmp_path / "project"
    shutil.copytree(fixture, project)
    config = project / "rector.php"
    text = config.read_text(encoding="utf-8")
    assert "->withPaths([__DIR__ . '/src'])" in text
    config.write_text(text.replace(
        "->withPaths([__DIR__ . '/src'])",
        "->withPaths([__DIR__ . '/src'])\n    ->withAutoloadPaths([__DIR__ . '/src'])",
    ), encoding="utf-8")
    report, report_md = run_tool_from_empty_tmpdir(
        tmp_path, project, {"MCP_RECTOR_WARM_SESSION": "1", "MCP_RECTOR_WARM_SESSION_LOG": "1"},
    )
    assert "Invalid value" not in report_md and FOREIGN_MARKER not in report_md, report_md
    assert_all_match_with_a_real_diff(report, report_md)
    # Positive control: the session really analysed the file (not a decline).
    log = (tmp_path / "out" / "warm-server.stderr").read_text(encoding="utf-8")
    assert "mcp-rector-warm: session serve:" in log, log
