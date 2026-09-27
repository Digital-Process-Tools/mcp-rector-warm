---
title: "src/RectorRunner.php: never fclose(STDOUT)+reopen to suppress output -- crashes a real container boot silently"
match: "src/RectorRunner.php"
mode: once
---

**Tried and reverted (#14/#15).** `fclose(STDOUT)` + reopening the freed fd 1 (to alias it to
stderr's target, keeping the real MCP pipe alive via a separate `fopen('php://fd/1', 'wb')` dup)
works in a standalone script, but crashes a REAL Rector container boot
(`RectorContainerFactory::createFromBootstrapConfigs()`): the process exits 255 with **zero bytes
on stdout or stderr** -- no exception, nothing visible. Something inside Rector's Symfony DI
container build (or PHPStan's bootstrap) reads the `STDOUT` resource directly during a normal boot
(`stream_isatty(STDOUT)` or similar), not only on the SymfonyStyle-warning path #14 targets.

**Use instead:** `ini_set('display_errors', 'stderr')` plus `ob_start()`/`ob_end_clean()` around
the config-resolution + container-build step -- the same pattern `execute()` already uses around
`$application->run()`. Neither touches the `STDOUT` resource.

If a future change wants fd-level suppression (defense against a raw `write(1, ...)`, a C-level
warning, a segfault dump), a minimal repro that only redirects fds and prints "it works" is not
proof -- re-verify against a REAL container boot end-to-end before trusting it.
