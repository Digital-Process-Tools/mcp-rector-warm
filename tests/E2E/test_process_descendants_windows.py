"""#79/#97: `process_descendants()` (mcp_harness.py) shells out to `ps`,
which does not exist on Windows -- and Windows has no pcntl/fork either, so
the daemon never has a forked descendant there in the first place. Before a
real Windows CI leg could run, this had never been observed: an
unconditional `subprocess.run(["ps", ...])` would raise `FileNotFoundError`
there instead of a named skip, which is exactly the silent-pass-or-crash
shape every OS-specific check must avoid.

No real subprocess spawned here -- this only proves the platform gate
itself is wired the way the issue requires, with a positive control (the
second test) proving the guard is not an unconditional skip that would
make the first test pass for the wrong reason.
"""

from __future__ import annotations

import subprocess
import sys

import pytest

import mcp_harness
from mcp_harness import process_descendants


def test_process_descendants_skips_on_windows_instead_of_crashing(monkeypatch):
    monkeypatch.setattr(sys, "platform", "win32")

    with pytest.raises(pytest.skip.Exception):
        process_descendants(999999)


def test_process_descendants_does_not_skip_on_non_windows(monkeypatch):
    # Positive control paired with the test above: the guard must be
    # platform-gated, not an unconditional skip everywhere.
    #
    # Self-review finding: an earlier version of this test called the REAL
    # `ps` here instead of faking it. That works on the platform this repo
    # was developed on (macOS), but the windows-latest E2E leg #97 adds now
    # actually runs this file with sys.platform genuinely "linux"/"win32"
    # depending on which leg -- and forcing `sys.platform` to "linux" via
    # monkeypatch while the REAL host is Windows does not change what `ps`
    # binary (if any) is actually on PATH there. A real subprocess call
    # would make this "does it skip correctly" test depend on the calling
    # machine's own `ps` availability/flags, which is exactly what the
    # Windows guard exists to route around. Faking subprocess.run() proves
    # the guard is reached (not skipped) without depending on any real `ps`.
    monkeypatch.setattr(sys, "platform", "linux")
    calls = []

    def fake_run(cmd, **kwargs):
        calls.append(cmd)
        return subprocess.CompletedProcess(cmd, 0, stdout="  PID  PPID STAT\n", stderr="")

    monkeypatch.setattr(mcp_harness.subprocess, "run", fake_run)

    result = process_descendants(999999)

    assert calls, "the guard skipped instead of reaching the real ps invocation"
    assert result == []
