#!/usr/bin/env python3
"""Print the slowest PHPUnit tests from a JUnit XML log (#209).

    tools/slowest-tests.py JUNIT_XML [--limit N] [--format text|markdown]

Reads a PHPUnit --log-junit file and ranks every <testcase> by its `time`
attribute, descending. Nothing here is hand-maintained: the ranking is
recomputed from whatever the suite actually reported on this run, so it
cannot drift from reality the way a hand-kept "known slow tests" list would.

`--format markdown` (the default) emits a table suited to
`$GITHUB_STEP_SUMMARY`; `--format text` emits a plain aligned table for a
terminal.
"""
from __future__ import annotations

import argparse
import sys
import xml.etree.ElementTree as ET


def collect_testcases(root: ET.Element) -> list[tuple[str, str, float]]:
    """Return (classname, name, time_seconds) for every <testcase> in the tree.

    JUnit XML nests <testcase> under <testsuite> under an optional outer
    <testsuites>; this walks every <testcase> anywhere in the document rather
    than assuming one fixed depth, so it survives either shape.
    """
    cases: list[tuple[str, str, float]] = []
    for testcase in root.iter("testcase"):
        name = testcase.get("name", "<unnamed>")
        classname = testcase.get("class") or testcase.get("classname") or ""
        time_raw = testcase.get("time")
        try:
            time_seconds = float(time_raw) if time_raw is not None else 0.0
        except ValueError:
            label = f"{classname}::{name}" if classname else name
            print(
                f"slowest-tests: {label} has a non-numeric time attribute "
                f"({time_raw!r}); treating it as 0.0, not a measured fast test",
                file=sys.stderr,
            )
            time_seconds = 0.0
        cases.append((classname, name, time_seconds))
    return cases


def render_markdown(rows: list[tuple[str, str, float]]) -> str:
    lines = [
        "| # | Test | Time (s) |",
        "| --- | --- | --- |",
    ]
    for i, (classname, name, seconds) in enumerate(rows, start=1):
        label = f"{classname}::{name}" if classname else name
        lines.append(f"| {i} | `{label}` | {seconds:.3f} |")
    return "\n".join(lines)


def render_text(rows: list[tuple[str, str, float]]) -> str:
    lines = []
    for i, (classname, name, seconds) in enumerate(rows, start=1):
        label = f"{classname}::{name}" if classname else name
        lines.append(f"{i:>2}. {seconds:>8.3f}s  {label}")
    return "\n".join(lines)


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("junit_xml", help="Path to a PHPUnit --log-junit XML file")
    parser.add_argument("--limit", type=int, default=10, help="How many tests to show (default: 10)")
    parser.add_argument("--format", choices=["text", "markdown"], default="markdown")
    args = parser.parse_args(argv)

    try:
        tree = ET.parse(args.junit_xml)
    except (ET.ParseError, OSError) as exc:
        print(f"slowest-tests: could not read {args.junit_xml}: {exc}", file=sys.stderr)
        return 1

    cases = collect_testcases(tree.getroot())
    if not cases:
        print(f"slowest-tests: no <testcase> elements found in {args.junit_xml}", file=sys.stderr)
        return 1

    cases.sort(key=lambda row: row[2], reverse=True)
    top = cases[: args.limit]

    if args.format == "markdown":
        print(f"### Slowest {len(top)} tests (of {len(cases)} total)\n")
        print(render_markdown(top))
    else:
        print(f"Slowest {len(top)} tests (of {len(cases)} total):")
        print(render_text(top))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
