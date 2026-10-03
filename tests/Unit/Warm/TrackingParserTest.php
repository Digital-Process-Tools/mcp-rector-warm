<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Warm;

use Dpt\McpRectorWarm\Warm\TrackingParser;
use PhpParser\Node\Stmt\Nop;
use PHPStan\Parser\Parser;
use PHPUnit\Framework\TestCase;

/**
 * #227: no direct test exercised TrackingParser's own three methods before
 * this file -- every existing use only built one (SessionHooksTest's
 * installParserHook() tests) or stood a fake in for it (WarmSessionTest's
 * FakeSymbolResolver docblock). parseFile() and parseString() diverge on
 * exactly the thing #227 asks about: whether $onParseFile runs.
 */
final class TrackingParserTest extends TestCase
{
    public function testParseFileReportsTheFileToTheHookBeforeDelegatingToTheInnerParser(): void
    {
        /** @var list<string> $calls */
        $calls = [];
        $inner = self::recordingParser(static function (string $event) use (&$calls): void {
            $calls[] = $event;
        });
        $tracking = new TrackingParser($inner, static function (string $file) use (&$calls): void {
            $calls[] = 'hook:' . $file;
        });

        $nodes = $tracking->parseFile('/project/src/A.php');

        // Order matters (TrackingParser's own docblock): the hook must see the
        // file BEFORE the inner parser reads it, not after.
        self::assertSame(['hook:/project/src/A.php', 'parseFile:/project/src/A.php'], $calls);
        self::assertCount(1, $nodes);
        self::assertInstanceOf(Nop::class, $nodes[0]);
    }

    public function testParseStringDelegatesToTheInnerParserWithoutReportingAFile(): void
    {
        /** @var list<string> $calls */
        $calls = [];
        $inner = self::recordingParser(static function (string $event) use (&$calls): void {
            $calls[] = $event;
        });
        /** @var list<string> $hookCalls */
        $hookCalls = [];
        $tracking = new TrackingParser($inner, static function (string $file) use (&$hookCalls): void {
            $hookCalls[] = $file;
        });

        $nodes = $tracking->parseString('<?php class A {}');

        // Positive control: parseFile() DOES call the hook (previous test).
        // This asserts the other half -- parseString() has no file to report,
        // so the hook must not fire at all.
        self::assertSame([], $hookCalls, 'parseString() has no file path to report');
        self::assertSame(['parseString:<?php class A {}'], $calls);
        self::assertCount(1, $nodes);
        self::assertInstanceOf(Nop::class, $nodes[0]);
    }

    public function testInnerReturnsTheWrappedParser(): void
    {
        $inner = self::recordingParser(static function (string $event): void {});
        $tracking = new TrackingParser($inner, static function (string $file): void {});

        self::assertSame($inner, $tracking->inner());
    }

    /**
     * @param \Closure(string): void $record
     */
    private static function recordingParser(\Closure $record): Parser
    {
        return new class ($record) implements Parser {
            /**
             * @param \Closure(string): void $record
             */
            public function __construct(private readonly \Closure $record) {}

            public function parseFile(string $file): array
            {
                ($this->record)('parseFile:' . $file);

                return [new Nop()];
            }

            public function parseString(string $sourceCode): array
            {
                ($this->record)('parseString:' . $sourceCode);

                return [new Nop()];
            }
        };
    }
}
