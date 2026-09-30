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

import re
import subprocess
from pathlib import Path
from urllib.parse import unquote, urlsplit

from test_lsp_diagnostics import apply_edit, copy_fixture, did_change, did_open, start_server, stop_server
from test_lsp_initialize import REPO, frame, php_binary, read_frame

# A `file:///C:/Users/...` URI's path component, per urlsplit(), keeps the
# leading '/' before the drive letter (`/C:/Users/...`) -- unlike PHP's own
# parse_url(), which LspServer::uriToPath()'s docblock notes already strips
# it for exactly this shape. Left un-stripped, `Path("/C:/Users/...")` on
# Windows is read as a root-relative path with a folder literally named
# "C:", not the C: drive -- observed in CI (PR #137, windows-latest legs):
# ValueError: '\C:\...' is not in the subpath of 'C:\...'
_WINDOWS_DRIVE_WITH_LEADING_SLASH = re.compile(r"^/[A-Za-z]:")


def uri_to_path(uri: str) -> Path:
    """The inverse of LspServer::pathToUri() -- percent-decoded and, for a
    Windows drive-letter path, stripped of the leading '/' the same way
    LspServer::uriToPath() strips it on the PHP side, so this test reads a
    `file://` URI back exactly the way a real editor would."""
    path = unquote(urlsplit(uri).path)
    if _WINDOWS_DRIVE_WITH_LEADING_SLASH.match(path):
        path = path[1:]
    return Path(path)

APPLY_EDIT_CAPABILITIES = {
    "workspace": {"applyEdit": True, "workspaceEdit": {"documentChanges": True}},
}


def execute_fix_workspace(proc, request_id: int = 2) -> list[dict]:
    """Send the command and collect every frame the server writes back for
    it, reading until the response TO THIS REQUEST ITSELF arrives (an `id`
    matching request_id, with no `method` -- a response, not another
    request the server sent us). With no window.workDoneProgress declared
    here, that response can be preceded by an outbound `workspace/applyEdit`
    request and/or, since #141, a `window/showMessage` notification naming
    any file that failed alongside others that succeeded -- a fixed
    read-one-or-two-frames count (the pre-#141 shape) silently stops short
    of the real response once a third frame is possible, leaving it
    unread on the stream and making every assertion below it look for a
    response that was never captured. Bounded so a genuine protocol
    regression (the response never arriving at all) fails with a clear
    error instead of hanging the test forever.
    """
    proc.stdin.write(frame({
        "jsonrpc": "2.0",
        "id": request_id,
        "method": "workspace/executeCommand",
        "params": {"command": "rector-warm.fixWorkspace"},
    }))
    proc.stdin.flush()

    frames = []
    for _ in range(10):
        fr = read_frame(proc.stdout)
        frames.append(fr)
        if fr.get("id") == request_id and "method" not in fr:
            return frames
    raise AssertionError(
        f"never saw the response to executeCommand (id={request_id}) within 10 frames: {frames}"
    )


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


def test_fix_workspace_skips_a_dirty_buffer_and_reports_it(tmp_path):
    # #140, must not fire: an open, unsaved (dirty) buffer must never be
    # overwritten with a fix computed from disk -- applying one would
    # silently discard the buffer's unsaved edits, exactly the bug this
    # issue reports (real subprocess, real stdio -- the unit-level tests in
    # LspServerTest already pin the same behaviour against a fake source).
    project = tmp_path / "project"
    copy_fixture(project)
    proc = start_server(project, APPLY_EDIT_CAPABILITIES)
    fixable_uri = (project / "src" / "Fixable.php").as_uri()

    try:
        did_open(proc, fixable_uri)
        original = (project / "src" / "Fixable.php").read_bytes().decode("utf-8")
        did_change(proc, fixable_uri, 2, original + "\n// unsaved, must survive\n")
        read_frame(proc.stdout)  # drain the didChange diagnostics publish

        frames = execute_fix_workspace(proc)
    finally:
        stop_server(proc)

    result = next(f for f in frames if f.get("id") == 2)
    assert "error" not in result, result
    assert result["result"] == {"skippedDirtyBuffers": [fixable_uri]}, result

    apply_edit_request = next((f for f in frames if f.get("method") == "workspace/applyEdit"), None)
    if apply_edit_request is not None:
        uris = [c["textDocument"]["uri"] for c in apply_edit_request["params"]["edit"]["documentChanges"]]
        assert fixable_uri not in uris, uris


def test_fix_workspace_still_fixes_a_file_that_is_open_but_not_dirty(tmp_path):
    # Positive control for the test above (CLAUDE.md's own rule: a
    # must-not-fire assertion needs a must-fire sibling): a file that IS
    # open, but has no unsaved buffer content (no didChange since didOpen),
    # is not at risk and must still get its fix -- proving the skip is
    # triggered by dirtiness, not by merely being open.
    project = tmp_path / "project"
    copy_fixture(project)
    proc = start_server(project, APPLY_EDIT_CAPABILITIES)
    fixable_uri = (project / "src" / "Fixable.php").as_uri()

    try:
        did_open(proc, fixable_uri)
        frames = execute_fix_workspace(proc)
    finally:
        stop_server(proc)

    result = next(f for f in frames if f.get("id") == 2)
    assert "error" not in result, result
    assert result["result"] is None, result

    apply_edit_request = next(f for f in frames if f.get("method") == "workspace/applyEdit")
    uris = [c["textDocument"]["uri"] for c in apply_edit_request["params"]["edit"]["documentChanges"]]
    assert fixable_uri in uris, uris
