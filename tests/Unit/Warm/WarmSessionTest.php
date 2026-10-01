<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Warm;

use Dpt\McpRectorWarm\Warm\DependencyFileTracker;
use Dpt\McpRectorWarm\Warm\DirectorySnapshot;
use Dpt\McpRectorWarm\Warm\Path;
use Dpt\McpRectorWarm\Warm\SymbolResolver;
use Dpt\McpRectorWarm\Warm\WarmSession;
use PHPUnit\Framework\TestCase;

/**
 * #185: the session child's own bookkeeping -- what makes it stale, and which
 * calls it may serve itself. Each must-not-fire case is paired with a must-fire
 * one on the same fixture.
 */
final class WarmSessionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/warm-session-test-' . \bin2hex(\random_bytes(8));
        \mkdir($this->dir . '/src', 0o777, true);
        \mkdir($this->dir . '/lib', 0o777, true);
        // Real, '/'-separated: the form WarmSession compares paths in.
        $this->dir = (string) Path::real($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (['src', 'lib', ''] as $sub) {
            foreach (\glob($this->dir . '/' . $sub . '/*.php') ?: [] as $file) {
                @\unlink($file);
            }
        }
        @\rmdir($this->dir . '/src');
        @\rmdir($this->dir . '/lib');
        @\rmdir($this->dir);
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->dir . '/' . $relative;
        \file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param array<string, list<array{0: string, 1: string}>> $declared path => symbols
     * @param array<string, ?string> $standard name => file ('' = found, no file)
     * @param array<string, ?string> $autoloadPaths name => file
     */
    private function resolver(array $declared = [], array $standard = [], array $autoloadPaths = []): FakeSymbolResolver
    {
        return new FakeSymbolResolver($declared, $standard, $autoloadPaths);
    }

    /** @param list<string> $autoloadDirectories */
    private function session(?FakeSymbolResolver $resolver = null, ?DirectorySnapshot $directories = null, array $autoloadDirectories = []): WarmSession
    {
        $resolver ??= $this->resolver();
        $session = new WarmSession($resolver, new DependencyFileTracker(), $directories, \get_included_files(), $autoloadDirectories);
        $resolver->session = $session;

        return $session;
    }

    public function testAFreshSessionIsNotStale(): void
    {
        $session = $this->session();
        $session->recordRead($this->write('src/Base.php', '<?php class Base {}'));

        self::assertNull($session->staleReason());
    }

    public function testAReadFileThatChangesMakesTheSessionStale(): void
    {
        $session = $this->session();
        $base = $this->write('src/Base.php', '<?php class Base {}');
        $session->recordRead($base);
        \file_put_contents($base, '<?php class Base { function run() {} }');

        self::assertStringContainsString($base, (string) $session->staleReason());
    }

    public function testAFileNeverReadMayChangeFreely(): void
    {
        $session = $this->session();
        $session->recordRead($this->write('src/Base.php', '<?php class Base {}'));
        $other = $this->write('src/Other.php', '<?php class Other {}');
        \file_put_contents($other, '<?php class Other { const X = 1; }');

        self::assertNull($session->staleReason());
    }

    public function testTheAnalysedFileIsTrackedAfterAnInSessionCall(): void
    {
        $session = $this->session();
        $child = $this->write('src/Child.php', '<?php class Child {}');
        $session->afterInSessionCall($child);
        self::assertNull($session->staleReason());

        \file_put_contents($child, '<?php class Child extends Base {}');

        self::assertStringContainsString($child, (string) $session->staleReason());
        self::assertSame(1, $session->calls());
    }

    public function testAFileIncludedDuringACallIsTrackedButOneIncludedBeforeTheSessionIsNot(): void
    {
        $before = $this->write('src/Before.php', '<?php return 1;');
        require $before;
        $session = $this->session();
        $during = $this->write('src/During.php', '<?php return 2;');
        require $during;
        $session->afterInSessionCall($this->write('src/Child.php', '<?php class Child {}'));

        // Must not fire: the pristine worker loaded it; a new session could not reload it either.
        \file_put_contents($before, '<?php return 10;');
        self::assertNull($session->staleReason());

        // Must fire: loaded inside this session.
        \file_put_contents($during, '<?php return 20;');
        self::assertStringContainsString($during, (string) $session->staleReason());
    }

    public function testAFileIncludedFromACacheDirectoryIsNotTracked(): void
    {
        \mkdir($this->dir . '/lib/cache');
        $cached = $this->dir . '/lib/cache/entry.php';
        \file_put_contents($cached, '<?php return 1;');
        $resolver = $this->resolver();
        $session = new WarmSession($resolver, new DependencyFileTracker(), null, \get_included_files(), [], [$this->dir . '/lib/cache']);
        $during = $this->write('lib/During.php', '<?php return 2;');
        require $cached;
        require $during;
        $session->afterInSessionCall($this->write('src/Child.php', '<?php class Child {}'));

        // Must not fire: PHPStan rewrites its own cache entries as a matter of course.
        \file_put_contents($cached, '<?php return 10;');
        self::assertNull($session->staleReason());
        // Must fire: the same kind of include outside the cache directory.
        \file_put_contents($during, '<?php return 20;');
        self::assertNotNull($session->staleReason());
        @\unlink($cached);
        @\rmdir($this->dir . '/lib/cache');
    }

    public function testDeclaredWatchPathsExpandToEveryFileAndKeepMissingOnes(): void
    {
        \mkdir($this->dir . '/lib/events/deep', 0o777, true);
        \file_put_contents($this->dir . '/lib/events/a.json', '{}');
        \file_put_contents($this->dir . '/lib/events/deep/b.tpl', 'x');
        $missing = $this->dir . '/lib/later.json';

        $files = WarmSession::watchedFiles([$this->dir . '/lib/events', $missing]);
        \sort($files);

        self::assertSame([$this->dir . '/lib/events/a.json', $this->dir . '/lib/events/deep/b.tpl', $missing], $files);
        @\unlink($this->dir . '/lib/events/deep/b.tpl');
        @\unlink($this->dir . '/lib/events/a.json');
        @\rmdir($this->dir . '/lib/events/deep');
        @\rmdir($this->dir . '/lib/events');
    }

    public function testAWatchedFileThatChangesOrAppearsMakesTheSessionStale(): void
    {
        $session = $this->session();
        $json = $this->write('lib/events.json', '{"a":1}');
        $later = $this->dir . '/lib/later.json';
        foreach (WarmSession::watchedFiles([$json, $later]) as $file) {
            $session->recordRead($file);
        }
        self::assertNull($session->staleReason());

        \file_put_contents($json, '{"a":2}');
        self::assertStringContainsString($json, (string) $session->staleReason());

        $fresh = $this->session();
        foreach (WarmSession::watchedFiles([$later]) as $file) {
            $fresh->recordRead($file);
        }
        self::assertNull($fresh->staleReason());
        \file_put_contents($later, '{}');
        self::assertStringContainsString($later, (string) $fresh->staleReason());
        @\unlink($later);
        @\unlink($this->dir . '/lib/events.json');
    }

    public function testADirectoryChangeMakesTheSessionStale(): void
    {
        $snapshot = new DirectorySnapshot([$this->dir], [], [], ['php']);
        $snapshot->refresh();
        $session = $this->session(null, $snapshot);
        self::assertNull($session->staleReason());

        $this->write('src/Created.php', '<?php class Created {}');

        self::assertStringContainsString($this->dir . '/src', (string) $session->staleReason());
    }

    public function testAFileWhoseSymbolsTheStandardChainFindsIsServedInSession(): void
    {
        $child = $this->write('src/Child.php', '<?php namespace App; class Child {}');
        $session = $this->session($this->resolver([$child => [['class', 'App\\Child']]], ['App\\Child' => $child]));

        self::assertSame(WarmSession::SERVE, $session->route($child, false)[0]);
    }

    public function testAFileWhoseClassTheStandardChainFindsElsewhereIsStillServed(): void
    {
        // Cold consults PHPStan's standard chain before the analysed file itself,
        // so the class comes from the other file either way.
        $copy = $this->write('lib/Child.php', '<?php namespace App; class Child {}');
        $original = $this->write('src/Child.php', '<?php namespace App; class Child {}');
        $session = $this->session($this->resolver([$copy => [['class', 'App\\Child']]], ['App\\Child' => $original]));

        self::assertSame(WarmSession::SERVE, $session->route($copy, false)[0]);
    }

    public function testAFileWithAClassNoLocatorFindsWithoutItIsDeclined(): void
    {
        // Must fire: in session its class would stay known to later calls,
        // where a cold run on another file cannot see it.
        $tool = $this->write('lib/Tool.php', '<?php class Tool {}');
        $session = $this->session($this->resolver([$tool => [['class', 'Tool']]]));

        [$route, $reason] = $session->route($tool, false);
        self::assertSame(WarmSession::DECLINE, $route);
        self::assertStringContainsString('Tool', $reason);
    }

    public function testAFileUnderAnAutoloadPathThatResolvesToItselfIsServed(): void
    {
        $child = $this->write('src/Child.php', '<?php class Child {}');
        $session = $this->session(
            $this->resolver([$child => [['class', 'Child']]], [], ['Child' => $child]),
            null,
            [$this->dir . '/src'],
        );

        self::assertSame(WarmSession::SERVE, $session->route($child, false)[0]);
    }

    public function testAFileUnderAnAutoloadPathWhoseClassResolvesToAnotherFileIsDeclined(): void
    {
        $child = $this->write('src/Child.php', '<?php class Child {}');
        $twin = $this->write('src/Twin.php', '<?php class Child {}');
        $session = $this->session(
            $this->resolver([$child => [['class', 'Child']]], [], ['Child' => $twin]),
            null,
            [$this->dir . '/src'],
        );

        self::assertSame(WarmSession::DECLINE, $session->route($child, false)[0]);
    }

    public function testABufferCopyWhoseClassesTheStandardChainFindsIsForkedFromTheSession(): void
    {
        $original = $this->write('src/Child.php', '<?php namespace App; class Child {}');
        $copy = $this->write('src/Copy.php', '<?php namespace App; class Child { function x() {} }');
        $session = $this->session($this->resolver([$copy => [['class', 'App\\Child']]], ['App\\Child' => $original]));

        self::assertSame(WarmSession::FORK, $session->route($copy, true)[0]);
    }

    public function testABufferCopyOfAFileOnlyAnAutoloadPathFindsIsDeclined(): void
    {
        // Cold finds the class in the copy itself (the copy is consulted before
        // the autoload paths); the session would answer from the original.
        $original = $this->write('src/Child.php', '<?php class Child {}');
        $copy = $this->write('src/Copy.php', '<?php class Child { function x() {} }');
        $session = $this->session(
            $this->resolver([$copy => [['class', 'Child']]], [], ['Child' => $original]),
            null,
            [$this->dir . '/src'],
        );

        self::assertSame(WarmSession::DECLINE, $session->route($copy, true)[0]);
    }

    public function testRoutingDoesNotTrackTheFileItOnlyInspectedButDoesTrackWhatItResolved(): void
    {
        $original = $this->write('src/Child.php', '<?php namespace App; class Child {}');
        $copy = $this->write('src/Copy.php', '<?php namespace App; class Child {}');
        $resolver = $this->resolver([$copy => [['class', 'App\\Child']]], ['App\\Child' => $original]);
        $session = $this->session($resolver);

        $session->route($copy, true);

        // Must not fire: a buffer copy is rewritten or deleted after every run.
        \file_put_contents($copy, '<?php namespace App; class Child { const Y = 2; }');
        self::assertNull($session->staleReason());
        // Must fire: what the standard chain parsed is part of the session's state.
        \file_put_contents($original, '<?php namespace App; class Child { const Z = 3; }');
        self::assertStringContainsString($original, (string) $session->staleReason());
    }
}

/**
 * Stands in for SessionHooks: answers from fixed maps, and reports a read of
 * each file it "parses" the way the real TrackingParser hook does.
 */
final class FakeSymbolResolver implements SymbolResolver
{
    public ?WarmSession $session = null;

    /**
     * @param array<string, list<array{0: string, 1: string}>> $declared
     * @param array<string, ?string> $standard
     * @param array<string, ?string> $autoloadPaths
     */
    public function __construct(
        private readonly array $declared,
        private readonly array $standard,
        private readonly array $autoloadPaths,
    ) {}

    public function declaredSymbols(string $path): array
    {
        $this->session?->recordRead($path);

        return $this->declared[$path] ?? [];
    }

    public function resolveStandard(string $kind, string $name): ?string
    {
        $file = $this->standard[$name] ?? null;
        if ($file !== null && $file !== '') {
            $this->session?->recordRead($file);
        }

        return $file;
    }

    public function resolveAutoloadPaths(string $kind, string $name): ?string
    {
        $file = $this->autoloadPaths[$name] ?? null;
        if ($file !== null) {
            $this->session?->recordRead($file);
        }

        return $file;
    }
}
