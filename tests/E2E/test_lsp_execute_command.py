"""E2E for #102: `workspace/executeCommand` ("rector-warm.fixWorkspace") over a
real subprocess and real stdio, reusing test_lsp_diagnostics.py's fixture/server
helpers (same hand-rolled client as the rest of the LSP E2E suite -- see
test_lsp_initialize.py's module docstring for why).

The warm-vs-cold oracle applies here too (CLAUDE.md, e2e-harness.md): every file
`workspace/applyEdit` names must, once its edits are applied, equal what a cold
`vendor/bin/rector process` (non-dry-run) produces for that same file when run
over the whole project directory.
"""

from __future__ import annotations

import subprocess
from pathlib import Path
from urllib.parse import unquote, urlsplit

from test_lsp_diagnostics import apply_edit, copy_fixture, start_server, stop_server
from test_lsp_initialize import REPO, frame, php_binary, read_frame


def uri_to_path(uri: str) -> Path:
    """The inverse of LspServer::pathToUri() -- percent-decoded, same as a
    real editor reads a `file://` URI back. A bare `removeprefix("file://")`
    (this test's own first version, self-review finding: oss:auditor pass)
    would leave a percent-escaped path segment -- e.g. a space as `%20`, or
    (Windows-only, reasoned not observed here) a drive letter percent-
    escaped by an implementation that does not special-case it the way
    LspServer::pathToUri() now does -- literal in the resulting Path,
    silently failing to resolve to the real file."""
    return Path(unquote(urlsplit(uri).path))

APPLY_EDIT_CAPABILITIES = {
    "workspace": {"applyEdit": True, "workspaceEdit": {"documentChanges": True}},
}


def execute_fix_workspace(proc, request_id: int = 2) -> list[dict]:
    """Send the command and collect every frame the server writes back for
    it -- with no window.workDoneProgress declared here, that is at most an
    outbound `workspace/applyEdit` request followed by the response to this
    request itself."""
    proc.stdin.write(frame({
        "jsonrpc": "2.0",
        "id": request_id,
        "method": "workspace/executeCommand",
        "params": {"command": "rector-warm.fixWorkspace"},
    }))
    proc.stdin.flush()

    frames = [read_frame(proc.stdout)]
    if frames[0].get("id") != request_id:
        frames.append(read_frame(proc.stdout))
    return frames


def test_fix_workspace_matches_a_cold_rector_apply_across_every_changed_file(tmp_path):
    project = tmp_path / "project"
    copy_fixture(project)
    proc = start_server(project, APPLY_EDIT_CAPABILITIES)

    try:
        frames = execute_fix_workspace(proc)
    finally:
        stop_server(proc)

    apply_edit_request = next(f for f in frames if f.get("method") == "workspace/applyEdit")
    result = next(f for f in frames if f.get("id") == 2)
    assert "error" not in result, result

    document_changes = apply_edit_request["params"]["edit"]["documentChanges"]
    assert document_changes, "expected at least one file to have a fix"

    # Cold oracle: apply Rector for real on a fresh copy of the whole
    # fixture, the same tree the warm dry run just analysed.
    cold_copy = tmp_path / "cold"
    copy_fixture(cold_copy)
    done = subprocess.run(
        [php_binary(), str(REPO / "vendor" / "bin" / "rector"), "process",
         "--config=rector.php", "--no-progress-bar", "--", "src"],
        cwd=cold_copy, capture_output=True, text=True, timeout=60,
    )
    # A syntax error in one file (src/Broken.php, deliberately part of this
    # fixture -- see test_lsp_diagnostics.py) makes Rector exit non-zero
    # even though every OTHER file was processed; only a genuinely empty
    # output means nothing ran at all.
    assert done.stdout != "" or done.stderr != "", done

    for change in document_changes:
        uri = change["textDocument"]["uri"]
        path = uri_to_path(uri)
        relative = path.relative_to(project)
        original = (project / relative).read_bytes().decode("utf-8")

        warm_applied = original
        # Edits within one file must be applied in the same order the
        # server sent them, same as test_code_action_edit_matches_a_cold_
        # rector_apply's own single-edit case generalizes to N edits: this
        # server always emits a file's hunks top-to-bottom (RectorDiffParser
        # never reorders them), so applying in list order is safe here --
        # unlike an editor applying arbitrary overlapping edits, these never
        # overlap (they are Rector's own non-overlapping hunks).
        for edit in change["edits"]:
            warm_applied = apply_edit(warm_applied, edit)

        cold_applied = (cold_copy / relative).read_bytes().decode("utf-8")
        assert warm_applied == cold_applied, relative


def test_fix_workspace_refuses_without_client_apply_edit_support(tmp_path):
    # #102 "refuse with a visible error, never a silent no-op" -- E2E must-fire
    # positive control for the unit-level refusal test in LspServerTest.
    project = tmp_path / "project"
    copy_fixture(project)
    proc = start_server(project, {})  # no workspace.applyEdit declared

    try:
        frames = execute_fix_workspace(proc)
    finally:
        stop_server(proc)

    assert len(frames) == 1
    assert "error" in frames[0]
    assert "applyEdit" not in [f.get("method") for f in frames]
