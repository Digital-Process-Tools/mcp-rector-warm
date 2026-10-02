<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Support\CallResultFrame;
use PHPUnit\Framework\TestCase;

/**
 * #213 (then the rest, call-result slice): the result-decode guard duplicated
 * four times in RectorRunner (runForked(), forkAndExecute(), runCold(),
 * callProcWorker()) -- `json_decode()` -> `!is_array($decoded)` check -> an
 * `isset($decoded['error'])` check -> cast-and-return -- now lives once in
 * CallResultFrame. Pinned directly against its own decode(), independent of a
 * real worker. Every call site still builds its OWN failure message using
 * worker-local context (an exit status, a stderr tail) this class has no
 * access to; this class only classifies the raw payload, exactly like
 * HandshakeFrame and CallRequestFrame before it (#213).
 */
final class CallResultFrameTest extends TestCase
{
    public function testWellFormedResultDecodesToArray(): void
    {
        $frame = CallResultFrame::decode((string) json_encode([
            'exit_code' => 0,
            'output' => 'no errors found',
            'warm_boot' => true,
        ]));

        self::assertTrue($frame->decodedToArray());
        self::assertNull($frame->error());
        self::assertSame([
            'exit_code' => 0,
            'output' => 'no errors found',
            'warm_boot' => true,
        ], $frame->fields());
    }

    public function testErrorFieldIsExposedAsAString(): void
    {
        $frame = CallResultFrame::decode((string) json_encode(['error' => 'boom']));

        self::assertTrue($frame->decodedToArray());
        self::assertSame('boom', $frame->error());
    }

    public function testErrorFieldIsCastToStringLikeTheOriginalGuard(): void
    {
        // Every original call site did `(string) $decoded['error']`, not an
        // is_string() check first -- a non-string-but-present error value
        // (here an int) must still come back as a string, not null.
        $frame = CallResultFrame::decode((string) json_encode(['error' => 42]));

        self::assertSame('42', $frame->error());
    }

    public function testMissingErrorFieldIsNull(): void
    {
        $frame = CallResultFrame::decode((string) json_encode(['exit_code' => 0]));

        self::assertNull($frame->error());
    }

    public function testNullRawDoesNotDecodeToArray(): void
    {
        $frame = CallResultFrame::decode(null);

        self::assertFalse($frame->decodedToArray());
        self::assertNull($frame->error());
        self::assertSame([], $frame->fields());
    }

    public function testEmptyStringRawDoesNotDecodeToArray(): void
    {
        // readFrame()/the accumulated $raw buffer can be '' (not null) for a
        // zero-length frame -- json_decode('') is also not an array, so this
        // must be rejected exactly like the null case.
        $frame = CallResultFrame::decode('');

        self::assertFalse($frame->decodedToArray());
        self::assertSame([], $frame->fields());
    }

    public function testNonJsonRawDoesNotDecodeToArray(): void
    {
        $frame = CallResultFrame::decode('not json at all');

        self::assertFalse($frame->decodedToArray());
        self::assertSame([], $frame->fields());
    }

    public function testJsonScalarRawDoesNotDecodeToArray(): void
    {
        // json_decode('"ok"', true) decodes successfully to a string, not an
        // array -- the is_array() half of every original guard must reject
        // this even though json_decode() itself did not fail.
        $frame = CallResultFrame::decode('"ok"');

        self::assertFalse($frame->decodedToArray());
        self::assertSame([], $frame->fields());
    }
}
