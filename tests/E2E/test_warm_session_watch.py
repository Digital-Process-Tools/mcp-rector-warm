"""#185, PR #189 finding A: a custom rule that reads files itself at call time
(file_get_contents, a JSON config) and caches them in a static.

Before the session, every call forked from a pristine worker whose static was
still empty, so the rule re-read its inputs on every call. Inside the session
the static survives, and nothing tells the session those files are inputs --
so an edit to them is served stale. That limit is documented (docs/how-it-works.md
§1b); MCP_RECTOR_WARM_SESSION_WATCH declares such inputs so their changes start
a fresh session.

The first test pins the limit (must fire: stale without the declaration, which
is also the positive control that this harness can see staleness at all); the
second shows the declaration keeps every answer equal to cold.

#216: a third test takes the same staleness-inducing edit and shows that the
WRITE Rector performs after it -- unlike the dry run above -- always equals
cold, because a dryRun:false call never reaches the session (RectorRunner::
sessionCandidate()'s `!$dryRun` guard, unconditional, predating #216). Reading
a stale dry run in CI is an accepted limit (a cold CI run is the backstop);
writing stale bytes into the user's file never is.
"""

from __future__ import annotations

import os
import shutil
import time
from pathlib import Path

import anyio
import pytest

from mcp_harness import (
    NO_PCNTL_PLATFORM,
    SESSION_LOG_ENV,
    SESSION_OPT_OUT,
    normalise,
    open_server,
    run_cold,
    session_active,
    session_events,
    stderr_size,
    structured,
)

CONFIG = r"""<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use Rector\Config\RectorConfig;
use Rector\Rector\AbstractRector;

// The usual "read my inputs once, keep them in a static" custom rule.
final class MapStringsFromJsonRector extends AbstractRector
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
    ->withRules([MapStringsFromJsonRector::class]);
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

final class Greeter
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
        "src/Greeter.php": SOURCE,
    }.items():
        target = root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding="utf-8")


def cold(root: Path, n: int) -> dict:
    """A cold run on a fresh copy (a fresh path: no Rector cache entry to reuse)."""
    copy = root.parent / f"cold-{n}"
    shutil.copytree(root, copy)
    return normalise(run_cold(copy.resolve(), "src/Greeter.php", True), copy.resolve())


def run_steps(tmp_path: Path, extra_env: dict[str, str]) -> tuple[list[dict], list[dict], list[list[tuple[str, str]]]]:
    root = tmp_path / "project"
    build(root)
    root = root.resolve()
    record = tmp_path / "record"
    env = {**SESSION_LOG_ENV, **extra_env}
    if not SESSION_OPT_OUT:
        env["MCP_RECTOR_WARM_SESSION"] = "1"
    warm: list[dict] = []
    colds: list[dict] = []
    events: list[list[tuple[str, str]]] = []

    async def run() -> None:
        async with open_server(root, record, root / "rector.php", extra_env=env) as server:
            for step in range(2):
                if step == 1:
                    target = root / "config" / "map.json"
                    target.write_text('{"hello": "there"}', encoding="utf-8")
                    later = time.time() + 10
                    os.utime(target, (later, later))
                offset = stderr_size(record)
                result = await server.process(root / "src" / "Greeter.php")
                warm.append(normalise(structured(result), root))
                events.append(session_events(record, offset, wait_for_terminal=session_active(True)))
                colds.append(cold(root, step))

    anyio.run(run)
    return warm, colds, events


def test_an_undeclared_input_read_by_a_custom_rule_goes_stale_in_the_session(tmp_path: Path) -> None:
    warm, colds, events = run_steps(tmp_path, {})
    assert warm[0] == colds[0] and "'world'" in "".join(warm[0]["diffs"].values())
    assert "'there'" in "".join(colds[1]["diffs"].values())
    if not session_active(True):
        # Fork per call (session off, or no pcntl): the static starts empty every time.
        assert warm[1] == colds[1]
        return
    # Must fire: the documented limit. Nothing told the session map.json is an
    # input, so the rule's static still holds the old map.
    assert [e for e, _ in events[1]] == ["serve"], events[1]
    assert warm[1] != colds[1]
    assert "'world'" in "".join(warm[1]["diffs"].values())


def test_a_declared_input_starts_a_fresh_session_and_stays_equal_to_cold(tmp_path: Path) -> None:
    warm, colds, events = run_steps(tmp_path, {"MCP_RECTOR_WARM_SESSION_WATCH": "config"})
    assert warm == colds
    if session_active(True):
        names = [e for e, _ in events[1]]
        assert "respawn" in names and names[-1] == "serve", events[1]
        assert any("map.json" in detail for e, detail in events[1] if e == "respawn"), events[1]


def test_a_write_after_the_undeclared_edit_still_equals_cold(tmp_path: Path) -> None:
    """#216: with NO env var set at all -- the new default, what every real
    user hits without opting into anything -- the session is on, so the dry
    run above (test_an_undeclared_input_read_by_a_custom_rule_goes_stale_in_
    the_session) can be stale after the map.json edit. The write never is,
    because `dryRun:false` never reaches the session at all (RectorRunner::
    sessionCandidate()'s unconditional `!$dryRun` guard, #72, unchanged by
    #216). Before #216 this scenario only existed for someone who explicitly
    opted in; #216 makes it the ambient default, which is exactly what this
    test must now cover -- setting MCP_RECTOR_WARM_SESSION=1 explicitly here
    would also pass on the pre-#216 code and prove nothing about the flip
    itself."""
    if NO_PCNTL_PLATFORM:
        pytest.skip("no session without pcntl -- nothing #216-specific to pin here")
    root = tmp_path / "project"
    build(root)
    root = root.resolve()
    record = tmp_path / "record"
    env = {**SESSION_LOG_ENV}

    async def run() -> None:
        async with open_server(root, record, root / "rector.php", extra_env=env) as server:
            # Seed the session exactly like run_steps()'s own step 0.
            await server.process(root / "src" / "Greeter.php")
            # The same staleness-inducing edit the other two tests make.
            target = root / "config" / "map.json"
            target.write_text('{"hello": "there"}', encoding="utf-8")
            later = time.time() + 10
            os.utime(target, (later, later))
            # The write: dryRun:false, so it can never be session-served,
            # whatever state the session is in after the edit above.
            result = await server.process(root / "src" / "Greeter.php", dry_run=False)
            assert structured(result).get("exit_code") == 0, structured(result)

    anyio.run(run)
    warm_written = (root / "src" / "Greeter.php").read_text(encoding="utf-8")

    cold_copy = tmp_path / "cold-write"
    build(cold_copy)
    # The identical edit, made before the only (cold) call touches this copy
    # at all -- there is no prior call and no session here to go stale.
    (cold_copy / "config" / "map.json").write_text('{"hello": "there"}', encoding="utf-8")
    cold_result = run_cold(cold_copy.resolve(), "src/Greeter.php", False)
    assert cold_result["exit_code"] == 0, cold_result
    cold_written = (cold_copy / "src" / "Greeter.php").read_text(encoding="utf-8")

    assert warm_written == cold_written
    assert "'there'" in warm_written, warm_written
