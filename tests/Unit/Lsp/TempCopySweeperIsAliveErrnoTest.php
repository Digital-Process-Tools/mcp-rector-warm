<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Lsp {
    /**
     * #168 regression test support: an errno other than ESRCH/EPERM has no
     * portable, deterministic trigger for posix_kill() from PHPUnit, so
     * this overrides posix_kill()/posix_get_last_error() for calls made
     * from INSIDE TempCopySweeper's own namespace only -- PHP resolves an
     * unqualified function call against the current namespace first,
     * falling back to the global one only when no same-namespace function
     * exists, so this intercepts isAlive()'s own calls without touching
     * \posix_kill() itself, or anything called from a different namespace.
     */
    final class TempCopySweeperPosixErrnoStub
    {
        public static bool $active = false;
        public static ?int $forcedErrno = null;

        public static function reset(): void
        {
            self::$active = false;
            self::$forcedErrno = null;
        }
    }

    function posix_kill(int $pid, int $signal): bool
    {
        if (TempCopySweeperPosixErrnoStub::$active) {
            return false;
        }

        return \posix_kill($pid, $signal);
    }

    function posix_get_last_error(): int
    {
        if (TempCopySweeperPosixErrnoStub::$active && TempCopySweeperPosixErrnoStub::$forcedErrno !== null) {
            return TempCopySweeperPosixErrnoStub::$forcedErrno;
        }

        return \posix_get_last_error();
    }
}

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp {

    use Dpt\McpRectorWarm\Lsp\TempCopySweeper;
    use Dpt\McpRectorWarm\Lsp\TempCopySweeperPosixErrnoStub;
    use PHPUnit\Framework\TestCase;

    /**
     * #168: isAlive()'s POSIX branch only distinguished EPERM (alive) from
     * everything else, collapsing ESRCH (genuinely dead), a transient
     * failure, and posix_get_last_error() itself being unavailable all
     * into "known dead" -- contradicting the method's own three-way
     * contract (true = alive, false = known dead, null = cannot tell).
     * These force an errno posix_kill() itself has no portable trigger
     * for, via the same-namespace function overrides declared in the
     * Lsp-namespace block above (PHP has no way to mock a real extension
     * function like posix_kill() directly).
     */
    final class TempCopySweeperIsAliveErrnoTest extends TestCase
    {
        private string $root;

        protected function setUp(): void
        {
            if (!\function_exists('posix_kill') || !\function_exists('posix_get_last_error')) {
                self::markTestSkipped('posix_kill/posix_get_last_error unavailable in this environment');
            }

            $this->root = \sys_get_temp_dir() . '/mcp-rector-sweep-errno-' . \bin2hex(\random_bytes(4));
            \mkdir($this->root, 0o700, true);
        }

        protected function tearDown(): void
        {
            TempCopySweeperPosixErrnoStub::reset();
            self::removeTree($this->root);
        }

        private static function removeTree(string $dir): void
        {
            foreach (\scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (\is_dir($path)) {
                    self::removeTree($path);
                } else {
                    \unlink($path);
                }
            }
            \rmdir($dir);
        }

        private function plant(int $pid): string
        {
            $dir = $this->root . '/.rector-warm-' . $pid;
            \mkdir($dir, 0o700, true);
            \file_put_contents($dir . '/Clean.php', "<?php\n");

            return $dir;
        }

        /** A pid that belonged to a process which has already exited. */
        private static function deadPid(): int
        {
            $process = \proc_open([PHP_BINARY, '-r', ''], [], $pipes);
            self::assertIsResource($process);
            $pid = \proc_get_status($process)['pid'];
            \proc_close($process);

            return $pid;
        }

        public function testAnUnrecognisedErrnoIsKeptNotRemoved(): void
        {
            $dir = $this->plant(self::deadPid());

            TempCopySweeperPosixErrnoStub::$active = true;
            TempCopySweeperPosixErrnoStub::$forcedErrno = 4; // EINTR: neither ESRCH (3) nor EPERM (1).

            TempCopySweeper::sweepDirectory($this->root);

            self::assertDirectoryExists(
                $dir,
                'an unrecognised posix_kill() errno must read as "cannot tell", never as "known dead"',
            );
        }

        /**
         * Positive control for the test above, same stub wiring: an errno
         * of ESRCH (3) -- genuinely dead -- still gets the directory
         * removed. Without this, the test above could pass because the
         * stub silently broke sweepDirectory() entirely rather than
         * because isAlive() correctly distinguished the two cases.
         */
        public function testAnEsrchErrnoIsStillRemoved(): void
        {
            $dir = $this->plant(self::deadPid());

            TempCopySweeperPosixErrnoStub::$active = true;
            TempCopySweeperPosixErrnoStub::$forcedErrno = 3; // ESRCH: genuinely dead.

            TempCopySweeper::sweepDirectory($this->root);

            self::assertDirectoryDoesNotExist($dir, 'an ESRCH errno must still be treated as known dead');
        }

        /**
         * Second positive control, same stub wiring: an errno of EPERM (1)
         * -- the process exists but belongs to someone else -- still keeps
         * the directory, same as before this fix.
         */
        public function testAnEpermErrnoIsStillKept(): void
        {
            $dir = $this->plant(self::deadPid());

            TempCopySweeperPosixErrnoStub::$active = true;
            TempCopySweeperPosixErrnoStub::$forcedErrno = 1; // EPERM: alive, belongs to someone else.

            TempCopySweeper::sweepDirectory($this->root);

            self::assertDirectoryExists($dir, 'an EPERM errno must still be treated as alive');
        }
    }
}
