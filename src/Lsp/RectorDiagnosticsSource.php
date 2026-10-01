<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp;

use Dpt\McpRectorWarm\RectorTool;
use Mcp\Schema\Result\CallToolResult;

/**
 * The real DiagnosticsSource: runs the same warm RectorTool::process() the
 * MCP tool uses (dry-run), on the single file the LSP asks about, and turns
 * its `file_diffs` entry into fixes via RectorDiffParser.
 */
final readonly class RectorDiagnosticsSource implements BufferDiagnosticsSource, WorkspaceDiagnosticsSource, EditDiagnosticsSource
{
    /**
     * @param \Closure(resource $handle): bool|null $lockAcquirer #188 test
     *   seam: when given, replaces the raw flock(LOCK_EX|LOCK_NB) call
     *   diagnoseBuffer() makes on its own temp directory's lock, so a test
     *   can force that one acquisition to fail without a second real
     *   process racing on the same file. null (every real caller) keeps
     *   the actual flock() call.
     */
    public function __construct(
        private RectorTool $tool,
        private ?\Closure $lockAcquirer = null,
    ) {
    }

    /** #106: the hidden per-server directory an unsaved buffer's temp copy lives in */
    public const TEMP_DIRECTORY_PREFIX = '.rector-warm-';

    public function diagnose(string $absolutePath): array
    {
        return $this->interpret($this->tool->process($absolutePath, true), $absolutePath);
    }

    /**
     * #216: same as diagnose(), with the session forced off -- for a call
     * whose result an LSP code action is about to turn into a WorkspaceEdit.
     * See EditDiagnosticsSource's own docblock for why.
     */
    public function diagnoseForEdit(string $absolutePath): array
    {
        return $this->interpret($this->tool->processForEdit($absolutePath), $absolutePath);
    }

    /**
     * #102: the same dry run as diagnose(), over $rootPath (the server's
     * working dir) instead of one file, with every `file_diffs` entry turned
     * into fixes rather than just the first. #216: `rector-warm.fixWorkspace`
     * turns this straight into a `workspace/applyEdit`, never merely
     * displays it as a diagnostic -- it always forces the session off, the
     * same as diagnoseForEdit(), never diagnose()'s plain process() call.
     *
     * @return array{files: array<string, list<array<string, mixed>>>, errors: list<array{message: string, line: int, file?: string}>}
     */
    public function diagnoseWorkspace(string $rootPath): array
    {
        return $this->interpretWorkspace($this->tool->processForEdit($rootPath), $rootPath);
    }

    /**
     * @param array<string, mixed>|CallToolResult $result
     * @return array{files: array<string, list<array<string, mixed>>>, errors: list<array{message: string, line: int, file?: string}>}
     */
    private function interpretWorkspace(array|CallToolResult $result, string $rootPath): array
    {
        if ($result instanceof CallToolResult) {
            $error = is_array($result->structuredContent) ? ($result->structuredContent['error'] ?? null) : null;
            $message = is_string($error) && $error !== '' ? $error : 'unknown error';
            fwrite(STDERR, sprintf(
                "rector-warm-lsp: workspace fix failed for %s: %s\n",
                $rootPath,
                $message,
            ));

            return ['files' => [], 'errors' => [['message' => $message, 'line' => 0]]];
        }

        $report = self::extractReport($result['output'] ?? '');
        if ($report === null) {
            return ['files' => [], 'errors' => [[
                'message' => 'rector_process succeeded but produced no parseable report.',
                'line' => 0,
            ]]];
        }

        $errors = self::buildErrors($report['errors'] ?? []);
        $fileDiffs = $report['file_diffs'] ?? [];

        $files = [];
        foreach ($fileDiffs as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $file = $entry['file'] ?? null;
            if (!is_string($file) || $file === '') {
                continue;
            }

            $fixes = RectorDiffParser::buildFixes(
                $entry['diff'] ?? '',
                $entry['applied_rectors'] ?? [],
                $entry['changes'] ?? [],
            );
            if ($fixes !== []) {
                $files[self::resolveAbsolutePath($file, $rootPath)] = $fixes;
            }
        }

        return ['files' => $files, 'errors' => $errors];
    }

    /**
     * Rector's `file_diffs[].file` is relative to the path it was asked to
     * process when that path is a directory (the workspace-fix case) --
     * joined against $rootPath here so WorkspaceDiagnosticsSource's own
     * contract (absolute `files` keys) holds regardless of what Rector's
     * own report shape happens to be for a given path/version.
     */
    private static function resolveAbsolutePath(string $file, string $rootPath): string
    {
        if (self::isAbsolutePath($file)) {
            return $file;
        }

        return rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * CI fix (PR #137, windows-latest legs): the character class here was
     * `[\\/]` at runtime -- a single escaped forward slash, since PHP's
     * single-quote parsing collapses `\\\\` to one backslash and THAT
     * backslash then escapes the `/` that follows it inside the class,
     * leaving no backslash actually IN the class. It never matched a real
     * Windows absolute path (`C:\...`), only the `C:/...` form -- reasoned
     * from `php -r` on this (macOS) machine confirming the exact string
     * PHP produces for each byte count, not observed on Windows itself.
     * Needs FOUR backslashes on disk so the class contains an escaped
     * backslash (matches `\`) alongside the forward slash (matches `/`).
     */
    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * @param array<string, mixed>|CallToolResult $result
     * @return array{fixes: list<array<string, mixed>>, errors: list<array{message: string, line: int}>}
     */
    private function interpret(array|CallToolResult $result, string $absolutePath): array
    {
        if ($result instanceof CallToolResult) {
            $error = is_array($result->structuredContent) ? ($result->structuredContent['error'] ?? null) : null;
            $message = is_string($error) && $error !== '' ? $error : 'unknown error';
            fwrite(STDERR, sprintf(
                "rector-warm-lsp: diagnostics failed for %s: %s\n",
                $absolutePath,
                $message,
            ));

            // #90: a refused call (out-of-root path, SecurityError) used to
            // come back looking identical to "nothing to report" -- the
            // stderr line above was the only trace. Surface it as an error
            // diagnostic too, since stderr is not where an editor looks.
            return ['fixes' => [], 'errors' => [['message' => $message, 'line' => 0]]];
        }

        $report = self::extractReport($result['output'] ?? '');
        if ($report === null) {
            // Self-review finding on #90 (independent auditor pass): a
            // successful call (no CallToolResult refusal) whose output never
            // contains a parseable `{"totals":...}` report -- truncated
            // output, a decode failure, a lost boot-handshake byte (see #73,
            // #74) -- used to null-coalesce straight through to `[]` for
            // BOTH `errors` and `file_diffs`, the exact same "looks clean"
            // shape #90 exists to close, just for a third channel.
            return ['fixes' => [], 'errors' => [[
                'message' => 'rector_process succeeded but produced no parseable report.',
                'line' => 0,
            ]]];
        }

        $errors = self::buildErrors($report['errors'] ?? []);
        $fileDiffs = $report['file_diffs'] ?? [];
        if ($fileDiffs === []) {
            return ['fixes' => [], 'errors' => $errors];
        }

        $entry = $fileDiffs[0];

        return [
            'fixes' => RectorDiffParser::buildFixes(
                $entry['diff'] ?? '',
                $entry['applied_rectors'] ?? [],
                $entry['changes'] ?? [],
            ),
            'errors' => $errors,
        ];
    }

    /**
     * #106: Rector only reads files, so an unsaved buffer is written to a
     * temp copy and Rector runs on that. Where the copy lives is what keeps
     * the result equal to a cold run on the original:
     *
     * - inside the project (a hidden `.rector-warm-<pid>` directory in the
     *   original's own directory), so autoload, rector.php discovery and
     *   RectorTool's path containment all apply exactly as for the original;
     * - under the original's own basename, so globs on the file name keep
     *   matching it;
     * - with the ORIGINAL path passed alongside (RectorTool::
     *   processBufferCopy()), so the worker applies every `withSkip()` entry
     *   that matches the original -- exact path, relative path, a glob on
     *   its parent directories, a rule skipped for it -- to the copy too
     *   (RectorRunner::applySkipsOfOriginalPath()).
     *
     * The copy (and its directory) is removed in a `finally`, so a Rector
     * error, a refused call or a throwing runner leaves nothing behind. A
     * server killed outright (kill -9) cannot run that `finally`; its
     * leftover is removed by TempCopySweeper, next to the file being
     * diagnosed, before any run in the same directory -- there is no
     * startup sweep (#179 drops it).
     */
    public function diagnoseBuffer(string $absolutePath, string $content): array
    {
        return $this->diagnoseBufferImpl($absolutePath, $content, false);
    }

    /**
     * #216: same as diagnoseBuffer(), with the session forced off -- for a
     * call whose result an LSP code action is about to turn into a
     * WorkspaceEdit over an unsaved buffer. See EditDiagnosticsSource's own
     * docblock for why.
     */
    public function diagnoseBufferForEdit(string $absolutePath, string $content): array
    {
        return $this->diagnoseBufferImpl($absolutePath, $content, true);
    }

    private function diagnoseBufferImpl(string $absolutePath, string $content, bool $noSession): array
    {
        $directory = dirname($absolutePath);
        $realDirectory = realpath($directory);
        $cwd = realpath(getcwd() ?: '.');

        // Checked BEFORE anything is written: RectorTool refuses an
        // out-of-root path too, but only after the temp copy would already
        // exist outside the project.
        if ($realDirectory === false || $cwd === false || !self::isWithinRoot($realDirectory, $cwd)) {
            return self::failure('rector_process: path is outside the configured working directory.');
        }

        $tempDirectory = $directory . DIRECTORY_SEPARATOR . self::tempDirectoryName();
        $tempPath = $tempDirectory . DIRECTORY_SEPARATOR . basename($absolutePath);

        // #179 self-review: the lock file this method now creates inside
        // $tempDirectory lives at the same level as the buffer copy, named
        // TempCopySweeper::LOCK_FILE_NAME ('.lock'). A buffer whose own
        // basename collides with that name (a project genuinely editing a
        // file called `.lock`) would otherwise have its own fopen(...,
        // 'x') permanently fail with "already exists", since the lock
        // file is created first -- refused explicitly, with its own
        // message, rather than that generic failure. strcasecmp() rather
        // than a plain === so the same refusal fires on a case-
        // insensitive filesystem (Windows, and macOS's default APFS) for
        // any case variant, not only an exact match.
        if (strcasecmp(basename($tempPath), TempCopySweeper::LOCK_FILE_NAME) === 0) {
            return self::failure(sprintf("rector-warm-lsp: refusing to diagnose a buffer named like the sweeper's own lock file (%s) in %s", TempCopySweeper::LOCK_FILE_NAME, $directory));
        }

        // A server killed mid-run (kill -9) never reached its `finally`; its
        // leftover next to this file goes now -- the per-directory sweep
        // below runs before every buffer write; there is no startup walk.
        TempCopySweeper::sweepDirectory($directory);

        try {
            // #142/#144: a symlink or NTFS junction planted at the
            // deterministic `.rector-warm-<pid>` name would have is_dir()
            // follow it -- the write below then lands through it. Refused
            // before that happens. `$tempDirectory` is checked again below,
            // right before the `finally` block's own unlink/rmdir:
            // isLinkOrJunction($tempDirectory) here only prevents this
            // branch from being entered, and does nothing to a `finally`
            // that runs unconditionally on every exit from `try` -- a
            // re-check is the only way to keep it out of that block too.
            // isLinkOrJunction() widens is_link() to also catch a junction,
            // which is_link() does not reliably detect (#144).
            if (TempCopySweeper::isLinkOrJunction($tempDirectory)) {
                return self::failure(sprintf('rector-warm-lsp: refusing a symlinked or junctioned temp directory in %s', $directory));
            }

            // #165: #161's fix reapplied chmod(0700) to $tempDirectory on
            // every call, including when it already existed -- but chmod()
            // only succeeds when the calling process owns the target, so
            // for a directory an attacker planted ahead of time under this
            // guessable name (matching this server's own live pid, so
            // TempCopySweeper's own per-directory sweep above does not
            // treat it as stale -- a fresh, still-empty directory is kept
            // within its grace period, and a live lock is always kept),
            // the reapply silently failed (its return value was discarded)
            // and the guard was a no-op for exactly the case its own
            // comment named.
            // mkdir() is already atomic and exclusive -- refusing whenever
            // it does not succeed, rather than tolerating is_dir() ===
            // true and trying to repair the mode afterwards, closes that
            // gap without needing to know who owns what is already there.
            // The legitimate repeat-call case (same buffer, same live pid,
            // calls handled serially by the LSP) is unaffected: the
            // `finally` block below always removes $tempDirectory before
            // this method returns, so a genuine reuse never finds it
            // present here (see #149's own PR #152 for why that block runs
            // on every exit from `try`, not only the happy path).
            // Split into two messages rather than one shared "refusing"
            // sentence (self-review finding): !@mkdir() below can fail for
            // reasons that have nothing to do with a pre-existing directory
            // -- a read-only parent, a full disk, a path too long on
            // Windows -- and folding those into "refusing a pre-existing
            // temp directory" would tell an operator debugging a genuine
            // mkdir() failure that an attacker planted something, when
            // nothing did.
            // #188: a `.rector-warm-<pid>` directory reaching here already
            // matches THIS process's own deterministic name -- nothing else
            // was ever going to create one under this pid. If all it holds
            // is an empty `.lock` nobody else can lock (the shape a
            // lock-acquisition race, or any other imperfect cleanup, can
            // leave behind), it is reclaimed rather than left to refuse
            // every buffer in this directory for the rest of this
            // process's life: TempCopySweeper::reclaimStaleOwnLock()
            // removes it and this call proceeds as if it had never
            // existed. Anything else present -- a live lock, or content
            // beyond a bare empty `.lock` -- is refused exactly as before.
            if (is_dir($tempDirectory) && !TempCopySweeper::reclaimStaleOwnLock($tempDirectory)) {
                return self::failure(sprintf('rector-warm-lsp: refusing a pre-existing temp directory in %s', $directory));
            }

            if (!@mkdir($tempDirectory, 0o700)) {
                return self::failure(sprintf('rector-warm-lsp: could not create a temp directory in %s', $directory));
            }

            // #179: ownership of $tempDirectory for TempCopySweeper's
            // purposes is this lock, held for the rest of this method's
            // run. A server killed outright (kill -9) never releases it
            // explicitly, but the OS releases every lock a dying process
            // holds -- including on Windows, where flock() maps to
            // LockFileEx -- so the next sweep's non-blocking LOCK_EX
            // acquire attempt succeeds and reads the directory as stale.
            $lockPath = $tempDirectory . DIRECTORY_SEPARATOR . TempCopySweeper::LOCK_FILE_NAME;
            $lockHandle = @fopen($lockPath, 'c');
            if ($lockHandle === false || !$this->tryLockExclusive($lockHandle)) {
                if (is_resource($lockHandle)) {
                    fclose($lockHandle);
                }
                // #188: the success path a few lines below unlinks `.lock`
                // before it rmdir()s $tempDirectory (see the `finally`
                // block) -- this failure path must do the same, or `.lock`
                // is still there when rmdir() runs, rmdir() silently fails
                // on the now-non-empty directory, and this process refuses
                // its own directory for the rest of its life at the
                // is_dir() guard above. isLinkOrJunction() re-checked here
                // for the same reason the `finally` block re-checks it: a
                // symlink swapped in mid-race must never have its target's
                // same-named `.lock` deleted through this path.
                if (!TempCopySweeper::isLinkOrJunction($tempDirectory)) {
                    @unlink($lockPath);
                }
                @rmdir($tempDirectory);

                return self::failure(sprintf('rector-warm-lsp: could not lock a temp directory in %s', $directory));
            }

            // Release-audit finding: the sweeper's own removeKnownContents()
            // now refuses to delete anything unless the directory's full
            // listing is exactly `.lock` plus one other recorded name --
            // recorded HERE, in `.lock` itself, rather than assumed from
            // "whatever single file is inside", so a directory this process
            // did not create (or one holding anything unexpected) is never
            // guessed to be safe to empty.
            @ftruncate($lockHandle, 0);
            @fwrite($lockHandle, basename($tempPath));
            @fflush($lockHandle);

            // #149: the directory-level guard above stops a symlinked or
            // junctioned `.rector-warm-<pid>` NAME from being entered, but a
            // genuinely real directory (e.g. one an attacker plants ahead of
            // time, matching this server's own live pid so TempCopySweeper's
            // own per-directory sweep does not treat it as stale) passes
            // that guard -- and can then
            // contain a symlink at the LEAF path, named after this buffer's
            // own basename. Without this check, the write below would
            // follow it and overwrite whatever it points to, anywhere this
            // process can write, before RectorTool's own realpath
            // containment check ever runs (that check only gates whether
            // Rector is invoked afterwards -- too late to stop the write).
            if (TempCopySweeper::isLinkOrJunction($tempPath)) {
                return self::failure(sprintf('rector-warm-lsp: refusing a symlinked or junctioned temp file in %s', $directory));
            }

            // #161: isLinkOrJunction() above only widens is_link() with a
            // Windows junction check -- neither detects a HARD LINK, a
            // second directory entry pointing at the same inode as a file
            // elsewhere. is_link() is correctly false for a hard link (it
            // genuinely is not a symlink), so the guard above lets one
            // straight through, and a plain write would truncate-and-
            // overwrite whatever it points at. Rather than naming a third
            // link type to check for, fopen(..., 'x') (O_CREAT|O_EXCL)
            // refuses unconditionally if anything -- file, symlink, or hard
            // link -- already exists at $tempPath: it does not need to know
            // WHAT is there, only that something is, which also closes the
            // check-then-write TOCTOU gap between the guard above and the
            // write itself (same family as #145). Never in the way for the
            // legitimate repeat-call pattern (two diagnoseBuffer() calls for
            // the same buffer in a row): the `finally` block below removes
            // $tempPath on every exit from this method, so by the time a
            // second call for the same buffer reaches here, nothing is left
            // at that path to collide with.
            // #165: fopen(..., 'x') below creates the file at the current
            // umask's default mode (typically 0644 for 0666 & ~0022), and
            // the chmod() after it only narrows that to 0600 once fopen()
            // has already returned -- a brief window, even in the
            // legitimate same-owner case, during which the buffer's
            // content is group/world-readable before the narrower mode is
            // applied. Narrowing the umask around the call itself means
            // the file is created at 0600 (0666 & ~0077) from the instant
            // it exists, closing that window instead of repairing it
            // afterwards; restored immediately so it never affects any
            // other code in this process. chmod() is kept as a second,
            // redundant layer in case a filesystem or platform does not
            // honour umask() for some reason this process cannot detect.
            $previousUmask = umask(0o077);
            $handle = @fopen($tempPath, 'x');
            umask($previousUmask);
            if ($handle === false) {
                return self::failure(sprintf('rector-warm-lsp: refusing to write a temp file that already exists in %s', $directory));
            }

            @chmod($tempPath, 0o600);

            $length = strlen($content);
            $written = 0;
            while ($written < $length) {
                $chunk = @fwrite($handle, substr($content, $written));
                if ($chunk === false || $chunk <= 0) {
                    break;
                }
                $written += $chunk;
            }
            fclose($handle);

            if ($written !== $length) {
                @unlink($tempPath);

                return self::failure(sprintf('rector-warm-lsp: could not write the unsaved buffer to a temp file in %s', $directory));
            }

            // #106: Rector matches `withSkip()` against the path it
            // processes; processBufferCopy() has the worker apply the
            // ORIGINAL path's skips to the copy (RectorRunner::
            // applySkipsOfOriginalPath()), so exact-path, relative, glob and
            // rule-scoped skips behave as on the saved file.
            $result = $this->interpret($this->tool->processBufferCopy($tempPath, $absolutePath, $noSession), $tempPath);
        } catch (\Throwable $e) {
            $result = self::failure($e->getMessage());
        } finally {
            // #142/#144: `$tempDirectory` is an intermediate path component
            // of `$tempPath`, not its final one, so unlink() follows a
            // symlink or junction there regardless of the early return
            // above -- that return only skips the write; it does not skip
            // this block, which runs on every exit from `try`, including
            // that one. Re-checked here so a symlinked or junctioned
            // `$tempDirectory` never reaches unlink/rmdir either, whether or
            // not the write above ever ran.
            // #149: re-checked here for the same reason $tempDirectory is
            // re-checked just above -- this `finally` runs on every exit
            // from `try`, including the early return the guard above takes,
            // so unlink() must not be the one place a symlinked $tempPath
            // still gets followed.
            // #179: the lock is released and its file removed before
            // rmdir() -- rmdir() refuses a non-empty directory. $lockHandle
            // may be unset if mkdir() or the lock guard above already
            // returned before it was opened.
            // #179 self-review: every other filesystem call on
            // $tempDirectory/$tempPath in this block is preceded by an
            // isLinkOrJunction($tempDirectory) re-check, because this
            // `finally` runs on every exit from `try` and a symlink swap
            // can happen mid-run, while Rector is still executing (the
            // same race #142/#144/#149 already assume is live and guard
            // every other operation here against). The unlink() below is
            // no exception: skipped whenever $tempDirectory is no longer
            // the real directory this process created, so a same-named
            // `.lock` at whatever it now points to is never deleted
            // through it.
            if (isset($lockHandle) && is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                if (!TempCopySweeper::isLinkOrJunction($tempDirectory)) {
                    @unlink($tempDirectory . DIRECTORY_SEPARATOR . TempCopySweeper::LOCK_FILE_NAME);
                }
            }

            if (!TempCopySweeper::isLinkOrJunction($tempDirectory) && !TempCopySweeper::isLinkOrJunction($tempPath)) {
                @unlink($tempPath);
                @rmdir($tempDirectory);
            }
        }

        // Both of $result's producers (self::failure(), $this->interpret())
        // declare an 'errors' key in their own return type, so it is always
        // present here -- no ?? fallback needed.
        foreach ($result['errors'] as $i => $error) {
            $result['errors'][$i]['message'] = self::withoutTempDirectory($error['message']);
        }

        return $result;
    }

    private static function tempDirectoryName(): string
    {
        return self::TEMP_DIRECTORY_PREFIX . getmypid();
    }

    /**
     * #188 test seam: real callers get the actual flock(LOCK_EX|LOCK_NB)
     * attempt; a test may inject $lockAcquirer to force this one
     * acquisition to fail, simulating the race a second server winds up
     * winning between this process's own fopen() and flock().
     *
     * @param resource $handle
     */
    private function tryLockExclusive($handle): bool
    {
        return $this->lockAcquirer !== null
            ? ($this->lockAcquirer)($handle)
            : flock($handle, LOCK_EX | LOCK_NB);
    }

    /**
     * Rector's messages name the file it processed -- the temp copy, in
     * absolute or project-relative form. Dropping the temp directory segment
     * turns either form back into the original's path.
     */
    private static function withoutTempDirectory(string $message): string
    {
        $segment = self::tempDirectoryName();

        return str_replace(['/' . $segment . '/', '\\' . $segment . '\\'], ['/', '\\'], $message);
    }

    /**
     * @return array{fixes: list<never>, errors: list<array{message: string, line: int}>}
     */
    private static function failure(string $message): array
    {
        return ['fixes' => [], 'errors' => [['message' => $message, 'line' => 0]]];
    }

    /**
     * Same rule as RectorTool's own containment check (#99: case-insensitive
     * on Windows, where realpath() does not normalise case).
     */
    private static function isWithinRoot(string $real, string $root): bool
    {
        $caseInsensitive = PHP_OS_FAMILY === 'Windows';
        $root = rtrim($root, '/\\');

        if ($caseInsensitive ? strcasecmp($real, $root) === 0 : $real === $root) {
            return true;
        }

        $prefix = $root . DIRECTORY_SEPARATOR;

        return $caseInsensitive
            ? strncasecmp($real, $prefix, strlen($prefix)) === 0
            : str_starts_with($real, $prefix);
    }

    /**
     * #90: `report['errors']` (a syntax error, or any other per-file Rector
     * failure) used to be read nowhere at all -- a file that goes from
     * "1 fixable diagnostic" to "does not even parse" dropped to zero
     * diagnostics, the same silent-clean shape as the CallToolResult branch
     * above. Tolerant of both the documented shape (`{"message":...,
     * "line":...}`) and a bare string entry, since nothing upstream pins
     * Rector's own error-entry shape across versions.
     *
     * #141: `file` is present only when the raw entry named one (Rector's
     * own multi-file/workspace report does; a single-file report does not
     * need to and may not).
     *
     * @param mixed $rawErrors
     * @return list<array{message: string, line: int, file?: string}>
     */
    private static function buildErrors(mixed $rawErrors): array
    {
        if (!is_array($rawErrors)) {
            return [];
        }

        $errors = [];
        foreach ($rawErrors as $raw) {
            if (is_array($raw)) {
                $message = is_string($raw['message'] ?? null) ? $raw['message'] : 'Rector reported an error.';
                $line = is_int($raw['line'] ?? null) ? $raw['line'] : 0;
                $file = is_string($raw['file'] ?? null) && $raw['file'] !== '' ? $raw['file'] : null;
            } elseif (is_string($raw)) {
                $message = $raw;
                $line = 0;
                $file = null;
            } else {
                continue;
            }

            // #141: preserved when Rector's own report names the file an
            // error belongs to -- needed for `diagnoseWorkspace()`'s
            // multi-file case, where a bare message alone cannot say WHICH
            // file among several failed. Absent for the single-file
            // diagnose() path's own use of this same helper; harmless
            // there, since that caller already knows the file from its own
            // $absolutePath argument and never reads this key.
            $errors[] = $file !== null
                ? ['message' => $message, 'line' => $line, 'file' => $file]
                : ['message' => $message, 'line' => $line];
        }

        return $errors;
    }

    /**
     * Rector's own JSON report out of raw process output, skipping any text
     * BEFORE it (e.g. a no-config warning) and tolerating any text AFTER it
     * too (a self-review finding on #53: decoding `substr($output, $pos)`
     * whole -- to the end of the string -- fails on ANY trailing byte after
     * the closing `}`, e.g. a PHP deprecation notice a future Rector/PHP
     * version prints after its JSON, and fails SILENTLY here, since a null
     * report reads identically to "0 changes"). This scans for the matching
     * closing brace instead, so only the JSON object itself is decoded.
     *
     * @return array<string, mixed>|null
     */
    private static function extractReport(string $output): ?array
    {
        for ($pos = strpos($output, '{'); $pos !== false; $pos = strpos($output, '{', $pos + 1)) {
            $end = self::matchingBraceEnd($output, $pos);
            if ($end === null) {
                continue;
            }

            $decoded = json_decode(substr($output, $pos, $end - $pos + 1), true);
            if (is_array($decoded) && array_key_exists('totals', $decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * The index of the `}` that closes the `{` at $start, respecting JSON
     * string literals (so a brace inside a quoted string, e.g. a rule's diff
     * text, is never mistaken for structure) -- null if $output ends before
     * the brace at $start closes.
     */
    private static function matchingBraceEnd(string $output, int $start): ?int
    {
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start, $len = strlen($output); $i < $len; $i++) {
            $char = $output[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
