<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Support\Scalar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #213 (PHPStan level 9): Scalar replaces bare `(string)` / `(int)` casts
 * of untyped values. It must give the cast's exact result for every scalar
 * and null -- that is what keeps behaviour identical on valid input -- and
 * throw only where the bare cast silently produced garbage.
 */
final class ScalarTest extends TestCase
{
    /** @return iterable<string, array{string|int|float|bool|null}> */
    public static function scalars(): iterable
    {
        yield 'string' => ['abc'];
        yield 'empty string' => [''];
        yield 'numeric string' => ['12'];
        yield 'int' => [42];
        yield 'negative int' => [-3];
        yield 'float' => [1.5];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'null' => [null];
    }

    #[DataProvider('scalars')]
    public function testToStringMatchesTheCastForEveryScalar(string|int|float|bool|null $value): void
    {
        self::assertSame((string) $value, Scalar::toString($value, 'x'));
    }

    #[DataProvider('scalars')]
    public function testToIntMatchesTheCastForEveryScalar(string|int|float|bool|null $value): void
    {
        self::assertSame((int) $value, Scalar::toInt($value, 'x'));
    }

    public function testToStringThrowsNamingWhatWasReadForAnArray(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('call result frame "error": expected a scalar, got array');

        Scalar::toString(['nested'], 'call result frame "error"');
    }

    public function testToIntThrowsForAnObject(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('handshake frame "tracked": expected a scalar, got stdClass');

        Scalar::toInt(new \stdClass(), 'handshake frame "tracked"');
    }
}
