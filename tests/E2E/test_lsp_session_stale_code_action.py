"""E2E for #223: a real `textDocument/codeAction` write -- one of the two
#216/#220 callers that force noSession:true (the other, `rector-warm.
fixWorkspace`'s own recompute, shares the EXACT same RectorDiagnosticsSource::
diagnoseForEdit()/diagnoseWorkspace() -> RectorTool::processForEdit() call,
pinned at the unit level by RectorDiagnosticsSourceNoSessionWiringTest.php) --
driven after the session has gone stale on a CUSTOM RULE's own data file, with
NO MCP_RECTOR_WARM_SESSION env var set at all: the ambient #220 default every
real user hits, never an opt-in.

`fixWorkspace` was tried first for this test and found NOT to exercise the
regression at all: RectorDiagnosticsSource::diagnoseWorkspace() processes the
whole project ROOT DIRECTORY in one Rector call, and RectorRunner::
sessionCandidate() already declines any call whose path is not a single
existing file -- so toggling noSession makes no observable difference for
that path (confirmed empirically: reverting processForEdit()'s noSession:true
to false left this test green). `textDocument/codeAction`'s own recompute
(RectorDiagnosticsSource::diagnoseForEdit()) targets exactly ONE open
document, the shape sessionCandidate() DOES accept -- the one #223 is
actually about.

Same custom-rule-reads-a-data-file shape as
tests/E2E/test_warm_session_watch.py's MapStringsFromJsonRector fixture
(file_get_contents a JSON file, cached in a static -- never tracked by the
session's own directory watch, docs/how-it-works.md Section 1b), adapted to
the hand-rolled LSP stdio harness (test_lsp_initialize.py / test_lsp_
diagnostics.py) since the MCP harness that file uses does not drive the LSP
binary at all.

The warm-vs-cold oracle applies here too (CLAUDE.md): the edit `textDocument/
codeAction`'s own quickfix produces must, once applied, equal what a cold
`vendor/bin/rector process` produces for that same file -- with the EDITED
data file already on disk, never the session's stale cached answer.
"""

from __future__ import annotations

import os
import subprocess
import time
from pathlib import Path

from test_lsp_diagnostics import apply_edit, did_open
from test_lsp_initialize import BIN, REPO, frame, php_binary, read_frame

CONFIG = r"""<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use Rector\Config\RectorConfig;
use Rector\Rector\AbstractRector;

// Same "read my inputs once, keep them in a static" custom rule shape as
// test_warm_session_watch.py's own MapStringsFromJsonRector.
final class MapStringsFromJsonRector223 extends AbstractRector
{
    private static ?array $map = null;

    public function getNodeTypes(): array
    {
        return [String_::class];
    }

    public function refactor(Node $node): ?Node
    {
        self::$map ??= json_decode((string) file_get_contents(__DIR__ . '/config/map.json'), true);
        $to = self::$map[$node->value] ?? null;

        return is_string($to) && $to !== $node->value ? new String_($to) : null;
    }
}

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withAutoloadPaths([__DIR__ . '/src'])
    ->withRules([MapStringsFromJsonRector223::class]);
"""

AUTOLOAD = r"""<?php

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
"""

SOURCE = """<?php

declare(strict_types=1);

namespace App;

final class Greeter223
{
    public function greet(): string
    {
        return 'hello';
    }
}
"""


def build(root: Path) -> None:
    for relative, content in {
        "rector.php": CONFIG,
        "vendor/autoload.php": AUTOLOAD,
        "config/map.json": '{"hello": "world"}',
        "src/Greeter223.php": SOURCE,
    }.items():
        target = root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding="utf-8")


def start_server_no_session_env(project: Path, capabilities: dict):
    """Same shape as test_lsp_diagnostics.py's start_server()/server fixture,
    with MCP_RECTOR_WARM_SESSION explicitly absent from the CHILD's own
    environment -- #223 is specifically about the ambient #220 default
    (unset means the session is ON), never an opt-in env var."""
    env = {k: v for k, v in os.environ.items() if k != "MCP_RECTOR_WARM_SESSION"}
    proc = subprocess.Popen(
        [php_binary(), str(BIN), f"--working-dir={project}"],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=env,
    )
    proc.stdin.write(frame({
        "jsonrpc": "2.0", "id": 1, "method": "initialize",
        "params": {"processId": None, "rootUri": None, "capabilities": capabilities},
    }))
    proc.stdin.write(frame({"jsonrpc": "2.0", "method": "initialized", "params": {}}))
    proc.stdin.flush()
    read_frame(proc.stdout)
    return proc


