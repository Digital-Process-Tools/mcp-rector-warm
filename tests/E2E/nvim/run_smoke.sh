#!/usr/bin/env bash
# #109: headless-Neovim smoke test of docs/lsp.md's own LSP snippets.
#
#   tests/E2E/nvim/run_smoke.sh
#
# Extracts the native `vim.lsp.config` + `vim.lsp.enable('rector')` snippets
# straight out of docs/lsp.md (so this exercises what the docs actually
# says, not a frozen copy of it -- #94 found the native snippet broken on
# every Neovim that has vim.lsp.config, and docs rot silently unless
# something runs them), copies the existing lsp-project fixture
# (tests/Fixtures/lsp-project, already used by tests/E2E/test_lsp_diagnostics.py)
# to a scratch directory per file, and drives `nvim --headless` against the
# fixable file (expects >=1 diagnostic plus a code action) and the clean file
# (expects 0 diagnostics) via driver.lua.
#
# Needs: nvim (0.11.3+, native vim.lsp.config support), php, composer
# install already run at the repo root, and rector-warm-lsp resolvable on
# PATH (this script prepends bin/ itself).
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../../.." && pwd)"

if [ ! -f "$repo/vendor/autoload.php" ]; then
    echo "run_smoke.sh: vendor/ is missing -- run 'composer install' at the repo root first" >&2
    exit 2
fi

nvim_bin="${NVIM_BINARY:-nvim}"
if ! command -v "$nvim_bin" >/dev/null 2>&1; then
    echo "run_smoke.sh: no '$nvim_bin' on PATH -- install Neovim 0.11.3+ first" >&2
    exit 2
fi

python="${E2E_PYTHON:-python3}"

scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT

# Headless Neovim still wants a writable state/data/cache dir even under
# `-u NONE` (for shada, log files, etc.) -- point those at scratch rather
# than trusting $HOME to be writable, which a locked-down CI/sandbox user
# is not guaranteed to have.
export XDG_STATE_HOME="$scratch/xdg-state"
export XDG_DATA_HOME="$scratch/xdg-data"
export XDG_CACHE_HOME="$scratch/xdg-cache"
mkdir -p "$XDG_STATE_HOME" "$XDG_DATA_HOME" "$XDG_CACHE_HOME"

config_dir="$scratch/config"
mkdir -p "$config_dir/lsp"
"$python" "$here/extract_snippet.py" nvim-native-config "$repo/docs/lsp.md" > "$config_dir/lsp/rector.lua"
"$python" "$here/extract_snippet.py" nvim-native-enable "$repo/docs/lsp.md" > "$config_dir/enable.lua"

export PATH="$repo/bin:$PATH"

status=0

run_case() {
    local label="$1" fixture_file="$2" min_diagnostics="$3" want_code_action="$4"
    local project_dir="$scratch/$label"
    cp -R "$repo/tests/Fixtures/lsp-project" "$project_dir"
    local target="$project_dir/src/$fixture_file"

    echo "== $label: $fixture_file (min_diagnostics=$min_diagnostics) =="
    local out
    if ! out="$("$nvim_bin" --headless -u NONE -l "$here/driver.lua" "$config_dir" "$target" "$min_diagnostics" 2>&1)"; then
        echo "$out" >&2
        echo "run_smoke.sh: $label: nvim exited non-zero" >&2
        status=1
        return
    fi
    echo "$out"

    # driver.lua writes exactly one JSON object as its own line via io.write;
    # everything else in $out is headless Neovim's own echoed ex-command
    # output (":w" status, deprecation notices, ...) sharing the same
    # stdout. Take the last line that looks like a JSON object rather than
    # treating the whole capture as one JSON value.
    local json_line
    json_line="$(printf '%s\n' "$out" | grep '^{' | tail -1)"
    if [ -z "$json_line" ]; then
        echo "run_smoke.sh: $label: no JSON line found in driver output: $out" >&2
        status=1
        return
    fi

    # Self-review finding: `tail -1` trusts the LAST line starting with "{"
    # to be driver.lua's own JSON -- almost always true, but a deferred LSP
    # notification flushed after `qall!` could in principle also start with
    # "{". Parse everything in one python call and report a malformed line
    # as an ordinary per-case failure (status=1) rather than letting a
    # `json.loads` exception under `set -e` abort the whole script with a
    # raw traceback.
    local parsed
    if ! parsed="$("$python" -c '
import json, sys
try:
    data = json.loads(sys.argv[1])
    print(data["ok"])
    print(data["diagnostic_count"])
    print(data["has_code_action"])
except Exception as exc:
    print("PARSE_ERROR:" + str(exc))
' "$json_line")"; then
        echo "run_smoke.sh: $label: could not invoke python to parse driver output: $json_line" >&2
        status=1
        return
    fi
    if [[ "$parsed" == PARSE_ERROR:* ]]; then
        echo "run_smoke.sh: $label: driver output was not the expected JSON (${parsed#PARSE_ERROR:}): $json_line" >&2
        status=1
        return
    fi

    local ok diagnostic_count has_code_action
    ok="$(sed -n '1p' <<<"$parsed")"
    diagnostic_count="$(sed -n '2p' <<<"$parsed")"
    has_code_action="$(sed -n '3p' <<<"$parsed")"

    if [ "$ok" != "True" ]; then
        echo "run_smoke.sh: $label: driver reported failure: $json_line" >&2
        status=1
        return
    fi
    if [ "$diagnostic_count" -lt "$min_diagnostics" ]; then
        echo "run_smoke.sh: $label: expected >=$min_diagnostics diagnostics, got $diagnostic_count" >&2
        status=1
        return
    fi
    if [ "$min_diagnostics" -eq 0 ] && [ "$diagnostic_count" -ne 0 ]; then
        echo "run_smoke.sh: $label: expected exactly 0 diagnostics, got $diagnostic_count" >&2
        status=1
        return
    fi
    if [ "$want_code_action" = "yes" ] && [ "$has_code_action" != "True" ]; then
        echo "run_smoke.sh: $label: expected a code action, got none" >&2
        status=1
        return
    fi
    echo "run_smoke.sh: $label: OK"
}

run_case fixable Fixable.php 1 yes
run_case clean Clean.php 0 no

exit "$status"
