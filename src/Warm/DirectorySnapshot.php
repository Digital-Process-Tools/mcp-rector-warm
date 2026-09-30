<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Warm;

/**
 * #185: which PHP files and directories exist, directory by directory, so the
 * session child can tell that a file was created, deleted or renamed since it
 * started.
 *
 * Content changes are DependencyFileTracker's job. This one covers what a
 * content tracker cannot see: a class lookup that found NOTHING. PHPStan
 * memoises a failed lookup, and so do most autoloaders (Composer's
 * ClassLoader::$missingClasses, or a project's own map), so once a session has
 * asked for App\Base and not found it, creating src/Base.php later would never
 * reach it. No hook sees where a custom autoloader probed, so the snapshot
 * watches every directory under the project roots instead: a file appearing
 * anywhere under them changes that directory's listing.
 *
 * Only names count, not every byte: a subdirectory, or a file whose extension
 * is one PHP sources use. Under a plain root, hidden entries are skipped (an
 * editor's swap file, the LSP's own `.rector-warm-<pid>` temp directory);
 * under a "full" root -- a directory PHPStan itself scans for symbols, such as
 * withAutoloadPaths() -- they count, the way PHPStan's own walk counts them.
 *
 * A directory's mtime is read first and the listing is re-read only when it
 * moved -- or when the mtime is too close to the moment it was recorded to be
 * trusted: stat() reports whole seconds, so a file created in the same second
 * the listing was taken leaves the mtime unchanged ("racily clean", the same
 * rule git applies to its index, and DependencyFileTracker to file contents).
 *
 * POSIX paths only: the session exists only where pcntl does.
 */
final class DirectorySnapshot
{
    /** Hidden entries skipped; only PHP-source extensions count. */
    private const PLAIN = 0;

    /** Hidden entries count too (a directory PHPStan scans for symbols). */
    private const FULL = 1;

    /** Hidden entries and every file count (MCP_RECTOR_WARM_SESSION_WATCH). */
    private const WATCH = 2;

    /**
     * dir => [mtime, listing hash, subdirectories to walk, recorded-at, mode].
     *
     * @var array<string, array{0: int, 1: string, 2: list<string>, 3: int, 4: int}>
     */
    private array $dirs = [];

    /** @var list<string> */
    private array $watchRoots;

    /** @var list<string> */
    private array $roots;

    /** @var list<string> */
    private array $fullRoots;

    /** @var list<string> */
    private array $excluded;

    /** @var list<string> */
    private array $suffixes;

    /**
     * @param list<string> $roots walked with hidden entries skipped
     * @param list<string> $fullRoots walked with hidden entries included
     * @param list<string> $excluded never walked, never listed (vendor dirs, caches)
     * @param list<string> $extensions file extensions whose names count, without the dot
     * @param list<string> $watchRoots declared inputs of custom rules
     *   (MCP_RECTOR_WARM_SESSION_WATCH): every file name counts, whatever its extension
     */
    public function __construct(
        array $roots,
        array $fullRoots,
        array $excluded,
        array $extensions,
        private readonly int $maxDirectories = 100_000,
        array $watchRoots = [],
    ) {
        $this->watchRoots = self::normalise($watchRoots);
        $this->fullRoots = self::normalise($fullRoots);
        $this->roots = self::normalise($roots);
        $this->excluded = self::normalise($excluded);
        $this->suffixes = \array_values(\array_unique(\array_map(
            static fn (string $extension): string => '.' . \strtolower(\ltrim($extension, '.')),
            $extensions,
        )));
    }

    /**
     * (Re)build the snapshot from the tree as it is now. A directory whose mtime
     * has not moved since the last refresh (and is not racily clean) keeps its
     * previous listing, so refreshing an unchanged tree costs one stat() per
     * directory.
     *
     * @throws \RuntimeException when the roots hold more directories than the cap:
     *   watching that many would cost more per call than the session saves.
     */
    public function refresh(): void
    {
        $previous = $this->dirs;
        $this->dirs = [];
        \clearstatcache();
        foreach ([...$this->watchRoots, ...$this->fullRoots, ...$this->roots] as $root) {
            $this->walk($root, $previous);
        }
    }

