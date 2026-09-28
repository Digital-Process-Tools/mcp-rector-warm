<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

final class RectorTool
{
    private RunnerInterface $runner;

    public function __construct(?RectorRunner $runner = null)
    {
        $this->runner = $runner ?? new RectorRunner();
    }

    /**
     * Test seam: build a tool around an arbitrary runner (e.g. a double that
     * simulates warm-state corruption). Kept separate from __construct so the MCP
     * SDK's constructor autowiring still sees a concrete RectorRunner type.
     *
     * @internal
     */
    public static function withRunner(RunnerInterface $runner): self
    {
        $tool = new self();
        $tool->runner = $runner;

        return $tool;
    }

    /**
     * Run Rector on a path. Config + working dir are pinned at server startup (--working-dir, --config flags).
     *
     * @param string $path Absolute path to file or directory under the server's working dir
     * @param bool $dryRun true = preview changes only (default), false = apply
     * @return array{exit_code: int, output: string, warm_boot: bool, error?: string, error_class?: string, trace?: string}|CallToolResult
     */
    #[McpTool(
        name: 'rector_process',
        description: 'Run Rector refactoring on a path, using the rector.php resolved at server start (reloaded automatically when that file changes).',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
            openWorldHint: false,
        ),
    )]
    public function process(string $path, bool $dryRun = true): array|CallToolResult
    {
        return $this->processWith($path, $dryRun, []);
    }

    /**
     * #106 (LSP unsaved buffers): a dry run on $tempPath, a temp copy of an
     * editor buffer, with Rector's skip rules applied as if it were
     * $originalPath (RectorRunner::applySkipsOfOriginalPath()). Not an MCP
     * tool: only process() carries the attribute.
     *
     * @return array{exit_code: int, output: string, warm_boot: bool}|CallToolResult
     */
    public function processBufferCopy(string $tempPath, string $originalPath): array|CallToolResult
    {
        return $this->processWith($tempPath, true, [RectorRunner::SKIP_AS_OPTION . '=' . $originalPath]);
    }

    /**
     * @param list<string> $extraOptions options inserted before `--`
     * @return array{exit_code: int, output: string, warm_boot: bool}|CallToolResult
     */
    private function processWith(string $path, bool $dryRun, array $extraOptions): array|CallToolResult
    {
        // Containment: rector reads (dry-run) or rewrites (non-dry) PHP files at
        // $path. Reject paths outside realpath(cwd) — set at boot via --working-dir.
        // Prevents a hostile MCP caller from triggering refactor writes or content
        // disclosure on arbitrary files (e.g. ~/projects/*.php, /etc/php/*.php).
        $cwd = realpath(getcwd() ?: '.');
        $real = realpath($path);
        if ($cwd === false || $real === false || !self::isWithinRoot($real, $cwd)) {
            return self::errorResult([
                'exit_code'   => -1,
                'output'      => '',
                'warm_boot'   => $this->runner->isWarm(),
                'error'       => 'rector_process: path is outside the configured working directory.',
                'error_class' => 'SecurityError',
                'trace'       => '',
            ]);
        }

        // #72 correction: every --call-timeout kill site (RectorRunner::
        // killAndReap(), the worker backstop, the no-pcntl proc_terminate()
        // path) used to send an unconditional SIGKILL/signal 9 with no grace
        // for an in-flight file write -- Rector writes each changed file by
        // truncating it and then writing the new content (vendor rector's
        // FileProcessor -> Nette\Utils\FileSystem::write() ->
        // file_put_contents()), so a kill landing mid-write left that file
        // truncated with no copy of its original content anywhere. An
        // earlier version of this fix refused every dryRun:false call
        // outright whenever a deadline was active -- a breaking change for
        // every caller running under the (now-default) 600s --call-timeout.
        // The correct fix instead: never kill a call that can write.
        // $dryRun is passed straight through to the runner below, which
        // gates the deadline on it at every one of the three kill sites --
        // a dryRun:false call simply is never bound by --call-timeout, and
        // can hang indefinitely if genuinely wedged (the pre-#58 status quo
        // for a write call), rather than being refused or risking data loss.
        // --debug disables parallel mode. We keep it for speed: parallel mode on 1
        // file is 14s overhead because rector still scans all configured paths at
        // boot. Single-thread bypasses the worker dance entirely.
        // Re-verified 2026-09-28 against rector/rector ^2.4 (#53 recon): --debug
        // does NOT suppress file_diffs[].applied_rectors/diff/changes in this
        // version -- a stale claim from an earlier rector release is corrected
        // here. Dpt\McpRectorWarm\Lsp\RectorDiagnosticsSource relies on file_diffs
        // being present through this same --debug call.
        $argv = ['rector', 'process', '--output-format=json', '--debug', '--no-progress-bar'];
        if ($dryRun) {
            $argv[] = '--dry-run';
        }
        // '--' marks the end of options so a path that happens to start with '-'
        // (e.g. a file named "-rf") is never parsed as a Rector CLI flag.
        array_push($argv, ...$extraOptions);
        $argv[] = '--';
        $argv[] = $path;

        try {
            return $this->runner->run($argv, $dryRun);
        } catch (\Throwable $e) {
            // A warm container can corrupt across edits: PHPStan's scope/reflection
            // caches are not ResettableInterface, so a class whose shape changed on
            // disk yields a null scope deep in PHPStanNodeScopeResolver
            // ("toMutatingScope() on null"). It is not a real finding — a cold run on
            // the same file passes. Reboot the container and retry once so the caller
            // gets the correct (cold-quality) result instead of a false rector.error.
            if ($this->runner->isWarm() && self::isRecoverableWarmCorruption($e)) {
                $this->runner->reboot();
                try {
                    return $this->runner->run($argv, $dryRun);
                } catch (\Throwable $retryError) {
                    $e = $retryError;
                }
            }

            return self::errorResult([
                'exit_code' => -1,
                'output' => '',
                'warm_boot' => $this->runner->isWarm(),
                'error' => $e->getMessage(),
                'error_class' => $e::class,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * A failed rector_process call must come back as an MCP tool error
     * (isError: true) so a host can see the failure and self-correct, per the
     * SDK's own CallToolResult contract, while keeping the structured details
     * (exit_code, error, error_class, trace) available via structuredContent.
     *
     * @param array{exit_code: int, output: string, warm_boot: bool, error?: string, error_class?: string, trace?: string} $details
     */
    private static function errorResult(array $details): CallToolResult
    {
        return new CallToolResult(
            content: [new TextContent($details['error'] ?? 'rector_process failed.')],
            isError: true,
            structuredContent: $details,
        );
    }

    /**
     * #99: on Windows the filesystem is case-insensitive, so realpath()
     * comparing a $cwd-derived drive letter against a $real-derived one can
     * legitimately differ only in case for the SAME file (verified: no
     * Windows here -- see the developer report's platform-band note; the
     * string comparison this drives is exercised directly by
     * RectorToolContainmentTest via reflection, independent of the host
     * OS). A bare str_starts_with() there would misjudge an in-root file as
     * out-of-root. $caseInsensitive defaults from the running OS's own
     * DIRECTORY_SEPARATOR so production behaviour needs no wiring; a test
     * can still force either branch to pin the string logic on any
     * platform.
     */
    private static function isWithinRoot(string $real, string $cwd, ?bool $caseInsensitive = null): bool
    {
        $caseInsensitive ??= DIRECTORY_SEPARATOR === '\\';
        // #99/#97: both branches hardcode their own separator rather than
        // deferring to the host's DIRECTORY_SEPARATOR, and for the same
        // reason on each side -- a test (or a merge-ref CI checkout) can
        // force either branch on the "wrong" host OS to pin the string
        // logic independent of platform. The case-insensitive branch is by
        // definition the Windows one, so it uses '\\' explicitly (#99);
        // symmetrically the case-sensitive branch is by definition the
        // POSIX one, so it uses '/' explicitly (#97's windows-latest CI leg
        // is what actually exposed this) -- otherwise a test forcing this
        // branch on a real windows-latest CI runner (where
        // DIRECTORY_SEPARATOR is '\\') would build a mismatched
        // "/proj\..." prefix against forward-slash test paths.
        $separator = $caseInsensitive ? '\\' : '/';
        $prefix = $cwd . $separator;

        if ($caseInsensitive) {
            return strcasecmp($real, $cwd) === 0 || stripos($real, $prefix) === 0;
        }

        return $real === $cwd || str_starts_with($real, $prefix);
    }

    /**
     * True for internal Rector/PHPStan errors that signal corrupted warm state
     * rather than a genuine problem with the analysed code — recoverable by
     * rebooting the container. Matched on message because the underlying type is a
     * plain \Error (null method call) or Rector "System error".
     */
    private static function isRecoverableWarmCorruption(\Throwable $error): bool
    {
        $message = $error->getMessage();
        foreach (['toMutatingScope', 'must be resolved', 'System error'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
