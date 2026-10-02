#!/usr/bin/env python3
"""Fail when total line coverage in a Clover report is below a threshold (#275).

    tools/coverage-gate.py CLOVER_XML [--min PERCENT] [--label NAME]

Reads the project-level <metrics> element of a Clover file (the merged
coverage.xml phpcov writes in the `coverage` CI job) and computes
coveredstatements / statements. In php-code-coverage's Clover output those two
attributes are executable lines and executed lines, the same numbers Codecov
reports as "lines" and "hits" for the upload.

Prints one Markdown line with the percentage, and appends it to
$GITHUB_STEP_SUMMARY when that is set, pass or fail. Below the threshold it
also prints a `::error::` annotation and exits 1. A missing or unreadable
report, or one with no statements, also exits 1: a gate that cannot measure
must not pass.

Codecov's own `codecov/project` status is not posted on this repository, so
this script is the coverage gate, not Codecov.
"""
from __future__ import annotations

import argparse
import math
import os
import sys
import xml.etree.ElementTree as ET
from fractions import Fraction


def fail(message: str) -> int:
    print(f"::error::{message}")
    write_summary(f"- :x: {message}")
    return 1


def write_summary(line: str) -> None:
    print(line)
    path = os.environ.get("GITHUB_STEP_SUMMARY")
    if path:
        with open(path, "a", encoding="utf-8") as handle:
            handle.write(line + "\n")


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("clover")
    parser.add_argument("--min", default="90.0", help="minimum line coverage in percent")
    parser.add_argument("--label", default="", help="prefix for the printed line, e.g. the OS")
    args = parser.parse_args(argv)

    label = f"{args.label}: " if args.label else ""
    threshold = Fraction(args.min)

    try:
        root = ET.parse(args.clover).getroot()
    except (OSError, ET.ParseError) as exc:
        return fail(f"{label}coverage gate could not read {args.clover}: {exc}")

    metrics = root.find("project/metrics")
    if metrics is None:
        return fail(f"{label}coverage gate found no project-level <metrics> in {args.clover}")
    try:
        statements = int(metrics.get("statements", ""))
        covered = int(metrics.get("coveredstatements", ""))
    except ValueError:
        return fail(f"{label}coverage gate found non-numeric statement counts in {args.clover}")
    if statements <= 0:
        return fail(f"{label}coverage gate found 0 statements in {args.clover}")

    ratio = Fraction(covered * 100, statements)
    # Truncate, never round up: 89.996% must not print as 90.00% and then fail.
    shown = f"{math.floor(ratio * 100) / 100:.2f}"
    detail = f"{shown}% line coverage ({covered}/{statements} statements), minimum {args.min}%"

    if ratio < threshold:
        return fail(f"{label}{detail} -- below the coverage gate (#275)")
    write_summary(f"- :white_check_mark: {label}{detail}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
