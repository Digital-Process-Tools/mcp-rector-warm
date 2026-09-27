#!/usr/bin/env bash
# Run the end-to-end suite: the official Python MCP client against bin/mcp-rector-warm.
#
#   tests/E2E/run.sh                      # whole suite
#   tests/E2E/run.sh -k two-files         # extra args go to pytest
#
# Needs: python3 (>= 3.10), php, and `composer install` already run at the repo root.
# PHP_BINARY picks the interpreter when `php` on PATH is not the one you want.
# E2E_PYTHON picks the interpreter that runs pytest (CI sets it; locally a
# virtualenv is created in tests/E2E/.venv and reused).
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../.." && pwd)"

if [ ! -f "$repo/vendor/autoload.php" ]; then
    echo "run.sh: vendor/ is missing -- run 'composer install' at the repo root first" >&2
    exit 2
fi

python="${E2E_PYTHON:-}"
if [ -z "$python" ]; then
    venv="$here/.venv"
    if [ ! -x "$venv/bin/python" ]; then
        python3 -m venv "$venv"
    fi
    python="$venv/bin/python"
    # Reinstall only when requirements.txt changed since the last install.
    stamp="$venv/.requirements.installed"
    if ! cmp -s "$here/requirements.txt" "$stamp" 2>/dev/null; then
        "$python" -m pip install --quiet --disable-pip-version-check -r "$here/requirements.txt"
        cp "$here/requirements.txt" "$stamp"
    fi
fi

cd "$here"
exec "$python" -m pytest -p no:cacheprovider "$@"
