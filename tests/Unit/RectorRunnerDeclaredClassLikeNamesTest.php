<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorRunner;
use PHPUnit\Framework\TestCase;

/**
 * #190: declaredClassLikeNames() is the piece remapCopyClassesInAutoloader()
 * uses to find which classes a buffer copy declares, so the copy -- not the
 * stale original on disk -- wins when PHPStan resolves them by name. Tested
 * directly (a pure tokenizer, no container needed) rather than only through
 * the E2E acceptance scenario in tests/E2E/test_lsp_session.py.
 */
final class RectorRunnerDeclaredClassLikeNamesTest extends TestCase
{
    public function testANamespacedClassIsFound(): void
    {
        self::assertSame(['App\Foo'], $this->names('<?php declare(strict_types=1); namespace App; class Foo {}'));
    }

    public function testEveryClassLikeKeywordIsFound(): void
    {
        self::assertSame(
            ['App\AClass', 'App\AnInterface', 'App\ATrait', 'App\AnEnum'],
            $this->names(
                '<?php namespace App; '
                . 'class AClass {} interface AnInterface {} trait ATrait {} enum AnEnum {}'
            ),
        );
    }

    public function testNoNamespaceIsTheGlobalOne(): void
    {
        self::assertSame(['Foo'], $this->names('<?php class Foo {}'));
    }

    public function testAnAnonymousClassIsNotADeclaration(): void
    {
        // Must not fire: `new class` is an expression, not a name PHPStan's
        // standard chain could ever resolve by FQCN.
        self::assertSame([], $this->names('<?php namespace App; $x = new class { public function f(): void {} };'));
    }

    public function testAnAnonymousClassDoesNotHideARealOneInTheSameFile(): void
    {
        // Positive control for the case above: a real declaration alongside
        // an anonymous one is still found.
        self::assertSame(
            ['App\Real'],
            $this->names('<?php namespace App; class Real {} $x = new class { public function f(): void {} };'),
        );
    }

    public function testAnUnparseableFileReturnsNoNames(): void
    {
        // token_get_all() on malformed input never throws (it tokenizes best-
        // effort), so this is really pinning "no crash", not a catch branch.
        self::assertSame([], $this->names('not php at all {{{'));
    }

    /**
     * @return list<string>
     */
    private function names(string $content): array
    {
        /** @var list<string> */
        return (new \ReflectionMethod(RectorRunner::class, 'declaredClassLikeNames'))->invoke(null, $content);
    }
}
