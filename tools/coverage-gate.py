#!/usr/bin/env python3
"""Fail when line coverage in a Clover report is below a threshold (#275).

    tools/coverage-gate.py CLOVER_XML [--min PERCENT] [--label NAME]

Gates on the Codecov-equivalent figure: every distinct (file, line number)
that the Clover file lists as a <line type="stmt"> or <line type="method">,
counted as hit when its count is above 0. That is how Codecov turns a
php-code-coverage Clover file into "lines" and "hits", so this is the number
the README badge and the 90% goal are expressed in.

It is NOT coveredstatements / statements from the project-level <metrics>:
that figure leaves out the method-declaration lines, which are almost all hit,
so it reads about one point lower (locally 90.45% against 91.14% on the same
file). It is printed alongside for information only.

Prints Markdown lines with both figures and appends them to
$GITHUB_STEP_SUMMARY when that is set, pass or fail. Below the threshold it
also prints a `::error::` annotation and exits 1. A missing or unreadable
report, or one with no countable lines, also exits 1: a gate that cannot
measure must not pass.

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

GATED_LINE_TYPES = ("stmt", "method")


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


def percent(ratio: Fraction) -> str:
    # Truncate, never round up: 89.996% must not print as 90.00% and then fail.
    return f"{math.floor(ratio * 100) / 100:.2f}"


def codecov_equivalent(root: ET.Element) -> tuple[int, int]:
    """Return (hit, total) over distinct stmt + method lines, Codecov-style."""
    best: dict[tuple[str, str], int] = {}
    for file in root.iter("file"):
        name = file.get("name", "")
        for line in file.iter("line"):
            if line.get("type") not in GATED_LINE_TYPES:
                continue
            key = (name, line.get("num", ""))
            try:
                count = int(line.get("count", "0"))
            except ValueError:
                count = 0
            best[key] = max(best.get(key, 0), count)
    return sum(1 for count in best.values() if count > 0), len(best)


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("clover")
    parser.add_argument("--min", default="90.0", help="minimum Codecov-equivalent line coverage in percent")
    parser.add_argument("--label", default="", help="prefix for the printed lines, e.g. the OS")
    args = parser.parse_args(argv)

    label = f"{args.label}: " if args.label else ""
    threshold = Fraction(args.min)

    try:
        root = ET.parse(args.clover).getroot()
    except (OSError, ET.ParseError) as exc:
        return fail(f"{label}coverage gate could not read {args.clover}: {exc}")

    hit, total = codecov_equivalent(root)
    if total <= 0:
        return fail(f"{label}coverage gate found no stmt or method lines in {args.clover}")
    ratio = Fraction(hit * 100, total)
    detail = (
        f"{percent(ratio)}% (Codecov-equivalent: statements + methods, {hit}/{total} lines), "
        f"minimum {args.min}%"
    )

    metrics = root.find("project/metrics")
    info = "statements only (information, not gated): not available, no project-level <metrics>"
    if metrics is not None:
        try:
            statements = int(metrics.get("statements", ""))
            covered = int(metrics.get("coveredstatements", ""))
        except ValueError:
            statements = 0
            covered = 0
        if statements > 0:
            info = (
                f"statements only (information, not gated): "
                f"{percent(Fraction(covered * 100, statements))}% ({covered}/{statements})"
            )

    if ratio < threshold:
        status = fail(f"{label}{detail} -- below the coverage gate (#275)")
    else:
        write_summary(f"- :white_check_mark: {label}{detail}")
        status = 0
    write_summary(f"  - {info}")
    return status


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
