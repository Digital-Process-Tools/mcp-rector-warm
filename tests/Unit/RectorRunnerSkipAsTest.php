<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\Tests\Support\Json;
use Dpt\McpRectorWarm\RectorRunner;
use PHPUnit\Framework\TestCase;

/**
 * #106 review findings on RectorRunner::applySkipsOfOriginalPath(), driven
 * with a fake container so each Rector-version shape is reachable here.
 */
final class RectorRunnerSkipAsTest extends TestCase
{
    /**
     * Calls resolve() on a fake resolver typed only as `object` in this test
     * (its real methods are only known to the fake itself) -- a direct
     * `$resolver->resolve()` is correctly flagged method.notFound for a
     * plain `object`; going through a `string $method` parameter crosses a
     * boundary PHPStan does not look through, so this is the honest way to
     * say "resolved at runtime, not a typo".
     *
     * @return array<string, mixed>
     */
    private static function callResolve(object $resolver, string $method = 'resolve'): array
    {
        return $resolver->$method();
    }

    public function testARenamedResolverPropertyFailsOpenInAChildWhoseStderrIsClosed(): void
    {
        // The forked grandchild closes fd 2 before running (runForked()).
        // The fail-open path used to fwrite() to that closed STDERR, which
        // throws a TypeError out of the catch block: the whole call failed
        // and the buffer got an Error diagnostic instead of being diagnosed
        // without the skips.
        if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            self::markTestSkipped('needs pcntl_fork, as the forked grandchild does');
        }

        $runner = $this->runnerWith($this->skipper(pathSkipped: true), new class {
            // the renamed-by-a-future-Rector shape: no `skippedPaths`
            /** @return list<string> */
            public function resolve(): array
            {
                return [];
            }
        }, new class {
            /** @return array<string, list<string>|null> */
            public function resolve(): array
            {
                return [];
            }
        });

