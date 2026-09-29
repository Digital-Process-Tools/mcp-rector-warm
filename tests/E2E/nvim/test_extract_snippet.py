"""#109: extract_snippet.py pulls a marked fenced block out of docs/lsp.md
verbatim -- the piece #109's headless-editor smoke test depends on to run
what docs/lsp.md actually documents, not a copy of it frozen at write time.
"""

from __future__ import annotations

from pathlib import Path

import pytest

from extract_snippet import SnippetNotFoundError, extract_snippet

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[2]
README = REPO / "docs" / "lsp.md"


def test_extracts_the_fenced_block_between_markers():
    text = (
        "before\n"
        "<!-- snippet:demo -->\n"
        "```lua\n"
        "return 1\n"
        "```\n"
        "<!-- /snippet:demo -->\n"
        "after\n"
    )
    assert extract_snippet(text, "demo") == "return 1\n"


def test_missing_marker_raises_rather_than_returning_something_that_looks_like_success():
    # Negative-control pairing for the case above: a marker that is not there
    # must fail loudly, not quietly hand back an empty string or the whole
    # file -- either of which would look like success to a caller that only
    # checks "did I get a string back".
    text = "no markers here at all\n```lua\nx = 1\n```\n"
    with pytest.raises(SnippetNotFoundError):
        extract_snippet(text, "demo")


def test_end_marker_missing_raises():
    text = "<!-- snippet:demo -->\n```lua\nx = 1\n```\n"
    with pytest.raises(SnippetNotFoundError):
        extract_snippet(text, "demo")


def test_marker_present_but_no_fenced_block_inside_raises():
    text = "<!-- snippet:demo -->\nno code fence here\n<!-- /snippet:demo -->\n"
    with pytest.raises(SnippetNotFoundError):
        extract_snippet(text, "demo")


@pytest.mark.parametrize(
    "marker",
    ["nvim-native-config", "nvim-native-enable", "helix-languages"],
)
def test_readme_itself_carries_every_marker_this_smoke_test_needs(marker):
    # This is the regression guard #109 is actually for: if a future edit to
    # docs/lsp.md drops or renames one of these markers, this fails here
    # instead of the headless-editor CI job silently skipping that editor.
    text = README.read_text(encoding="utf-8")
    snippet = extract_snippet(text, marker)
    assert snippet.strip() != ""
