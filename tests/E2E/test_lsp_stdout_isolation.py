"""#194: a rule/project file writing straight to STDOUT/STDERR/php://stdout during
analysis must not corrupt the Content-Length-framed LSP stream either -- the same
bug as tests/E2E/scenarios/warm-stdout-direct-write.yaml, exercised over the LSP
wire format instead of MCP's. Same hand-rolled client as test_lsp_initialize.py
(see its module docstring for why this does not use a library)."""

from __future__ import annotations

import subprocess
from pathlib import Path

from test_lsp_initialize import BIN, frame, php_binary, read_frame

NOISY_CONFIG = r"""<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use Rector\Config\RectorConfig;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Rector\AbstractRector;
use Rector\ValueObject\PhpVersion;

final class NoisyLspRector extends AbstractRector
{
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    public function refactor(Node $node): ?Node
    {
        \fwrite(\STDOUT, "direct fwrite(STDOUT) noise\n");
        \fwrite(\STDERR, "direct fwrite(STDERR) noise\n");
        $stdout = \fopen('php://stdout', 'wb');
        if ($stdout !== false) {
            \fwrite($stdout, "php://stdout noise\n");
            \fclose($stdout);
        }
        echo "echo noise\n";
        print "print noise\n";
        @\trigger_error('a warning during analysis', \E_USER_WARNING);

        return null;
    }
}

return RectorConfig::configure()
    ->withPaths([__DIR__ . "/src"])
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withRules([NoisyLspRector::class, ReadOnlyClassRector::class]);
"""

MONEY_PHP = """<?php

declare(strict_types=1);

final class Money
{
    public function __construct(private readonly int $amount)
    {
    }
}
"""


def noisy_project(tmp_path: Path) -> Path:
    project = tmp_path / "noisy-project"
    (project / "src").mkdir(parents=True)
    (project / "rector.php").write_text(NOISY_CONFIG, encoding="utf-8")
    (project / "src" / "Money.php").write_text(MONEY_PHP, encoding="utf-8")
    return project


def test_did_open_with_a_noisy_rule_publishes_only_valid_frames(tmp_path: Path) -> None:
    project = noisy_project(tmp_path)
    proc = subprocess.Popen(
        [php_binary(), str(BIN), f"--working-dir={project}"],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    try:
        proc.stdin.write(frame({
            "jsonrpc": "2.0", "id": 1, "method": "initialize",
            "params": {"processId": None, "rootUri": None, "capabilities": {}},
        }))
        proc.stdin.flush()
        init_reply = read_frame(proc.stdout)
        assert init_reply.get("id") == 1, init_reply

        uri = (project / "src" / "Money.php").as_uri()
        proc.stdin.write(frame({
            "jsonrpc": "2.0",
            "method": "textDocument/didOpen",
            "params": {"textDocument": {"uri": uri, "languageId": "php", "version": 1, "text": ""}},
        }))
        proc.stdin.flush()

        # read_frame() itself is the assertion: a stray noise line interleaved
        # between two real frames gets swallowed into ITS OWN header search
        # (it has no CRLFCRLF terminator in it), so the next real frame's
        # header line fails the "no Content-Length header" assertion
        # deterministically -- it can never silently pass -- and pytest.ini's
        # suite-wide 600s timeout (every test spawns PHP; a hung server must
        # fail the test, not the CI job) bounds a genuine hang instead of
        # this test adding its own.
        notification = read_frame(proc.stdout)
        assert notification["method"] == "textDocument/publishDiagnostics"
        assert notification["params"]["uri"] == uri
        diagnostics = notification["params"]["diagnostics"]
        assert len(diagnostics) == 1, diagnostics
        assert diagnostics[0]["source"] == "rector"
    finally:
        if proc.poll() is None:
            proc.kill()
        proc.wait(timeout=10)
