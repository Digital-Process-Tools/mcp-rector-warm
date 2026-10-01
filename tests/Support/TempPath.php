<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Temp-dir cleanup for tests that spawn or kill subprocesses.
 *
 * On Windows a process that was just killed or reaped can still hold a handle
 * in the test's temp dir for a moment, so the teardown's rmdir()/unlink() fails
 * with "Resource temporarily unavailable". Under failOnWarning that warning
 * failed the coverage-windows job even though every test passed. On Windows
 * this retries with a short backoff (about 2s in total) and then fails the test
 * with a clear message. On every other OS it is the plain call, as before.
 */
final class TempPath
{
    private const WINDOWS_RETRY_BUDGET_US = 2_000_000;

    public static function unlink(string $path): void
    {
        self::remove('unlink', $path);
    }

    public static function rmdir(string $path): void
    {
        self::remove('rmdir', $path);
    }

    /**
     * @param 'unlink'|'rmdir' $function
     */
    private static function remove(string $function, string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $function($path);

            return;
        }

        $sleptUs = 0;
        $delayUs = 10_000;
        while (!@$function($path)) {
            if ($sleptUs >= self::WINDOWS_RETRY_BUDGET_US) {
                $error = error_get_last();
                Assert::fail(sprintf(
                    '%s(%s) still failed after %.1fs of retries on Windows: %s',
                    $function,
                    $path,
                    $sleptUs / 1_000_000,
                    $error['message'] ?? 'no error message',
                ));
            }
            usleep($delayUs);
            $sleptUs += $delayUs;
            $delayUs = min($delayUs * 2, 250_000);
        }
    }
}
