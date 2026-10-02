<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Typed reads into decoded JSON / JSON-RPC frames for tests (#213, PHPStan
 * level 9): `Json::at($frame, 'params', 'diagnostics', 0)` instead of
 * `$frame['params']['diagnostics'][0]`. Every step asserts it is reading an
 * array that has the key, so a wrong shape fails the test with a message
 * naming the path, where the bare chain only raised a warning (which
 * failOnWarning turned into a less specific failure).
 */
final class Json
{
    public static function at(mixed $value, int|string ...$path): mixed
    {
        $walked = '';
        foreach ($path as $key) {
            Assert::assertIsArray($value, \sprintf('expected an array at "%s"', $walked === '' ? '(root)' : $walked));
            $walked .= '[' . \var_export($key, true) . ']';
            Assert::assertArrayHasKey($key, $value, \sprintf('missing key at "%s"', $walked));
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * The tolerant read: what `$value[a][b] ?? null` gives, without the
     * offset-on-mixed access -- null as soon as a step is not an array or
     * lacks the key. For a test's diagnostics text and "absent is fine"
     * lookups; an assertion reads through at() instead.
     */
    public static function find(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /** @return array<mixed> */
    public static function array(mixed $value, int|string ...$path): array
    {
        $found = self::at($value, ...$path);
        Assert::assertIsArray($found);

        return $found;
    }

    public static function string(mixed $value, int|string ...$path): string
    {
        $found = self::at($value, ...$path);
        Assert::assertIsString($found);

        return $found;
    }

    public static function int(mixed $value, int|string ...$path): int
    {
        $found = self::at($value, ...$path);
        Assert::assertIsInt($found);

        return $found;
    }

    /** The string at $key of $value, or null when $value has no such key. */
    public static function optionalString(mixed $value, int|string $key): ?string
    {
        Assert::assertIsArray($value);
        if (!\array_key_exists($key, $value) || $value[$key] === null) {
            return null;
        }
        Assert::assertIsString($value[$key]);

        return $value[$key];
    }
}
