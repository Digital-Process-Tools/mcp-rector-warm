#!/usr/bin/env bash
# #109: minimal Helix smoke check of docs/lsp.md's own languages.toml snippet.
#
#   tests/E2E/nvim/run_helix_health.sh
#
# Extracts the `helix-languages` snippet straight out of docs/lsp.md, points a
# scratch XDG_CONFIG_HOME at it, and runs `hx --health php`. Helix's own
# `--health` never sets a non-zero exit code either way (verified by hand:
# a working and a broken PATH both exit 0), so the assertion has to be on
# the printed text -- a configured, reachable server prints
# "rector-warm-lsp: <path>" on its own line; one that is missing or
# unreachable prints "... not found in $PATH" on the SAME line instead.
# That pairing is the positive control: this script fails loudly on the
# "not found" text, not just on a plain grep for the server's name (which
# a broken config also prints, right next to the failure message).
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../../.." && pwd)"

hx_bin="${HELIX_BINARY:-hx}"
if ! command -v "$hx_bin" >/dev/null 2>&1; then
    echo "run_helix_health.sh: no '$hx_bin' on PATH -- install Helix first" >&2
    exit 2
fi

python="${E2E_PYTHON:-python3}"

scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT

mkdir -p "$scratch/helix"
"$python" "$here/extract_snippet.py" helix-languages "$repo/docs/lsp.md" > "$scratch/helix/languages.toml"

export PATH="$repo/bin:$PATH"
export XDG_CONFIG_HOME="$scratch"

out="$("$hx_bin" --health php 2>&1)"
echo "$out"

if ! printf '%s\n' "$out" | grep -q 'rector-warm-lsp'; then
    echo "run_helix_health.sh: 'rector-warm-lsp' never appears in --health output -- languages.toml was not picked up" >&2
    exit 1
fi

if printf '%s\n' "$out" | grep -q 'rector-warm-lsp.*not found in \$PATH'; then
    echo "run_helix_health.sh: rector-warm-lsp is configured but Helix cannot find it on PATH" >&2
    exit 1
fi

echo "run_helix_health.sh: OK"
