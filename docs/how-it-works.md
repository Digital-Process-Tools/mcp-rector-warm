# How it works

Both entry points -- the [MCP server](mcp.md) and the [language server](lsp.md) -- run on the same warm core. Four decisions worth knowing.

## 1. One daemon per project; the container lives in a forked worker

The working dir pins at server startup, keeping `$_SERVER['argv']` clean for `RectorConfigsResolver`.

The Rector container always lives in a forked worker, never in the long-lived daemon process. The daemon only ever talks to a worker over a socket. Booting always happens in a process that has never booted before: `rector.php` is a plain PHP file `require`d while building the container, and re-`require`ing it in a process that already required it once is a PHP fatal ("Cannot declare class/function already declared") when the config declares a class or function. So re-editing `rector.php` between calls can never crash the server.

**Config changes take effect on the next call, without a restart.** Before every call the runner hashes:

- the resolved `rector.php`/`rector.dist.php` (whichever `RectorConfigsResolver` would pick);
- every file the config registered via `withBootstrapFiles()`;
- the project's `composer.json` (`withPhpSets()` with no argument reads its `require.php` once at boot to pick its rule sets).

A changed hash on any of them tears the current worker down and forks a brand-new one.

Limits:

- Only the main config file, its declared bootstrap files and `composer.json` are hashed. A `rector.php` that `require`s some other shared file directly (not via `withBootstrapFiles()`) is not tracked: touch or edit the main config file too, to force a reboot after changing what it includes.
- `composer.json` is watched wholesale, not just its `require.php`: a project whose config never calls bare `withPhpSets()` still reboots on an unrelated `composer.json` edit (a new dependency, a reformat). The same coarse trade-off applies to the main config file.

**The project's own autoloader.** The target project's `vendor/autoload.php` (if present) is loaded once per worker, the same way cold Rector's own CLI loads it for a composer-global install, so a class that only resolves through the project's Composer autoloader is visible to warm calls too. A `composer dump-autoload` mid-session is not picked up until the worker's next reboot.

## 2. Parallel mode forcibly disabled (`--debug` flag)

Rector's worker fork model expects `$_SERVER['argv'][0]` to be the rector CLI binary. From this server it isn't, so workers can't respawn. Single-thread analysis only — that's fine for the per-file edit loop this is designed for.

## 3. Runtime-prefixed namespace handled

Rector's bundled Symfony is namespaced `RectorPrefix<date>\\Symfony\\Component\\Console\\...` to avoid dependency conflicts. The runner detects the prefix at boot and resolves Application/Input/Output class names dynamically. Survives Rector version bumps.

## 4. Nothing can corrupt the protocol stdout

A missing config, a config with zero rules, or a project's own `rector.php` printing while it loads cannot corrupt the JSON-RPC stream on stdout.

Rector's CLI treats a missing `rector.php`, or one that loads fine but registers no rules or sets, as friendly onboarding, and Symfony's console output writes straight to the real stdout stream, bypassing an `ob_start()` wrap entirely. A project's `rector.php` can also `echo`, or trigger a notice or deprecation, while it loads. None of that reaches the JSON-RPC pipe:

- Both a missing config and a config with zero registered rules are refused as a real, reported error *before* any of Rector's own console machinery runs. This is checked per call, not at server startup: a `rector.php` fixed up later just works on the next call.
- Config resolution and container boot run inside an output buffer.
- PHP's own error display is pointed at stderr (`display_errors=stderr`).

## Windows and other PHP builds without pcntl

PHP on Windows has no `pcntl`, so there is no fork (the same holds for a build that
disables `pcntl_fork` in `disable_functions`). The server is still warm there, by a
different route: right after a call returns, it starts a **standby** `php` worker process that boots the
Rector container in the background. The next call is served by that already-booted
worker, which then exits; a fresh standby starts for the call after. Each call still runs
in a container nothing else was analysed in -- the same guarantee the forked worker gives
-- so the answers match a cold `rector process`.

Reusing one worker process for every call was measured and rejected: 25 of the 55 E2E
warm-vs-cold scenarios diverged (a dependency's edited method still answered with its old
return type, and even a second, unedited file was reported as unchanged).

What that costs compared with the fork:

- **The boot has to fit between calls.** A call that arrives while the standby is still
  booting waits for the rest of that boot -- never longer than a cold run, but not warm
  either. On a large project (20 files, ~7s boot, PHP 8.2, Apple Silicon): 0.75s per call
  (p50) with 15s between calls, against 7.4s cold; back-to-back calls with no pause,
  7.6s against 8.1s cold. The fork path does 0.56s back-to-back on the same files.
- **The config runs once per call**, as with cold Rector: side effects of loading
  `rector.php` (clearing a cache, say) happen before every call, not once per session.
- One idle `php` process holds a booted container between calls, as the forked worker does.

`MCP_RECTOR_WARM_NO_PCNTL=cold` in the server's environment turns this off: every call then
boots and runs in its own fresh `php` subprocess.
