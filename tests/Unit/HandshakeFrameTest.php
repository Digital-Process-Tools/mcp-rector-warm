<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Support\HandshakeFrame;
use PHPUnit\Framework\TestCase;

/**
 * #213 (frames first): the guard duplicated three times in RectorRunner
 * (`!is_array($decoded) || ($decoded['ok'] ?? false) !== true`, plus the
 * is_array-and-isset 'error' extraction) now lives once in HandshakeFrame.
 * Pinned directly against its own decode(), independent of a real socket.
 */
final class HandshakeFrameTest extends TestCase
{
    public function testOkTrueFrameDecodesSuccessfullyWithItsFields(): void
    {
        $frame = HandshakeFrame::decode((string) json_encode([
            'ok' => true,
            'tracked' => 3,
            'directories' => 2,
            'config_file' => '/tmp/rector.php',
            'bootstrap_files' => ['/tmp/bootstrap.php' => 'abc'],
        ]));

        self::assertTrue($frame->ok);
        self::assertNull($frame->error);
        self::assertTrue($frame->decodedToArray());
        self::assertSame(3, $frame->int('tracked'));
        self::assertSame(2, $frame->int('directories'));
        self::assertSame('/tmp/rector.php', $frame->string('config_file'));
        self::assertSame(['/tmp/bootstrap.php' => 'abc'], $frame->array('bootstrap_files'));
    }

    public function testMissingFieldsFallBackToTheirNeutralValue(): void
    {
        $frame = HandshakeFrame::decode((string) json_encode(['ok' => true]));

        self::assertTrue($frame->ok);
        self::assertNull($frame->string('config_file'));
        self::assertSame([], $frame->array('bootstrap_files'));
        self::assertSame(0, $frame->int('tracked'));
    }

    public function testOkFalseWithErrorIsNotOkAndExposesTheError(): void
    {
        $frame = HandshakeFrame::decode((string) json_encode([
            'ok' => false,
            'error' => 'boom',
        ]));

        self::assertFalse($frame->ok);
        self::assertSame('boom', $frame->error);
        self::assertTrue($frame->decodedToArray());
    }

    public function testOkFalseWithNoErrorKeyDecodedToArrayButNoError(): void
    {
        // RectorRunner::spawnSession()'s own fallback for this exact case
        // ('unknown error') is why decodedToArray() exists separately from
        // $error: a well-formed-but-failed frame and a payload that never
        // decoded both leave $error null, and only decodedToArray() tells
        // those two apart.
        $frame = HandshakeFrame::decode((string) json_encode(['ok' => false]));

        self::assertFalse($frame->ok);
        self::assertNull($frame->error);
        self::assertTrue($frame->decodedToArray());
    }

    public function testOkMissingEntirelyIsNotOk(): void
    {
        $frame = HandshakeFrame::decode((string) json_encode(['tracked' => 1]));

        self::assertFalse($frame->ok);
    }

    public function testNullRawIsNotOkAndDidNotDecodeToArray(): void
    {
        $frame = HandshakeFrame::decode(null);

        self::assertFalse($frame->ok);
        self::assertNull($frame->error);
        self::assertFalse($frame->decodedToArray());
        self::assertSame([], $frame->array('bootstrap_files'));
        self::assertNull($frame->string('config_file'));
        self::assertSame(0, $frame->int('tracked'));
    }

    public function testNonJsonRawIsNotOkAndDidNotDecodeToArray(): void
    {
        $frame = HandshakeFrame::decode('not json at all');

        self::assertFalse($frame->ok);
        self::assertFalse($frame->decodedToArray());
    }

    public function testEmptyStringRawIsNotOkAndDidNotDecodeToArray(): void
    {
        // readFrame() returns '' (not null) for a zero-length frame -- a
        // distinct case from EOF (null), and json_decode('') is also not an
        // array, so the guard must still reject it the same way.
        $frame = HandshakeFrame::decode('');

        self::assertFalse($frame->ok);
        self::assertFalse($frame->decodedToArray());
    }

    public function testJsonScalarRawIsNotOkAndDidNotDecodeToArray(): void
    {
        // json_decode('"ok"', true) decodes successfully to a string, not an
        // array -- the is_array() half of the guard must reject this even
        // though json_decode() itself did not fail.
        $frame = HandshakeFrame::decode('"ok"');

        self::assertFalse($frame->ok);
        self::assertFalse($frame->decodedToArray());
    }
}
