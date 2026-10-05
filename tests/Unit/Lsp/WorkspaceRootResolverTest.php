<?php

declare(strict_types=1);

namespace Dpt\McpRectorWarm\Tests\Unit\Lsp;

use Dpt\McpRectorWarm\Lsp\WorkspaceRootResolver;
use PHPUnit\Framework\TestCase;

/**
 * #107: pure logic, no process-wide chdir involved -- the thing
 * RootedDiagnosticsSourcePool and MultiRootDiagnosticsSource both lean on
 * to decide which of the server's known workspace roots a document
 * belongs to.
 */
final class WorkspaceRootResolverTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        // CI finding (Windows legs, php-cs-fixer-fixed PR #293): sys_get_temp_dir()
        // returns a backslash-separated path on Windows (e.g.
        // 'C:\Users\RUNNER~1\AppData\Local\Temp'), while every path this class
        // builds from here on uses '/' -- and WorkspaceRootResolver::resolve()
        // always normalizes its OWN return value to forward slashes (see its own
        // normalize()). Normalizing the base here too keeps every expected value
        // in this file in the same form resolve() actually returns, instead of a
        // mixed backslash-then-forward-slash string resolve() never produces.
        $this->base = str_replace('\\', '/', sys_get_temp_dir()) . '/mcp-rector-root-resolver-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0o700, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->base);
    }

    public function testFindsRectorPhpAtTheFolderItself(): void
    {
        $folder = $this->base . '/one';
        mkdir($folder . '/src', 0o700, true);
        touch($folder . '/rector.php');
        $file = $folder . '/src/Sample.php';
        touch($file);

        self::assertSame($folder, WorkspaceRootResolver::resolve($file, [$folder], $this->base));
    }

    public function testFindsComposerJsonWhenNoRectorPhpExists(): void
    {
        $folder = $this->base . '/one';
        mkdir($folder . '/src', 0o700, true);
        touch($folder . '/composer.json');
        $file = $folder . '/src/Sample.php';
        touch($file);

        self::assertSame($folder, WorkspaceRootResolver::resolve($file, [$folder], $this->base));
    }

    public function testFindsANestedRectorPhpCloserThanTheFolderRoot(): void
    {
        $folder = $this->base . '/one';
        mkdir($folder . '/packages/lib/src', 0o700, true);
        touch($folder . '/rector.php');
        touch($folder . '/packages/lib/rector.php');
        $file = $folder . '/packages/lib/src/Sample.php';
        touch($file);

        self::assertSame($folder . '/packages/lib', WorkspaceRootResolver::resolve($file, [$folder], $this->base));
    }

    public function testFallsBackToTheOwningFolderWhenNoMarkerExistsAnywhereUnderIt(): void
    {
        $folder = $this->base . '/one';
        mkdir($folder . '/src', 0o700, true);
        $file = $folder . '/src/Sample.php';
        touch($file);

        self::assertSame($folder, WorkspaceRootResolver::resolve($file, [$folder], $this->base));
    }

    public function testPicksTheLongestMatchingFolderAmongNestedOnes(): void
    {
        $outer = $this->base . '/outer';
        $inner = $this->base . '/outer/inner';
        mkdir($inner . '/src', 0o700, true);
        touch($inner . '/rector.php');
        $file = $inner . '/src/Sample.php';
        touch($file);

        // Both $outer and $inner are "known folders" that contain $file --
        // the longer (more specific) one must win, otherwise a document
        // inside a nested project resolves to the wrong rector.php.
        self::assertSame($inner, WorkspaceRootResolver::resolve($file, [$outer, $inner], $this->base));
    }

    public function testFallsBackToTheGivenFallbackWhenNoKnownFolderOwnsTheDocument(): void
    {
        $elsewhere = $this->base . '/elsewhere/src';
        mkdir($elsewhere, 0o700, true);
        $file = $elsewhere . '/Sample.php';
        touch($file);

        $unrelatedFolder = $this->base . '/unrelated';
        mkdir($unrelatedFolder, 0o700, true);

        self::assertSame($this->base, WorkspaceRootResolver::resolve($file, [$unrelatedFolder], $this->base));
    }

    public function testIgnoresAMarkerInAnUnrelatedDirectoryOutsideEveryWorkspaceFolder(): void
    {
        // #296: a document outside every known workspace folder must not
        // resolve to its own containing directory just because that
        // directory happens to hold a rector.php or composer.json -- that
        // directory was never authorised by any workspace folder or the
        // fallback, and RootedDiagnosticsSourcePool chdir()s into whatever
        // this returns. Before #293 the pre-existing containment guards
        // refused this outright; this pins resolve() itself refusing it
        // too, rather than relying on a cwd-dependent check that by then
        // has already been fooled by the chdir.
        $elsewhere = $this->base . '/elsewhere/proj';
        mkdir($elsewhere, 0o700, true);
        touch($elsewhere . '/rector.php');
        $file = $elsewhere . '/x.php';
        touch($file);

        $workspace = $this->base . '/ws';
        mkdir($workspace, 0o700, true);

        self::assertSame($workspace, WorkspaceRootResolver::resolve($file, [$workspace], $workspace));
    }

    public function testIgnoresAMarkerReachedThroughADotDotSegmentThatTextuallyStartsWithTheWorkspacePrefix(): void
    {
        // #298: isDescendant() used to be a plain string-prefix test, so a
        // document path containing ".." that textually starts with the
        // workspace's own prefix (e.g. "$workspace/../elsewhere/...") was
        // treated as "inside" the workspace even though it lexically
        // escapes it -- the same containment bypass #296 fixed, reached
        // through a different path spelling. Pairs with
        // testIgnoresAMarkerInAnUnrelatedDirectoryOutsideEveryWorkspaceFolder()
        // above, which covers the plain (no "..") escape.
        $workspace = $this->base . '/ws';
        mkdir($workspace, 0o700, true);

        $elsewhere = $this->base . '/elsewhere/proj';
        mkdir($elsewhere, 0o700, true);
        touch($elsewhere . '/rector.php');
        $file = $workspace . '/../elsewhere/proj/x.php';
        touch($file);

        self::assertSame($workspace, WorkspaceRootResolver::resolve($file, [$workspace], $workspace));
    }

    public function testIgnoresAMarkerReachedThroughASymlinkThatLexicallySitsInsideTheWorkspace(): void
    {
        // #298: a symlink inside the workspace whose real target is
        // outside it is the same containment bypass as the ".." case
        // above, reached a different way -- the literal path is nominally
        // under the workspace, but following it lands somewhere a
        // workspace folder never authorised.
        $workspace = $this->base . '/ws';
        mkdir($workspace, 0o700, true);

        $outside = $this->base . '/outside';
        mkdir($outside, 0o700, true);
        touch($outside . '/rector.php');

        if (!@symlink($outside, $workspace . '/link')) {
            self::markTestSkipped('symlink() is not available in this test environment');
        }

        $file = $workspace . '/link/x.php';
        touch($file);

        self::assertSame($workspace, WorkspaceRootResolver::resolve($file, [$workspace], $workspace));
    }

    public function testFindsANearerMarkerUnderANotYetCreatedDocumentDirectory(): void
    {
        // #298 follow-up (review finding): canonicalize() used to fall
        // straight to a purely lexical collapse the moment realpath()
        // failed on the FULL path, which mixed two different
        // canonicalisation strategies across the two sides of
        // isDescendant()'s comparison whenever only one side existed on
        // disk yet. A document's own directory not existing yet (an
        // unsaved/new file) is ordinary, not adversarial -- it must not,
        // by itself, make resolve() silently settle for a shallower root
        // than the one that genuinely owns the document, purely because
        // $this->base sits under a path the OS may resolve through a
        // symlink (e.g. macOS's /tmp -> /private/tmp) while the
        // not-yet-created directory does not resolve at all.
        $workspace = $this->base . '/ws';
        mkdir($workspace, 0700, true);
        touch($workspace . '/composer.json');

        $pkg = $workspace . '/pkg';
        mkdir($pkg, 0700, true);
        touch($pkg . '/rector.php');

        $file = $pkg . '/not_yet_created_subdir/File.php';

        self::assertSame($pkg, WorkspaceRootResolver::resolve($file, [$workspace], $workspace));
    }

    public function testNeverWalksAboveTheOwningFolderEvenWhenAnAncestorHasAMarker(): void
    {
        // A rector.php sitting ABOVE the workspace folder (e.g. a monorepo
        // root the editor never opened) must never leak in -- otherwise
        // two different folders of the same monorepo could resolve to the
        // same root, defeating the whole point of #107.
        touch($this->base . '/rector.php');
        $folder = $this->base . '/one';
        mkdir($folder . '/src', 0o700, true);
        $file = $folder . '/src/Sample.php';
        touch($file);

        self::assertSame($folder, WorkspaceRootResolver::resolve($file, [$folder], $this->base));
    }

    /**
     * #107 self-review (oss:auditor flagged dirname()'s undocumented
     * behaviour on a bare Windows drive letter as a possible walk-up
     * hazard; a second review pass proved, and this pins, that the
     * owner-equality check always intercepts a drive root first -- see
     * resolve()'s own comment on that check for the construction argument.
     * An earlier version of this test asserted the SAME resolve() call
     * against a version of the code carrying an extra explicit guard for
     * that case; removed once the guard was shown to be unreachable dead
     * code (this exact assertion passed identically with and without it,
     * which is what "vacuous" means here) -- this is the one test that
     * matters: that the drive root resolves correctly at all, via the path
     * the code actually takes.
     */
    public function testResolvesCleanlyAtAWindowsDriveRootWithNoMarkerAnywhereUnderIt(): void
    {
        self::assertSame('C:', WorkspaceRootResolver::resolve('C:/Project/src/Sample.php', [], 'C:'));
    }

    public function testCollapsesDotDotPastAWindowsDriveRootWithoutDroppingTheDrive(): void
    {
        // #298 follow-up (review finding): collapseDotSegments() used to
        // decide "is this path absolute" solely by a leading "/", so a
        // Windows-style drive-letter path ("C:/...") was treated as
        // RELATIVE -- a leading ".." could then pop the drive letter
        // itself off the stack like any ordinary segment, and once the
        // stack went empty the next ".." was pushed back on, turning an
        // absolute Windows path into a bogus relative one. The drive
        // letter must be protected the same way a POSIX leading "/" is.
        $method = new \ReflectionMethod(WorkspaceRootResolver::class, 'collapseDotSegments');

        self::assertSame('C:/x', $method->invoke(null, 'C:/../x'));
        self::assertSame('C:/x', $method->invoke(null, 'C:/../../x'));
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
