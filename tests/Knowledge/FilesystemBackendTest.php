<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Document\FileUri;
use Firehed\PhpLsp\Index\AutoloadFilesLocator;
use Firehed\PhpLsp\Index\CachedNamespaceCatalog;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\ComposerNamespaceSource;
use Firehed\PhpLsp\Index\ComposerSymbolLocator;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Knowledge\CompositeSymbolLocator;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\FilesystemBackend;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Knowledge\SymbolCache;
use Firehed\PhpLsp\Knowledge\SymbolLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Psr\SimpleCache\CacheInterface;
use PHPUnit\Framework\TestCase;

/**
 * The filesystem backend resolves symbols by locating and parsing one file, and
 * enumerates namespaces through the autoload map — the workspace and vendor roles
 * both run this code, differing only in the map subset they are given. These prove
 * lookup, its caching, the not-found paths, the empty prefix search, and that
 * enumeration forwards to the injected catalog.
 */
final class FilesystemBackendTest extends TestCase
{
    use LooksUpBackendSymbolsTrait;

    private string $fixturesRoot;
    private ParserService $parser;
    private DeclarationSymbolInfoFactory $infoFactory;

    protected function setUp(): void
    {
        $this->fixturesRoot = dirname(__DIR__, 2) . '/tests/Fixtures';
        $this->parser = new ParserService();
        $this->infoFactory = new DeclarationSymbolInfoFactory(new DefaultClassInfoFactory());
    }

    public function testLookupClassLikeResolvesAndParsesAFixtureClass(): void
    {
        $info = self::classLikeIn($this->backend(), 'Fixtures\Domain\User');

        self::assertNotNull($info, 'a class reachable through the autoload map must resolve');
        self::assertSame('Fixtures\Domain\User', $info->name->fqn, 'the located class must be returned');
    }

