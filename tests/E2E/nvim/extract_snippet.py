#!/usr/bin/env python3
"""Extract one marked fenced code block from README.md.

#109: the README's editor snippets (Neovim's native config, its
`vim.lsp.enable` line, Helix's languages.toml) rot silently unless something
runs them for real. This is the "something" that pulls the exact bytes a
headless editor is then driven with, straight out of README.md, via a
`<!-- snippet:NAME -->` / `<!-- /snippet:NAME -->` marker pair -- so the
smoke test exercises what the README actually says today, not a copy of it
that can drift.

Usage: extract_snippet.py <marker> <readme_path>
Prints the fenced block's contents (without the ``` fences) to stdout.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

_FENCE_RE = re.compile(r"```[a-zA-Z0-9_-]*\n(.*?)```", re.DOTALL)


class SnippetNotFoundError(Exception):
    pass


def extract_snippet(text: str, marker: str) -> str:
    """Return the contents of the fenced code block between
    `<!-- snippet:MARKER -->` and `<!-- /snippet:MARKER -->` in `text`."""
    start_tag = f"<!-- snippet:{marker} -->"
    end_tag = f"<!-- /snippet:{marker} -->"

    start = text.find(start_tag)
    if start == -1:
        raise SnippetNotFoundError(f"no start marker for {marker!r}")

    end = text.find(end_tag, start + len(start_tag))
    if end == -1:
        raise SnippetNotFoundError(f"no end marker for {marker!r}")

    block = text[start + len(start_tag):end]
    match = _FENCE_RE.search(block)
    if not match:
        raise SnippetNotFoundError(f"no fenced code block inside marker {marker!r}")

    return match.group(1)


def main(argv: list[str]) -> int:
    if len(argv) != 3:
        print("usage: extract_snippet.py <marker> <readme_path>", file=sys.stderr)
        return 2

    marker, readme_path = argv[1], argv[2]
    text = Path(readme_path).read_text(encoding="utf-8")

    try:
        snippet = extract_snippet(text, marker)
    except SnippetNotFoundError as exc:
        print(f"extract_snippet.py: {exc}", file=sys.stderr)
        return 1

    sys.stdout.write(snippet)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
