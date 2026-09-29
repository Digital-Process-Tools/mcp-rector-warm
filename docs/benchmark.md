# Benchmark

**~10× faster per call.** The first call boots Rector once, costing about the same as one cold run (6.4s). Every call after that takes 0.68s (median) instead of 7.02s cold.

## Numbers

Measured with `tools/warm-vs-cold.py` on a real private production codebase (PHP 8.2.0, Apple Silicon, v0.5.0 at `4902c3d`): 20 files sampled across the tree, each run once through one warm server session and once through a fresh `rector process --dry-run`, cold runs serial so neither side shares CPU with the other.

| Setup | median | p95 | Notes |
|-------|--------|-----|-------|
| Cold `rector process`, every call | 7.02s | 9.00s | autoloader + container + ruleset each time |
| Warm, start + handshake | 0.1s | | the container is not built yet |
| Warm, first call (one-time boot, once per session) | 6.4s | | builds the container: costs about one cold run |
| **Warm, every later call** | **0.68s** | **2.40s** | container reused |

**~10× faster per call at the median.** Across all 20 files: **144s cold → 30s warm**, the 30s including the one-time boot. All 20 warm answers matched the cold ones.

## The first call

The start and first-call rows come from a separate probe: 3 fresh sessions, each starting with the same small file, which takes 5.9-7.3s cold. The first call took 6.37-6.42s each time, and a second, different file then took 0.28-0.31s.

The server builds the container on the first call, not at start, so an idle server costs nothing. The first call costs about the same as running Rector once without the server.

## Caveats

Numbers vary with project size and rule set. The win is the cold-start amortization, not magic.

On Windows and other PHP builds without pcntl the warm path works differently, and so do its numbers: see [How it works](how-it-works.md#windows-and-other-php-builds-without-pcntl).

## Reproduce it

On your own project:

```bash
python3 tools/warm-vs-cold.py --project /path/to/project --files 'src/**/*.php' --limit 20 --jobs 1 --out /tmp/wvc
```

The script needs the Python MCP client from `tests/E2E/requirements.txt`, and writes the timings to `report.md` in `--out`.
