# Benchmark

**7–9.7× faster per file on Laravel and Symfony**, with the warm session on (`MCP_RECTOR_WARM_SESSION=1`). Every warm answer was byte-identical to a cold `rector process`.

## Numbers

Time to dry-run one file. Warm figures exclude the first call, which is listed on its own.

| Project | Mode | Warm p50 / p95 | Cold p50 / p95 | First warm call |
|---------|------|----------------|----------------|-----------------|
| laravel/framework v13.34.0 | **session on** | **76 / 1243 ms** | 740 / 2271 ms | 1531 ms |
| laravel/framework v13.34.0 | default (session off) | 224 / 1672 ms | 749 / 2008 ms | 1570 ms |
| symfony/symfony v7.4.20 | **session on** | **100 / 447 ms** | 696 / 1286 ms | 2255 ms |
| symfony/symfony v7.4.20 | default (session off) | 159 / 557 ms | 659 / 1172 ms | 1872 ms |

At the median: Laravel 740 → 76 ms (9.7×) and Symfony 696 → 100 ms (7.0×) with the session on; Laravel 749 → 224 ms (3.3×) and Symfony 659 → 159 ms (4.1×) in the default mode. Each mode was its own run, so each has its own cold column.

**Every warm result was byte-identical to its cold result: 30 of 30 files, in every run.** `tools/warm-vs-cold.py` compares Rector's JSON report (changed files, diffs, applied rules) from the warm server against a fresh `rector process --dry-run` on the same bytes. CI runs the same warm-vs-cold comparison on its own scenarios.

The first warm call boots Rector and analyses the first file. It costs two to three cold runs, once per server. The server boots on that first call, not at start, so an idle server costs nothing.

## Machine and method

- Apple M3 Pro, 18 GB, macOS 15.3.2.
- PHP 8.3.11 NTS, no OPcache, no Xdebug.
- laravel/framework v13.34.0 (commit `c829b4982d29344cbcf1d7ad78eb5d2bb8ae66c4`) with its own Rector 2.6.3.
- symfony/symfony v7.4.20 (commit `9f4a21e7d0efb5c0e2d3ba108738ec07358a5925`) with rector/rector 2.6.7 and phpstan/phpstan 2.2.16 added.
- 30 files per project, each run through one warm server and through its own fresh `rector process --dry-run`. Cold calls ran one at a time (`--jobs 1`), so neither side shared CPU with the other.
- Each run started from an empty `TMPDIR`, and every cold call ran in its own fresh `TMPDIR`.
- Measured 2026-09-30.

### Files

30 files per project, spread evenly over the sorted source tree, tests, resources and fixtures left out:

```bash
git ls-files 'src/*.php' | grep -v -i -E '/(tests|resources|fixtures)/' | LC_ALL=C sort \
  | awk -v n=53 'NR % n == 1' | head -n 30 > ../laravel-files.txt
```

That is every 53rd line from line 1 for Laravel. Symfony uses every 137th (`n=137`).

### Config

Both projects used the same `rector-bench.php` in their root:

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withSkip(['*/Tests/*', '*/Resources/*', '*/resources/*'])
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true);
```

## The two modes

**Session on** (`MCP_RECTOR_WARM_SESSION=1`) is the fastest mode. One long-lived session child serves every single-file dry run and keeps PHPStan's reflection and the rules' caches between calls. Before each call it checks every file the analysis relied on, and starts a fresh session if one changed. It is off by default.

What it cannot see: inputs a custom rule reads by itself at call time, for example with `file_get_contents()`, `glob()` or `json_decode()`, and keeps in a static. An edit to such a file is not seen until the session is replaced. List those files or directories in `MCP_RECTOR_WARM_SESSION_WATCH`, or leave the session off. What the session watches, and what it cannot, is in [How it works, 1b](how-it-works.md#1b-one-session-child-keeps-the-analysis-warm-between-calls-185).

**Default** (session off): every call forks from a worker that has booted Rector but analysed nothing, so each call starts clean.

**Windows and PHP builds without pcntl** have no session and no fork. They use a per-call standby worker, and these numbers were not measured there: see [How it works](how-it-works.md#windows-and-other-php-builds-without-pcntl).

## A large private codebase

Also measured on the same machine class: a private production codebase of about 136k files with a heavy custom rule set, PHP 8.2, 24 files run twice each.

| Mode | Warm p50 / p95 |
|------|----------------|
| session on | 247 / 1709 ms |
| default (session off) | 2054 / 6809 ms |

Cold was not measured in the same run. A separate cold measurement on the same project, also 24 files, gave about 10.3 s p50 and 23.7 s p95. The two were not taken together, so no ratio is given here.

## Caveats

Numbers vary with project size and rule set. Most of a cold run is Rector booting and loading the config; the warm server pays that once. A project with a heavy config gains more than one with a light config.

## Reproduce it

```bash
git clone --depth 1 --branch v13.34.0 https://github.com/laravel/framework laravel
(cd laravel && composer install && composer require --dev 'mcp/sdk:^0.7.1')
git clone --depth 1 --branch v7.4.20 https://github.com/symfony/symfony symfony
(cd symfony && composer install && composer require --dev 'rector/rector:^2.6' 'mcp/sdk:^0.7.1' 'phpstan/phpstan:2.2.16')
# write rector-bench.php (above) into each project root, and build each file list as above
```

Then, in an mcp-rector-warm checkout, for each project `<p>`:

```bash
composer install
python3 -m venv venv && venv/bin/pip install -r tests/E2E/requirements.txt
TMPDIR=$(mktemp -d) MCP_RECTOR_WARM_SESSION=1 venv/bin/python tools/warm-vs-cold.py --php "$(which php)" \
  --project <p> --config <p>/rector-bench.php --project-autoload <p>/vendor/autoload.php \
  --files @<p>-files.txt --jobs 1 --timeout 300 --progress --out out/<p>
```

Drop `MCP_RECTOR_WARM_SESSION=1` for the default mode. The script writes the timings and a match verdict per file to `report.md` in `--out`, and exits non-zero if any warm answer differs from cold. It gives each cold process its own Rector cache directory. Compare the "warm, later calls" row with cold: the "warm, first call" row includes the boot.

On your own project, `--files 'src/**/*.php' --limit 30` samples the files for you.
