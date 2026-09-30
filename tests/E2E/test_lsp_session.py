"""#185 over the LSP: an editor buffer's temp copy (#106) declares the same class
as the saved file at another path. The session must answer it exactly as the
pristine fork would, and must not keep anything of the unsaved buffer for a
later call on a saved file.

The project resolves every class through its own autoloader (PHPStan's standard
locator chain), which is the case where the session serves the buffer from a
child forked off the warm session. The oracle is a cold `rector process
--dry-run` on the same content at the same path, as in test_lsp_diagnostics.py.
"""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import time
from pathlib import Path

import pytest

from mcp_harness import NO_PCNTL_PLATFORM, SESSION_EXPECTED, SESSION_LOG_PREFIX, SESSION_TERMINAL_EVENTS
from test_lsp_initialize import BIN, frame, php_binary, read_frame

REPO = Path(__file__).resolve().parents[2]
RECTOR = REPO / "vendor" / "bin" / "rector"

CONFIG = """<?php

declare(strict_types=1);

use Rector\\Config\\RectorConfig;
use Rector\\Php83\\Rector\\ClassMethod\\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\\ValueObject\\PhpVersion;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withRules([AddOverrideAttributeToOverriddenMethodsRector::class]);
"""

AUTOLOAD = """<?php

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\\\')) {
        $file = __DIR__ . '/../src/' . str_replace('\\\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
"""

BASE = """<?php

declare(strict_types=1);

namespace App;

class Base
{
    public function run(): void
    {
        echo 'base';
    }
}
"""

MID = """<?php

declare(strict_types=1);

namespace App;

class Mid extends Base
{
}
"""

# The unsaved buffer: Mid gains run() (overrides Base, must be flagged) and ping().
MID_BUFFER = """<?php

declare(strict_types=1);

namespace App;

class Mid extends Base
{
    public function run(): void
    {
        echo 'mid';
    }

    public function ping(): void
    {
        echo 'mid ping';
    }
}
"""

MID_SAVED_WITH_PING = """<?php

declare(strict_types=1);

namespace App;

class Mid extends Base
{
    public function ping(): void
    {
        echo 'mid ping';
    }
}
"""

LEAF = """<?php

declare(strict_types=1);

namespace App;

final class Leaf extends Mid
{
    public function ping(): void
    {
        echo 'leaf ping';
    }
}
"""


def build(root: Path) -> None:
    for relative, content in {
        "rector.php": CONFIG,
        "vendor/autoload.php": AUTOLOAD,
        "src/Base.php": BASE,
        "src/Mid.php": MID,
        "src/Leaf.php": LEAF,
    }.items():
        target = root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding="utf-8")


def cold_changed(project: Path, relative: str, content: str | None = None) -> int:
    """Oracle: files a cold dry run reports changed, with `content` (if given)
    written at `relative` in a fresh copy of the project."""
    cold = project.parent / "cold"
    if cold.exists():
        shutil.rmtree(cold)
    shutil.copytree(project, cold, ignore=shutil.ignore_patterns(".rector-warm-*"))
    if content is not None:
        (cold / relative).write_text(content, encoding="utf-8")
    done = subprocess.run(
        [php_binary(), str(RECTOR), "process", "--dry-run", "--config=rector.php", "--no-progress-bar",
         "--output-format=json", "--", relative],
        cwd=cold, capture_output=True, text=True, timeout=120,
    )
    return int(json.loads(done.stdout[done.stdout.index("{"):])["totals"]["changed_files"])


def diagnostics_for(proc, uri: str) -> list:
    while True:
        message = read_frame(proc.stdout)
        if message.get("method") == "textDocument/publishDiagnostics" and message["params"]["uri"] == uri:
            return message["params"]["diagnostics"]


def send(proc, message: dict) -> None:
    proc.stdin.write(frame(message))
    proc.stdin.flush()


def open_doc(proc, uri: str) -> list:
    send(proc, {"jsonrpc": "2.0", "method": "textDocument/didOpen",
                "params": {"textDocument": {"uri": uri, "languageId": "php", "version": 1, "text": ""}}})
    return diagnostics_for(proc, uri)


