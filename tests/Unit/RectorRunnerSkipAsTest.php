<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit;

use Dpt\McpRectorWarm\RectorRunner;
use PHPUnit\Framework\TestCase;

/**
 * #106 review findings on RectorRunner::applySkipsOfOriginalPath(), driven
 * with a fake container so each Rector-version shape is reachable here.
 */
final class RectorRunnerSkipAsTest extends TestCase
{
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
            public function resolve(): array
            {
                return [];
            }
        }, new class {
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

        $resolved = $classes->resolve();
        self::assertContains('/p/src/.rector-warm-1/Foo.php', $resolved['App\\SomeRule']);
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

        self::assertContains('/p/src/.rector-warm-1/Foo.php', $classes->resolve()['App\\SomeRule']);
        self::assertFalse($skipper->marked);
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
            private array $skippedPaths = [];

            public function resolve(): array
            {
                return $this->skippedPaths;
            }
        };
    }

    /** @param array<string, list<string>|null> $classes */
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
            public function __construct(private ?array $skippedClassesToFiles) {}

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
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
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
            posix_kill(getmypid(), SIGKILL);
        }

        fclose($child);
        $answer = stream_get_contents($parent);
        fclose($parent);
        pcntl_waitpid($pid, $status);

        return (string) $answer;
    }
}
