<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Support;

/**
 * Explicit narrowing for values that arrive untyped (json_decode() output,
 * PHPStan container parameters, LSP request params) -- #213, PHPStan level 9.
 *
 * Each method keeps PHP's own cast semantics for every scalar and null, so a
 * caller that used to write `(string) $value` or `(int) $value` gets the exact
 * same result on every input it could already handle. The one difference is
 * an array or object: the bare cast silently produced "Array" (plus a
 * warning) or 1, while these throw an UnexpectedValueException naming what
 * was being read. UnexpectedValueException is a RuntimeException, so a caller
 * that already catches RuntimeException around the decode still catches it.
 */
final class Scalar
{
    /** @throws \UnexpectedValueException when $value is neither scalar nor null */
    public static function toString(mixed $value, string $what): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if ($value === null || \is_int($value) || \is_float($value) || \is_bool($value)) {
            return (string) $value;
        }

        throw self::unexpected($value, $what);
    }

    /** @throws \UnexpectedValueException when $value is neither scalar nor null */
    public static function toInt(mixed $value, string $what): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if ($value === null || \is_string($value) || \is_float($value) || \is_bool($value)) {
            return (int) $value;
        }

        throw self::unexpected($value, $what);
    }

    private static function unexpected(mixed $value, string $what): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf('%s: expected a scalar, got %s', $what, \get_debug_type($value)));
    }
}
