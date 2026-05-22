# Contributing

Thanks for the interest. This project is small and intentionally focused.

## Reporting issues

Open a GitHub issue with:

- Rector version (`composer show rector/rector`)
- PHP version (`php -v`)
- MCP client (Claude Desktop, Cline, ...)
- Repro: minimal `rector.php` + the failing command

## Pull requests

1. Fork, branch from `main`.
2. Add a test for the change (`tests/Unit` for logic, `tests/Integration` for end-to-end stdio behavior).
3. Run the suite:
   ```bash
   ./vendor/bin/phpunit --no-coverage
   ```
4. Open the PR with a one-paragraph summary of the change.

## What we'll merge

- Bug fixes with a regression test.
- Rector version compatibility shims.
- New MCP tools (e.g. `rector_list_rules`) that have a clear use case from an MCP client.
- Doc / README improvements.

## What we won't merge

- Features that re-enable parallel mode without a clean solution for the worker-spawn problem (it would break the warm guarantee).
- Wrappers that just shell out to `vendor/bin/rector` — defeats the whole purpose.

## Local development

```bash
git clone https://github.com/Digital-Process-Tools/mcp-rector-warm.git
cd mcp-rector-warm
composer install
./vendor/bin/phpunit --no-coverage
```

Smoke test the binary against the fixture project:

```bash
bin/mcp-rector-warm --working-dir=tests/Fixtures/project --config=tests/Fixtures/project/rector.php
# (then paste MCP JSON-RPC on stdin)
```