def save_doc(proc, uri: str, version: int) -> list:
    send(proc, {"jsonrpc": "2.0", "method": "textDocument/didSave",
                "params": {"textDocument": {"uri": uri, "version": version}}})
    return diagnostics_for(proc, uri)


def change_doc(proc, uri: str, version: int, text: str) -> list:
    send(proc, {"jsonrpc": "2.0", "method": "textDocument/didChange",
                "params": {"textDocument": {"uri": uri, "version": version}, "contentChanges": [{"text": text}]}})
    return diagnostics_for(proc, uri)


def terminal_events(log: Path, count: int, timeout: float = 5.0) -> list[tuple[str, str]]:
    """The session log's events once `count` terminal events are in (the
    worker writes each one just before it replies)."""
    deadline = time.monotonic() + timeout
    while True:
        events = []
        for line in log.read_text(encoding="utf-8", errors="replace").splitlines():
            if line.startswith(SESSION_LOG_PREFIX):
                event, _, detail = line[len(SESSION_LOG_PREFIX):].partition(":")
                events.append((event.strip(), detail.strip()))
        if sum(e in SESSION_TERMINAL_EVENTS for e, _ in events) >= count or time.monotonic() >= deadline:
            return events


@pytest.mark.skipif(NO_PCNTL_PLATFORM, reason="no session without pcntl (#108 standby worker per call)")
def test_a_buffer_copy_is_served_from_the_session_and_leaves_nothing_behind_in_it(tmp_path: Path) -> None:
    project = tmp_path / "project"
    build(project)
    log = tmp_path / "server.stderr"
    with open(log, "w", encoding="utf-8") as errlog:
        proc = subprocess.Popen(
            [php_binary(), str(BIN), f"--working-dir={project}"],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=errlog,
            env={**os.environ, "MCP_RECTOR_WARM_SESSION_LOG": "1"},
        )
        try:
            send(proc, {"jsonrpc": "2.0", "id": 1, "method": "initialize",
                        "params": {"processId": None, "rootUri": None, "capabilities": {}}})
            read_frame(proc.stdout)
            mid = (project / "src" / "Mid.php").as_uri()
            leaf = (project / "src" / "Leaf.php").as_uri()

            # 1-2. Warm the session on saved files; nothing to flag on disk.
            assert open_doc(proc, leaf) == [] and cold_changed(project, "src/Leaf.php") == 0
            assert open_doc(proc, mid) == [] and cold_changed(project, "src/Mid.php") == 0

            # 3. The unsaved buffer: flagged exactly as a cold run on that content.
            flagged = change_doc(proc, mid, 2, MID_BUFFER)
            assert len(flagged) == 1 and "AddOverrideAttributeToOverriddenMethodsRector" in flagged[0]["message"]
            assert cold_changed(project, "src/Mid.php", MID_BUFFER) == 1

            # 4. Must not fire: the buffer's ping() was never saved, so Leaf::ping()
            #    overrides nothing -- for a cold run, and for the session after
            #    it served the buffer.
            assert save_doc(proc, leaf, 2) == [] and cold_changed(project, "src/Leaf.php") == 0

            # 5-6. Must fire (the positive control for step 4): Mid saved WITH
            #      ping() makes Leaf::ping() an override.
            (project / "src" / "Mid.php").write_text(MID_SAVED_WITH_PING, encoding="utf-8")
            save_doc(proc, mid, 3)
            flagged = save_doc(proc, leaf, 3)
            assert len(flagged) == 1 and cold_changed(project, "src/Leaf.php") == 1
        finally:
            if proc.poll() is None:
                proc.kill()
            proc.wait(timeout=10)

    events = terminal_events(log, 6)
    terminals = [event for event, _ in events if event in SESSION_TERMINAL_EVENTS]
    if not SESSION_EXPECTED:
        assert events == [], f"the session is off, yet it logged: {events!r}"
        return
    # Leaf, Mid, the buffer (forked from the session), Leaf again, saved Mid, Leaf.
    assert terminals == ["serve", "serve", "fork", "serve", "serve", "serve"], events
    # Exactly one fresh session after the start: for the saved Mid, never for the buffer.
    names = [event for event, _ in events]
    assert names.count("respawn") == 1, events
    assert names.index("respawn") > names.index("fork"), events
