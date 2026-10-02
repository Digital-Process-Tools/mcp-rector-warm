<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Support\CallRequestFrame;
use PHPUnit\Framework\TestCase;

/**
 * #213 (then the rest, call-request slice): the request-decode guard
 * duplicated three times in RectorRunner (serveWorker(), runSessionBody(),
 * serveProcessWorker() -- `\is_array($request) && ($request[...] ?? default)
 * === true`-shaped reads, one per field) now lives once in CallRequestFrame.
 * Pinned directly against its own decode(), independent of a real socket.
 * Every case here mirrors what the three call sites already did on the same
 * input before this slice -- a malformed/missing field has always silently
 * defaulted, and that silent default is the behaviour under test, not a new
 * one.
 */
final class CallRequestFrameTest extends TestCase
{
    public function testWellFormedRequestExposesEveryField(): void
    {
        $frame = CallRequestFrame::decode((string) json_encode([
            'argv' => ['process', 'src/Foo.php'],
            'warm_boot' => true,
            'dry_run' => false,
            'no_session' => true,
            'path' => '/tmp/Foo.php',
            'buffer_copy' => true,
            'deadline_ns' => 123456,
        ]));

        self::assertSame(['process', 'src/Foo.php'], $frame->argv());
        self::assertTrue($frame->bool('warm_boot', false));
        self::assertFalse($frame->bool('dry_run', true));
        self::assertTrue($frame->bool('no_session', false));
        self::assertSame('/tmp/Foo.php', $frame->string('path'));
        self::assertTrue($frame->bool('buffer_copy', false));
        self::assertSame(123456, $frame->intOrNull('deadline_ns'));
    }

    public function testMissingFieldsFallBackToTheGivenDefault(): void
    {
        $frame = CallRequestFrame::decode((string) json_encode(['argv' => []]));

        self::assertSame([], $frame->argv());
        self::assertFalse($frame->bool('warm_boot', false));
        // serveWorker()'s own dry_run default is true, matching
        // `($request['dry_run'] ?? true) === true` -- the only field among
        // the three sites whose missing-key default is not false (#72).
        self::assertTrue($frame->bool('dry_run', true));
        self::assertFalse($frame->bool('no_session', false));
        self::assertSame('', $frame->string('path'));
        self::assertNull($frame->intOrNull('deadline_ns'));
    }

    public function testArgvDropsNonStringEntries(): void
    {
        $frame = CallRequestFrame::decode((string) json_encode([
            'argv' => ['process', 42, null, 'src/Foo.php'],
        ]));

        self::assertSame(['process', 'src/Foo.php'], $frame->argv());
    }

    public function testArgvNotAnArrayIsEmptyList(): void
    {
        $frame = CallRequestFrame::decode((string) json_encode(['argv' => 'not-a-list']));

        self::assertSame([], $frame->argv());
    }

    public function testBoolFieldPresentButNotLiterallyTrueIsFalse(): void
    {
        // `($request['warm_boot'] ?? false) === true` is a strict comparison:
        // any present-but-non-boolean-true value (here the string "true")
        // must still read as false, exactly like the hand-rolled guard did.
        $frame = CallRequestFrame::decode((string) json_encode(['warm_boot' => 'true']));

        self::assertFalse($frame->bool('warm_boot', false));
    }

    public function testBoolFieldPresentButNullFallsBackToTheDefaultLikeAMissingKey(): void
    {
        // `??`, not `array_key_exists`: `($request['dry_run'] ?? true) === true`
        // reads a present-but-null value exactly like a missing one, which
        // matters only for dry_run (serveWorker()'s one bool field whose
        // default is true, not false, per #72's "never silently unkillable").
        // A naive array_key_exists()-based implementation would instead
        // evaluate `null === true` and return false here -- flipping #72's
        // safety direction for this one malformed-but-present shape.
        $frame = CallRequestFrame::decode((string) json_encode(['dry_run' => null]));

        self::assertTrue($frame->bool('dry_run', true));
        self::assertFalse($frame->bool('warm_boot', false));
    }

    public function testDeadlineNsNotAnIntIsNull(): void
    {
        $frame = CallRequestFrame::decode((string) json_encode(['deadline_ns' => '123']));

        self::assertNull($frame->intOrNull('deadline_ns'));
    }

    public function testPathNullIsEmptyString(): void
    {
        $frame = CallRequestFrame::decode((string) json_encode(['path' => null]));

        self::assertSame('', $frame->string('path'));
    }

    public function testNullRawIsEveryFieldAtItsDefault(): void
    {
        $frame = CallRequestFrame::decode(null);

        self::assertSame([], $frame->argv());
        self::assertTrue($frame->bool('dry_run', true));
        self::assertFalse($frame->bool('warm_boot', false));
        self::assertSame('', $frame->string('path'));
        self::assertNull($frame->intOrNull('deadline_ns'));
    }

    public function testNonJsonRawIsEveryFieldAtItsDefault(): void
    {
        $frame = CallRequestFrame::decode('not json at all');

        self::assertSame([], $frame->argv());
        self::assertFalse($frame->bool('warm_boot', false));
    }

    public function testJsonScalarRawIsEveryFieldAtItsDefault(): void
    {
        // json_decode('"ok"', true) decodes successfully to a string, not an
        // array -- the is_array() half of every original guard must reject
        // this even though json_decode() itself did not fail.
        $frame = CallRequestFrame::decode('"ok"');

        self::assertSame([], $frame->argv());
        self::assertFalse($frame->bool('warm_boot', false));
    }

    public function testEmptyStringRawIsEveryFieldAtItsDefault(): void
    {
        // readFrame() returns '' (not null) for a zero-length frame -- a
        // distinct case from EOF (null), and json_decode('') is also not an
        // array, so every guard must still reject it the same way.
        $frame = CallRequestFrame::decode('');

        self::assertSame([], $frame->argv());
        self::assertFalse($frame->bool('warm_boot', false));
    }
}