    /**
     * The first watched directory whose listing no longer matches, or that no
     * longer exists; null when every one still matches.
     */
    public function firstChange(): ?string
    {
        \clearstatcache();
        foreach ($this->dirs as $dir => [$mtime, $hash, , $recordedAt, $mode]) {
            $stat = @\stat($dir);
            if ($stat === false) {
                return $dir;
            }
            if ($stat['mtime'] === $mtime && !self::racy($mtime, $recordedAt)) {
                continue;
            }
            $listing = $this->scan($dir, $mode);
            if ($listing === null || $listing[0] !== $hash) {
                return $dir;
            }
            // Same names, moved mtime (an atomic save): remember the new mtime
            // so the next check does not re-list this directory again.
            $this->dirs[$dir][0] = $stat['mtime'];
            $this->dirs[$dir][3] = \time();
        }

        return null;
    }

    public function count(): int
    {
        return \count($this->dirs);
    }

    /**
     * @param array<string, array{0: int, 1: string, 2: list<string>, 3: int, 4: int}> $previous
     */
    private function walk(string $dir, array $previous): void
    {
        if (isset($this->dirs[$dir]) || $this->isExcluded($dir)) {
            return;
        }
        $stat = @\stat($dir);
        if ($stat === false || !\is_dir($dir)) {
            return;
        }
        if (\count($this->dirs) >= $this->maxDirectories) {
            throw new \RuntimeException(\sprintf(
                'more than %d directories under the project roots; watching them all would cost more than the session saves',
                $this->maxDirectories,
            ));
        }
        $mode = $this->modeOf($dir);
        $old = $previous[$dir] ?? null;
        if ($old !== null && $old[0] === $stat['mtime'] && $old[4] === $mode && !self::racy($old[0], $old[3])) {
            $this->dirs[$dir] = $old;
        } else {
            $listing = $this->scan($dir, $mode);
            if ($listing === null) {
                return;
            }
            $this->dirs[$dir] = [$stat['mtime'], $listing[0], $listing[1], \time(), $mode];
        }
        foreach ($this->dirs[$dir][2] as $subdir) {
            $this->walk($subdir, $previous);
        }
    }

    /**
     * @return array{0: string, 1: list<string>}|null listing hash and subdirectories, or null when unreadable
     */
    private function scan(string $dir, int $mode): ?array
    {
        $hidden = $mode !== self::PLAIN;
        $entries = @\scandir($dir);
        if ($entries === false) {
            return null;
        }
        $names = [];
        $subdirs = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || (!$hidden && $entry[0] === '.')) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (\is_dir($path)) {
                if ($this->isExcluded($path)) {
                    continue;
                }
                $names[] = 'd:' . $entry;
                if (!\is_link($path)) {
                    $subdirs[] = $path;
                }
                continue;
            }
            if ($mode === self::WATCH) {
                $names[] = 'f:' . $entry;
                continue;
            }
            $lower = \strtolower($entry);
            foreach ($this->suffixes as $suffix) {
                if (\str_ends_with($lower, $suffix)) {
                    $names[] = 'f:' . $entry;
                    break;
                }
            }
        }
        \sort($names);

        return [\sha1(\implode("\0", $names)), $subdirs];
    }

    /**
     * mtime and the recording moment are both whole seconds: a directory
     * modified in the second before, or the same second as, its listing was
     * taken may have changed again without moving its mtime.
     */
    private static function racy(int $mtime, int $recordedAt): bool
    {
        return $mtime >= $recordedAt - 1;
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->excluded as $excluded) {
            if (Path::isUnder($path, $excluded)) {
                return true;
            }
        }

        return false;
    }

    private function modeOf(string $path): int
    {
        foreach ($this->watchRoots as $root) {
            if (Path::isUnder($path, $root)) {
                return self::WATCH;
            }
        }
        foreach ($this->fullRoots as $root) {
            if (Path::isUnder($path, $root)) {
                return self::FULL;
            }
        }

        return self::PLAIN;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private static function normalise(array $paths): array
    {
        $normalised = [];
        foreach ($paths as $path) {
            // Path::real() gives '/' separators everywhere, so the paths
            // walk() and scan() build from these with '/' compare equal.
            $real = Path::real($path);
            if ($real !== null) {
                $normalised[] = $real;
            }
        }

        return \array_values(\array_unique($normalised));
    }
}