def stop_server(proc) -> None:
    if proc.poll() is None:
        proc.kill()
    proc.wait(timeout=10)


# No workspace.applyEdit/documentChanges declared: a codeAction response
# carries its own WorkspaceEdit directly (`changes[uri]`, same shape
# test_lsp_diagnostics.py's own test_code_action_edit_matches_a_cold_rector_
# apply asserts against) -- no outbound `workspace/applyEdit` request is
# needed to exercise this path at all.
CAPABILITIES = {}


def test_code_action_after_a_stale_custom_rule_input_still_equals_cold(tmp_path):
    project = tmp_path / "project"
    build(project)
    assert "MCP_RECTOR_WARM_SESSION" not in os.environ, (
        "this test's whole point is the ambient #220 default -- a session "
        "var leaking from the outer environment would invalidate it"
    )

    proc = start_server_no_session_env(project, CAPABILITIES)
    path = project / "src" / "Greeter223.php"
    uri = path.as_uri()
    original = path.read_bytes().decode("utf-8")

    try:
        # Step 0: didOpen's own diagnostics computation (RectorDiagnosticsSource
        # ::diagnose(), noSession:false, single open file -- exactly the shape
        # RectorRunner::sessionCandidate() accepts) warms the session, which
        # caches the custom rule's static with map.json's ORIGINAL content.
        notification = did_open(proc, uri)
        diagnostics = notification["params"]["diagnostics"]
        assert diagnostics, "expected the custom rule to report a fixable diagnostic on open"
        assert "MapStringsFromJsonRector223" in diagnostics[0]["message"], diagnostics[0]

        # Go stale: edit the custom rule's own data file -- never tracked by
        # the session's own directory watch -- and bump its mtime so an
        # mtime-only staleness check cannot coincidentally catch it either.
        data_file = project / "config" / "map.json"
        data_file.write_text('{"hello": "there"}', encoding="utf-8")
        later = time.time() + 10
        os.utime(data_file, (later, later))

        # The code action recompute: RectorDiagnosticsSource::diagnoseForEdit()
        # -> RectorTool::processForEdit() -> noSession:true -- must fork fresh
        # from the pristine worker and so must reflect the EDIT, never the
        # session's stale cached answer the diagnostic above was computed from.
        proc.stdin.write(frame({
            "jsonrpc": "2.0",
            "id": 2,
            "method": "textDocument/codeAction",
            "params": {
                "textDocument": {"uri": uri},
                "range": diagnostics[0]["range"],
                "context": {"diagnostics": diagnostics},
            },
        }))
        proc.stdin.flush()
        actions = read_frame(proc.stdout)["result"]
    finally:
        stop_server(proc)

    assert any(a["title"] == "Apply all Rector fixes" for a in actions), actions
    fix_all = next(a for a in actions if a["title"] == "Apply all Rector fixes")
    warm_applied = original
    for edit in sorted(
        fix_all["edit"]["changes"][uri],
        key=lambda e: e["range"]["start"]["line"],
        reverse=True,
    ):
        warm_applied = apply_edit(warm_applied, edit)

    # Cold oracle: a FRESH copy, built AFTER the edit -- no prior call and no
    # session here to go stale, so cold rector reads map.json exactly once,
    # with the edited content already in place.
    cold_copy = tmp_path / "cold"
    build(cold_copy)
    (cold_copy / "config" / "map.json").write_text('{"hello": "there"}', encoding="utf-8")
    done = subprocess.run(
        [php_binary(), str(REPO / "vendor" / "bin" / "rector"), "process",
         "--config=rector.php", "--no-progress-bar", "--", "src/Greeter223.php"],
        cwd=cold_copy, capture_output=True, text=True, timeout=60,
    )
    assert done.returncode == 0, done
    cold_applied = (cold_copy / "src" / "Greeter223.php").read_bytes().decode("utf-8")

    assert warm_applied == cold_applied
    assert "'there'" in warm_applied, (
        "must reflect the EDITED map.json, never the stale cached value "
        "('world') the session primed by didOpen's own diagnostics would "
        "otherwise answer with -- that stale value is exactly what #223 "
        "exists to keep out of a write"
    )
