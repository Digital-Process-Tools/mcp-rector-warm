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

## 1b. One session child keeps the analysis warm between calls (#185)

Forking every call from the pristine worker keeps each call isolated, but it throws away everything a call learned. On a large project that is most of a warm call: PHPStan reflecting the same dependency classes, and project rules rebuilding the same indexes, every time.

With `MCP_RECTOR_WARM_SESSION=1` in the server's environment, the worker forks **one long-lived session child** and sends it every single-file dry run. The session analyses in-process and keeps those caches. **It is off by default**: read the next warning before turning it on.

> **With the session on, inputs a custom rule reads by itself at call time are not watched, and can go stale.** A rule whose `refactor()` does `file_get_contents()`, `glob()` or `json_decode()` on some file and keeps the result in a static builds that index once per session, not once per call. Without the session, every call started from a pristine worker whose static was still empty, so the rule re-read its inputs each time. With it, an edit to those files is not seen until the session is replaced. Declare such inputs with `MCP_RECTOR_WARM_SESSION_WATCH` (below), or leave the session off. This is not the same as what a rule reads while `rector.php` loads: that is pinned in the pristine worker with or without the session.

Before each call the session checks that nothing it relied on has changed:

- every file PHPStan parsed (parent classes, interfaces, traits, anything reflected). The hook is a decorator on PHPStan's own `pathRoutingParser` service, which is the parser every BetterReflection source locator, the PHPDoc resolver and the trait handler go through;
- every file PHP included during a call, and every file a call analysed;
- the files under `withAutoloadPaths()` and PHPStan `scanDirectories`, which PHPStan scans for symbols wholesale, plus Composer's metadata (`vendor/composer/installed.json`, `autoload_*.php`);
- the listing of every project directory. A class lookup that found nothing is remembered by PHPStan and by most autoloaders, so a class file created later would otherwise never be seen.

A file is compared by size, mtime, inode and ctime, plus a content hash when the mtime is within a second of when the file was recorded. The ctime catches a same-size edit whose mtime was put back (`touch -r`, `cp -p`, `rsync -t --inplace`). If anything differs, the session exits and the worker forks a fresh one from the still-pristine worker. That fresh session costs what the fork always cost.

Some calls never run inside the session:

- **Write calls, directories, several paths**: these fork from the pristine worker, as before.
- **A file whose classes nothing else finds.** A cold run on another file cannot see such a class. If the session analysed the file, it would keep the class for later calls. These calls are declined and fork from the pristine worker. Examples: a class outside the autoload paths with no autoloader entry, or a second declaration of an existing class.
- **An editor buffer's temp copy** (LSP, #106) declares the same class as the saved file. When PHPStan's standard locators find every class in it, a cold run takes those classes from the saved file, as the session does. The buffer then runs in a child forked from the session: it gets the warm caches, and whatever the buffer adds dies with that child. Otherwise it forks from the pristine worker.

What the session cannot see, and a cold run would: inputs that are not read through PHPStan's parser or PHP's `include`, unless declared. Examples are a PHP, template, XML or JSON file a custom rule reads with `file_get_contents()` at call time, an environment variable, or the clock. Also a file created in a hidden directory or with an extension other than `.php`, `.inc` or your configured file extensions, outside the autoload paths.

**Declaring such inputs.** `MCP_RECTOR_WARM_SESSION_WATCH` lists files or directories, relative to the project (or absolute), separated like `PATH` (`:` on macOS/Linux). Every file under a listed directory, whatever its extension, is tracked like a parsed PHP file: a changed, created or deleted file there starts a fresh session. A listed file that does not exist yet is watched too. Example: `MCP_RECTOR_WARM_SESSION_WATCH=config:src/Events`.

Limits and costs:

- **An edited file in the tracked set starts a new session.** Editing the file you keep re-checking, or any file under `withAutoloadPaths()`, therefore gives no speed-up on the next call, but costs no more than before.
- **Checking costs one `stat()` per tracked file and per project directory** before every call. That is a few milliseconds on a small project and about 50-90 ms on a tree with 16-26k directories. More than 100,000 directories turns the session off.
- **Memory**: the session holds the analysis caches, and they grow with every large file analysed. It retires itself after a call once its own memory passes `MCP_RECTOR_WARM_SESSION_MAX_MB` (default 512), after `MCP_RECTOR_WARM_SESSION_MAX_CALLS` calls (default 250), or past 75% of a finite `memory_limit`. The next call starts a fresh session.
- **No pcntl, no session.** Windows and builds without `pcntl` keep the per-call standby worker described below.

Without `MCP_RECTOR_WARM_SESSION=1` (or with `=0`), every call forks from the pristine worker, as before #185. `MCP_RECTOR_WARM_SESSION_LOG=1` makes the worker write one stderr line per call saying what served it (`serve`, `fork`, `decline`) and why a session was replaced (`respawn: changed: <file>`, `retire: <reason>`). If the hook does not fit an installed Rector or PHPStan, the session turns itself off at start with a `session disable:` line on stderr, and every call forks as before.

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
