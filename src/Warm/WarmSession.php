<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Warm;

/**
 * #185: the bookkeeping of one session child -- a process forked once from the
 * pristine warm worker that then analyses call after call in-process, keeping
 * PHPStan's reflection, parser and rule caches warm between them.
 *
 * That reuse is only safe while two things hold, and this class checks both:
 *
 * 1. Nothing the session has read has changed (staleReason()). Its inputs are
 *    every file PHPStan parsed (reported through TrackingParser -> recordRead()),
 *    every file PHP included during a call, every file a call analysed, and the
 *    listing of every project directory (DirectorySnapshot: a file created where
 *    a failed lookup looked). Any difference -- or a record() that raced an edit
 *    -- and the caller discards the session and forks a fresh one from the
 *    pristine worker.
 *
 * 2. What the analysed file itself contributes to the session is what a cold
 *    run on ANY other file would also see (route()). A cold run finds a class
 *    through PHPStan's standard locator chain first, then through the analysed
 *    file, then through withAutoloadPaths(). So a file whose symbols the
 *    standard chain already finds adds nothing file-specific; a file under an
 *    autoload path whose symbols that path resolves to the file itself is what
 *    every other cold run sees too. Anything else -- a class only this file
 *    declares and nothing else would find, or a duplicate declaration -- would
 *    stay visible to later calls inside the session while a cold run on those
 *    files cannot see it, so the call is DECLINED and runs forked from the
 *    pristine worker as before.
 *
 *    An editor buffer's temp copy (the LSP, #106) declares the same class as
 *    the original at another path. It never runs in the session itself: when
 *    the standard chain finds every symbol it declares (so a cold run takes
 *    them from the original on disk, exactly as the session did) it runs in a
 *    child FORKED from the session, which inherits the warm caches and dies
 *    with the buffer's; otherwise it is declined.
 *
 * What this cannot see: inputs that are not PHP sources read through PHPStan's
 * parser or PHP's include -- a template, XML or JSON file a custom rule reads at
 * Rector time, an environment variable, the clock. The pristine worker has the
 * same blind spot for anything it read at boot.
 */
final class WarmSession
{
    public const SERVE = 'serve';
    public const FORK = 'fork';
    public const DECLINE = 'decline';

    /** @var array<string, true> */
    private array $includedBaseline;

    /** @var list<string> */
    private array $autoloadDirectories = [];

    /** @var list<string> */
    private array $cacheDirectories = [];

    private bool $recording = true;

    private int $calls = 0;

    /**
     * @param list<string> $includedBaseline get_included_files() as the session starts
     * @param list<string> $autoloadDirectories Rector's withAutoloadPaths() directories
     * @param list<string> $cacheDirectories PHPStan's and Rector's own caches: a PHP
     *   file PHPStan writes there and includes is keyed by inputs already tracked,
     *   and is rewritten as a matter of course -- tracking it only forces needless
     *   fresh sessions
     */
    public function __construct(
        private readonly SymbolResolver $symbols,
        private readonly DependencyFileTracker $files,
        private readonly ?DirectorySnapshot $directories,
        array $includedBaseline,
        array $autoloadDirectories,
        array $cacheDirectories = [],
    ) {
        $this->includedBaseline = \array_fill_keys(\array_map(Path::normalise(...), $includedBaseline), true);
        foreach ($cacheDirectories as $directory) {
            $real = Path::real($directory);
            if ($real !== null) {
                $this->cacheDirectories[] = $real;
            }
        }
        foreach ($autoloadDirectories as $directory) {
            $real = Path::real($directory);
            if ($real !== null && \is_dir($real)) {
                $this->autoloadDirectories[] = $real;
            }
        }
    }

    /**
     * A file PHPStan is about to read (TrackingParser), or any other input the
     * session's state now depends on. Ignored while route() only inspects the
     * analysed file's own declarations.
     */
    public function recordRead(string $path): void
    {
        if ($this->recording) {
            $this->files->record($path, null);
        }
    }

    /**
     * Why this session can no longer be trusted, or null when it can.
     */
    public function staleReason(): ?string
    {
        $file = $this->files->checkStale();
        if ($file !== null) {
            return "changed: {$file}";
        }
        $directory = $this->directories?->firstChange();
        if ($directory !== null) {
            return "directory listing changed: {$directory}";
        }

        return null;
    }

    /**
     * How a call on $path may be served -- see the class docblock.
     *
     * @return array{0: string, 1: string} one of SERVE / FORK / DECLINE, and why
     */
    public function route(string $path, bool $bufferCopy): array
    {
        $this->recording = false;
        try {
            $declared = $this->symbols->declaredSymbols($path);
        } finally {
            $this->recording = true;
        }

        $real = Path::real($path);
        $underAutoloadPath = !$bufferCopy && $real !== null && $this->isUnderAutoloadDirectory($real);
        foreach ($declared as [$kind, $name]) {
            if ($this->symbols->resolveStandard($kind, $name) !== null) {
                continue;
            }
            if ($underAutoloadPath && self::samePath($this->symbols->resolveAutoloadPaths($kind, $name), $real)) {
                continue;
            }

            return [self::DECLINE, "{$kind} {$name} is found only through this file"];
        }

        return $bufferCopy ? [self::FORK, 'editor buffer copy'] : [self::SERVE, ''];
    }

    /**
     * After a call the session served itself: the analysed file, and every PHP
     * file included during the call, are now inputs of the session's state.
     */
    public function afterInSessionCall(string $path): void
    {
        ++$this->calls;
        $this->recordRead(Path::real($path) ?? Path::normalise($path));
        foreach (\get_included_files() as $included) {
            $included = Path::normalise($included);
            if (!isset($this->includedBaseline[$included]) && !$this->isUnder($included, $this->cacheDirectories)) {
                $this->recordRead($included);
            }
        }
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function trackedFiles(): int
    {
        return $this->files->count();
    }

    private function isUnderAutoloadDirectory(string $path): bool
    {
        return $this->isUnder($path, $this->autoloadDirectories);
    }

    /**
     * @param list<string> $directories real paths
     */
    private function isUnder(string $path, array $directories): bool
    {
        if ($directories === []) {
            return false;
        }
        $real = Path::real($path) ?? Path::normalise($path);
        foreach ($directories as $directory) {
            if ($real !== $directory && Path::isUnder($real, $directory)) {
                return true;
            }
        }

        return false;
    }

    private static function samePath(?string $a, string $b): bool
    {
        if ($a === null || $a === '') {
            return false;
        }

        return (Path::real($a) ?? Path::normalise($a)) === Path::normalise($b);
    }
}