        self::assertSame('returned', $this->inChildWithStderrClosed(function () use ($runner): void {
            $this->apply($runner, '/p/src/Foo.php', '/p/src/.rector-warm-1/Foo.php');
        }));
    }

    public function testTheSameChildReportsAThrowAsAThrow(): void
    {
        // Positive control for the harness above: a throw escaping the call
        // is reported, so 'returned' there is not a harness that cannot fail.
        if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            self::markTestSkipped('needs pcntl_fork');
        }

        self::assertSame('threw TypeError', $this->inChildWithStderrClosed(static function (): void {
            fwrite(STDERR, 'x');
        }));
    }

    public function testARuleScopedSkipMapsToTheCopyOnARectorWithoutMatchSkip(): void
    {
        // Skipper::matchSkip() only exists from Rector 2.5.2; composer.json
        // allows ^2.4, whose Skipper has shouldSkipElementAndFilePath() only.
        $classes = $this->classResolver(['App\\SomeRule' => ['/p/src/Foo.php'], 'App\\Other' => ['/p/src/Bar.php']]);
        $skipper = new class {
            public function shouldSkipFilePath(string $path): bool
            {
                return false;
            }

            public function shouldSkipElementAndFilePath(string|object $element, string $path): bool
            {
                return $element === 'App\\SomeRule' && $path === '/p/src/Foo.php'
                    || $element === 'App\\Other' && $path === '/p/src/Bar.php';
            }
        };

        $this->apply($this->runnerWith($skipper, $this->pathsResolver(), $classes), '/p/src/Foo.php', '/p/src/.rector-warm-1/Foo.php');

        $resolved = self::callResolve($classes);
        self::assertContains('/p/src/.rector-warm-1/Foo.php', Json::array($resolved, 'App\\SomeRule'));
        self::assertSame(['/p/src/Bar.php'], $resolved['App\\Other']);
    }

    public function testARuleScopedSkipMapsToTheCopyWithMatchSkip(): void
    {
        // Rector 2.5.2+ (the locked version): matchSkip() is used, since
        // shouldSkipElementAndFilePath() there also marks the skip as used.
        $classes = $this->classResolver(['App\\SomeRule' => ['/p/src/Foo.php']]);
        $skipper = new class {
            public bool $marked = false;

            public function shouldSkipFilePath(string $path): bool
            {
                return false;
            }

            public function matchSkip(string|object $element, string $path): ?object
            {
                return $path === '/p/src/Foo.php' ? new \stdClass() : null;
            }

            public function shouldSkipElementAndFilePath(string|object $element, string $path): bool
            {
                $this->marked = true;

                return $path === '/p/src/Foo.php';
            }
        };

        $this->apply($this->runnerWith($skipper, $this->pathsResolver(), $classes), '/p/src/Foo.php', '/p/src/.rector-warm-1/Foo.php');

        self::assertContains('/p/src/.rector-warm-1/Foo.php', Json::array(self::callResolve($classes), 'App\\SomeRule'));
        self::assertFalse($skipper->marked);
    }

    /**
     * #213 E2E finding: `Rule::class => 'src/Foo.php'` (a plain string, not a
     * list) is valid Rector config, and resolve() can hand it back as that
     * string. Level-9 narrowing must read it as the one-element list Rector
     * means, not throw -- a throw fails the whole applySkipsOfOriginalPath()
     * open, so every OTHER rule-scoped skip stopped reaching the buffer copy.
     * Both rules below are skipped for the original: the string-form one
     * (must fire) and the list-form one (positive control, same call).
     */
    public function testAStringFormRuleSkipIsReadAsAOneElementListAndMapsToTheCopy(): void
    {
        $classes = $this->classResolver([
            'App\\StringRule' => '/p/src/Foo.php',
            'App\\ListRule' => ['/p/src/Foo.php'],
            'App\\Elsewhere' => '/p/src/Bar.php',
        ]);
        $skipper = new class {
            public function shouldSkipFilePath(string $path): bool
            {
                return false;
            }

            public function matchSkip(string|object $element, string $path): ?object
            {
                return $element !== 'App\\Elsewhere' && $path === '/p/src/Foo.php' ? new \stdClass() : null;
            }
        };

        $this->apply($this->runnerWith($skipper, $this->pathsResolver(), $classes), '/p/src/Foo.php', '/p/src/.rector-warm-1/Foo.php');

        $resolved = self::callResolve($classes);
        self::assertSame(['/p/src/Foo.php', '/p/src/.rector-warm-1/Foo.php'], $resolved['App\\StringRule']);
        self::assertSame(['/p/src/Foo.php', '/p/src/.rector-warm-1/Foo.php'], $resolved['App\\ListRule']);
        // Untouched entries keep Rector's own shape, string form included.
        self::assertSame('/p/src/Bar.php', $resolved['App\\Elsewhere']);
    }

    private function skipper(bool $pathSkipped): object
    {
        return new class ($pathSkipped) {
            public function __construct(private readonly bool $pathSkipped) {}

            public function shouldSkipFilePath(string $path): bool
            {
                return $this->pathSkipped;
            }
        };
    }

    private function pathsResolver(): object
    {
        return new class {
            /** @var list<string> */
            private array $skippedPaths = [];

            /** @return list<string> */
            public function resolve(): array
            {
                return $this->skippedPaths;
            }
        };
    }

    /** @param array<string, list<string>|string|null> $classes */
    private function classResolver(array $classes): object
    {
        return new class ($classes) {
            // Not readonly: RectorRunner::overwriteResolved() rewrites this
            // property via ReflectionProperty::setValue() to graft the buffer
            // copy's path onto a rule's skip list (see applySkipsOfOriginalPath()),
            // the same way it treats Rector's real SkippedClassResolver -- a
            // readonly property here would make PHP throw on that write and
            // this stub would stop matching production shape (caught by
            // ReadOnlyPropertyRector wrongly proposing readonly here).
            /** @param array<string, list<string>|string|null>|null $skippedClassesToFiles */
            public function __construct(private ?array $skippedClassesToFiles) {}

            /** @return array<string, list<string>|string|null> */
            public function resolve(): array
            {
                return $this->skippedClassesToFiles ?? [];
            }
        };
    }

    private function runnerWith(object $skipper, object $pathsResolver, object $classResolver): RectorRunner
    {
        $services = [
            \Rector\Skipper\Skipper\Skipper::class => $skipper,
            \Rector\Skipper\SkipCriteriaResolver\SkippedPathsResolver::class => $pathsResolver,
            \Rector\Skipper\SkipCriteriaResolver\SkippedClassResolver::class => $classResolver,
        ];
        $container = new class ($services) {
            /** @param array<class-string, object> $services */
            public function __construct(private array $services) {}

            public function get(string $id): object
            {
                return $this->services[$id];
            }
        };

        $runner = new RectorRunner();
        (new \ReflectionProperty($runner, 'container'))->setValue($runner, $container);

        return $runner;
    }

    private function apply(RectorRunner $runner, string $original, string $copy): void
    {
        (new \ReflectionMethod($runner, 'applySkipsOfOriginalPath'))->invoke($runner, $original, $copy);
    }

    private function inChildWithStderrClosed(callable $body): string
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$parent, $child] = $pair;
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent);
            @fclose(STDERR);
            try {
                $body();
                fwrite($child, 'returned');
            } catch (\Throwable $e) {
                fwrite($child, 'threw ' . (new \ReflectionClass($e))->getShortName());
            }
            fclose($child);
            // Skip PHPUnit's shutdown handlers in the child.
            $selfPid = getmypid();
            posix_kill($selfPid !== false ? $selfPid : 0, SIGKILL);
        }

        fclose($child);
        $answer = stream_get_contents($parent);
        fclose($parent);
        pcntl_waitpid($pid, $status);

        return (string) $answer;
    }
}