    public function testLookupClassLikeReturnsNullForAnAbsentClass(): void
    {
        self::assertNull(
            self::classLikeIn($this->backend(), 'Fixtures\Does\Not\Exist'),
            'a name the autoload map cannot locate is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testLookupClassLikeCachesAResolvedClass(): void
    {
        $backend = $this->backend();

        $first = self::classLikeIn($backend, 'Fixtures\Domain\User');
        $second = self::classLikeIn($backend, 'Fixtures\Domain\User');

        self::assertNotNull($first, 'the first lookup must resolve so the cache is populated');
        self::assertSame($first, $second, 'a second lookup must return the cached instance, not re-parse');
    }

    public function testLookupClassLikeReturnsNullWhenTheLocatedFileIsUnreadable(): void
    {
        $backend = $this->backendWithLocator($this->locatorReturning('/no/such/file/Ghost.php'));

        self::assertNull(
            self::classLikeIn($backend, 'Ghost'),
            'a located path that is not readable degrades to not-found rather than an error',
        );
    }

    public function testLookupClassLikeReturnsNullWhenTheFileDoesNotDeclareTheClass(): void
    {
        // The located file declares a different named class and, nested in a method, an
        // anonymous class: the AST scan skips the unnamed declaration and finds no match.
        $backend = $this->backendWithLocator(
            $this->locatorReturning($this->fixturesRoot . '/src/TypeInference/AnonymousClass.php'),
        );

        self::assertNull(
            self::classLikeIn($backend, 'Fixtures\TypeInference\NotDeclaredHere'),
            'a located file that does not declare the requested class resolves to null',
        );
    }

    public function testLookupClassLikeResolvesADeclarationBelowTheTopLevel(): void
    {
        // The class-like half of the same rule the function path follows: a
        // `class_exists`-guarded declaration is a name the file declares, so a scan
        // narrowed to top-level statements would lose it.
        $backend = $this->backendWithLocator(
            $this->locatorReturning($this->fixturesRoot . '/MultiClass/MultiClass.php'),
        );

        self::assertNotNull(
            self::classLikeIn($backend, 'Fixtures\Completion\ConditionalInMultiFile'),
            'a conditionally declared class must resolve like any other declaration',
        );
    }

    public function testLookupClassLikeIsCaseInsensitive(): void
    {
        $backend = $this->backendWithLocator(
            $this->locatorReturning($this->fixturesRoot . '/src/Domain/User.php'),
        );

        self::assertNotNull(
            self::classLikeIn($backend, 'fixtures\domain\user'),
            'PHP matches class names case-insensitively, as the function path already does',
        );
    }

    public function testLookupFunctionResolvesAFunctionDeclaredInAnAutoloadFilesEntry(): void
    {
        $info = self::functionIn($this->backend(), 'Fixtures\Helpers\helperFormat');

        self::assertNotNull($info, 'a function in the files set must resolve through the derived index');
        self::assertCount(1, $info->parameters, 'the parsed signature must be carried');
        self::assertSame(
            $this->fixturesRoot . '/AutoloadFiles/helpers.php',
            $info->file,
            'a function resolved from disk must carry its definition site',
        );
    }

    public function testLookupFunctionResolvesADeclarationBelowTheTopLevel(): void
    {
        // The shape most `autoload.files` entries take: a polyfill declares itself
        // only where the runtime lacks it, so the declaration is nested. A scan
        // narrowed to top-level statements would miss it, and the name would resolve
        // from an open document but not from disk.
        self::assertNotNull(
            self::functionIn($this->backend(), 'fixtureConditionalHelper'),
            'a conditionally declared function must resolve like any other declaration',
        );
    }

    public function testLookupFunctionIsCaseInsensitive(): void
    {
        self::assertNotNull(
            self::functionIn($this->backend(), 'FIXTURES\HELPERS\HELPERFORMAT'),
            'PHP matches function names case-insensitively',
        );
    }

    public function testLookupFunctionReturnsNullForAFunctionOnlyAPsr4FileDeclares(): void
    {
        // Composer's PSR-4, PSR-0 and classmap entries all address class-likes, so a
        // function in an unopened PSR-4 file has no name -> file route at all. That
        // is Plan 0002 §3's locate-only limitation, not a gap in the backend.
        self::assertNull(
            self::functionIn($this->backend(), 'Fixtures\Completion\calculateSum'),
            'no autoload map addresses a function by name outside the files set',
        );
    }

    public function testLookupFunctionReturnsNullForAnAbsentFunction(): void
    {
        self::assertNull(
            self::functionIn($this->backend(), 'Fixtures\no_such_helper'),
            'a name no locator can reach is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testLookupFunctionReturnsNullWhenTheLocatedFileDoesNotDeclareIt(): void
    {
        $backend = $this->backendWithLocator(
            $this->locatorReturning($this->fixturesRoot . '/src/Domain/User.php'),
        );

        self::assertNull(
            self::functionIn($backend, 'notInThisFile'),
            'a located file that does not declare the requested function resolves to null',
        );
    }

    public function testLookupFunctionReturnsNullWhenTheLocatedFileIsUnreadable(): void
    {
        $backend = $this->backendWithLocator($this->locatorReturning('/no/such/file/helpers.php'));

        self::assertNull(
            self::functionIn($backend, 'ghostHelper'),
            'a located path that is not readable degrades to not-found rather than an error',
        );
    }

    public function testLookupFunctionCachesAResolvedFunction(): void
    {
        $backend = $this->backend();

        $first = self::functionIn($backend, 'Fixtures\Helpers\helperFormat');
        $second = self::functionIn($backend, 'Fixtures\Helpers\helperFormat');

        self::assertNotNull($first, 'the first lookup must resolve so the cache is populated');
        self::assertSame($first, $second, 'a second lookup must return the cached instance, not re-parse');
    }

    public function testFunctionAndClassLikeCachesDoNotCollide(): void
    {
        // PHP's symbol namespaces are independent, so one file may declare a class
        // and a function of the same name. A cache keyed on the name alone would
        // serve whichever was resolved first to both queries.
        $path = tempnam(sys_get_temp_dir(), 'php-lsp-fsb-dual-');
        self::assertNotFalse($path, 'a temp file must be creatable');

        try {
            self::assertNotFalse(
                file_put_contents($path, "<?php\nclass Dual {}\nfunction Dual(): void {}\n"),
                'the temp file must be writable',
            );

            $backend = $this->backendWithLocator($this->locatorReturning($path));

            $class = self::classLikeIn($backend, 'Dual');
            $function = self::functionIn($backend, 'Dual');

            self::assertNotNull($class, 'the class-like must resolve');
            self::assertNotNull($function, 'the function must resolve rather than hit the class entry');
        } finally {
            unlink($path);
        }
    }

    public function testInvalidateEvictsTheCachedFunctionSoTheNextLookupReParses(): void
    {
        $backend = $this->backend();

        $first = self::functionIn($backend, 'Fixtures\Helpers\helperFormat');
        self::assertNotNull($first, 'the first lookup must resolve so the cache is populated');

        $backend->invalidate(FileUri::fromPath($this->fixturesRoot . '/AutoloadFiles/helpers.php'));
        $second = self::functionIn($backend, 'Fixtures\Helpers\helperFormat');

        self::assertNotNull($second, 'the function must resolve again after invalidation');
        self::assertNotSame(
            $first,
            $second,
            'invalidate must evict cached functions too, or an edited file is served stale (RFC 1 §5.3)',
        );
    }

    public function testInvalidateEvictsTheCachedClassSoTheNextLookupReParses(): void
    {
        $backend = $this->backend();

        $first = self::classLikeIn($backend, 'Fixtures\Domain\User');
        self::assertNotNull($first, 'the first lookup must resolve so the cache is populated');

        $backend->invalidate('file://' . $this->fixturesRoot . '/src/Domain/User.php');
        $second = self::classLikeIn($backend, 'Fixtures\Domain\User');

        self::assertNotNull($second, 'the class must resolve again after invalidation');
        self::assertNotSame(
            $first,
            $second,
            'invalidate must evict the cached class so the changed file is re-parsed from disk (RFC 1 §5.3)',
        );
    }

    public function testInvalidateDecodesAPercentEncodedUriToMatchTheCachedPath(): void
    {
        // A client URI percent-encodes reserved characters (a space becomes %20),
        // but the locator path {@see $cacheKeysByPath} is keyed by does not. The
        // URI must be decoded before matching, or a workspace path with a space —
        // common on macOS — never evicts and the pre-change class is served stale.
        $dir = sys_get_temp_dir() . '/php-lsp fsb ' . getmypid();
        self::assertTrue(mkdir($dir), 'the temp directory with a space must be created');
        $path = $dir . '/Spaced.php';

        try {
            self::assertNotFalse(
                file_put_contents($path, "<?php\nclass Spaced {}\n"),
                'the spaced-path fixture must be writable',
            );

            $backend = $this->backendWithLocator($this->locatorReturning($path));

            $first = self::classLikeIn($backend, 'Spaced');
            self::assertNotNull($first, 'the first lookup must resolve so the cache is populated');

            $backend->invalidate('file://' . str_replace(' ', '%20', $path));
            $second = self::classLikeIn($backend, 'Spaced');

            self::assertNotNull($second, 'the class must resolve again after invalidation');
            self::assertNotSame(
                $first,
                $second,
                'the percent-encoded URI must be decoded to match the cached path so the entry is evicted',
            );
        } finally {
            unlink($path);
            rmdir($dir);
        }
    }

    public function testInvalidateAnUncachedFileIsHarmless(): void
    {
        $backend = $this->backend();

        $backend->invalidate('file:///never/looked/up.php');

        self::assertNotNull(
            self::classLikeIn($backend, 'Fixtures\Domain\User'),
            'invalidating a file that was never cached must not disturb later lookups',
        );
    }

    public function testInvalidateToleratesANonFileUri(): void
    {
        $backend = $this->backend();

        // An unsaved-buffer or other-scheme URI has no on-disk path to match; it is
        // used verbatim, matches no cached entry, and must not disturb later lookups.
        $backend->invalidate('untitled:Untitled-1');

        self::assertNotNull(
            self::classLikeIn($backend, 'Fixtures\Domain\User'),
            'a non-file:// URI must be handled without error',
        );
    }

    public function testSearchClassLikesIsEmpty(): void
    {
        self::assertSame(
            [],
            $this->backend()->searchClassLikes('User'),
            'project-wide prefix search over disk is the deferred workspace-index scope (RFC 1 §3)',
        );
    }

    public function testChildrenOfForwardsToTheInjectedCatalog(): void
    {
        $expected = new NamespaceContents(['Fixtures\Domain\Sub'], []);
        $catalog = $this->createMock(NamespaceCatalog::class);
        $catalog->expects($this->once())
            ->method('childrenOf')
            ->with('Fixtures\Domain')
            ->willReturn($expected);

        $backend = new FilesystemBackend(
            self::createStub(SymbolLocator::class),
            $catalog,
            $this->parser,
            $this->infoFactory,
            new DeclarationScanner(),
            new SymbolCache(CacheFactory::inMemory()),
        );

        self::assertSame(
            $expected,
            $backend->childrenOf(new NamespaceName('Fixtures\Domain')),
            'enumeration must forward the namespace path to the catalog and return its result',
        );
    }

    public function testChildrenOfEnumeratesRealAutoloadContents(): void
    {
        $contents = $this->backend()->childrenOf(new NamespaceName('Fixtures\Domain'));

        $fqns = array_map(static fn($symbol): string => $symbol->fullyQualifiedName, $contents->symbols);
        self::assertContains(
            'Fixtures\Domain\User',
            $fqns,
            'a class declared under a PSR-4 prefix must be enumerated from the directory listing',
        );
    }

    /**
     * Wired with the same locator chain {@see \Firehed\PhpLsp\Knowledge\KnowledgeStack}
     * gives it: the autoload maps address class-likes by name, and the derived index
     * covers the `files` set, which they address by no name at all.
     */
    private function backend(): FilesystemBackend
    {
        $map = ComposerAutoloadMap::fromProjectRoot($this->fixturesRoot);

        return new FilesystemBackend(
            new CompositeSymbolLocator([
                new ComposerSymbolLocator($map),
                new AutoloadFilesLocator($map, $this->parser, new DeclarationScanner()),
            ]),
            new ComposerNamespaceSource($map),
            $this->parser,
            $this->infoFactory,
            new DeclarationScanner(),
            new SymbolCache(CacheFactory::inMemory()),
        );
    }

    private function backendWithLocator(SymbolLocator $locator): FilesystemBackend
    {
        return new FilesystemBackend(
            $locator,
            self::createStub(NamespaceCatalog::class),
            $this->parser,
            $this->infoFactory,
            new DeclarationScanner(),
            new SymbolCache(CacheFactory::inMemory()),
        );
    }

    private function locatorReturning(string $path): SymbolLocator
    {
        $locator = self::createStub(SymbolLocator::class);
        $locator->method('locate')->willReturn($path);

        return $locator;
    }
}
