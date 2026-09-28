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

import sys

import pytest

from mcp_harness import process_descendants


def test_process_descendants_skips_on_windows_instead_of_crashing(monkeypatch):
    monkeypatch.setattr(sys, "platform", "win32")

    with pytest.raises(pytest.skip.Exception):
        process_descendants(999999)


def test_process_descendants_does_not_skip_on_non_windows(monkeypatch):
    # Positive control paired with the test above: the guard must be
    # platform-gated, not an unconditional skip everywhere.
    monkeypatch.setattr(sys, "platform", "linux")

    # A pid that (almost certainly) has no descendants still exercises the
    # real `ps` invocation rather than skipping -- this only fails if `ps`
    # itself is unavailable on the platform running this test.
    result = process_descendants(999999)

    assert result == []
