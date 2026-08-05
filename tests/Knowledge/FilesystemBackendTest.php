<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Document\FileUri;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\AutoloadFilesLocator;
use Firehed\PhpLsp\Index\CachedNamespaceCatalog;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\ComposerNamespaceSource;
use Firehed\PhpLsp\Index\ComposerSymbolLocator;
use Firehed\PhpLsp\Index\DeclarationScanner;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Knowledge\CompositeSymbolLocator;
use Firehed\PhpLsp\Knowledge\FilesystemBackend;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Knowledge\SymbolLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Tests\Index\CountingNamespaceCatalog;
use Psr\SimpleCache\CacheInterface;
use PHPUnit\Framework\TestCase;

/**
 * The filesystem backend resolves class-likes by locating and parsing one file, and
 * enumerates namespaces through the autoload map — the workspace and vendor roles
 * both run this code, differing only in the map subset they are given. These prove
 * lookup, its caching, the not-found paths, the empty prefix search, and that
 * enumeration forwards to the injected catalog.
 */
final class FilesystemBackendTest extends TestCase
{
    private string $fixturesRoot;
    private ParserService $parser;
    private ClassInfoFactory $factory;

    protected function setUp(): void
    {
        $this->fixturesRoot = dirname(__DIR__, 2) . '/tests/Fixtures';
        $this->parser = new ParserService();
        $this->factory = new DefaultClassInfoFactory();
    }

    public function testLookupClassLikeResolvesAndParsesAFixtureClass(): void
    {
        $info = $this->backend()->lookupClassLike(self::className('Fixtures\Domain\User'));

        self::assertNotNull($info, 'a class reachable through the autoload map must resolve');
        self::assertSame('Fixtures\Domain\User', $info->name->fqn, 'the located class must be returned');
    }

    public function testLookupClassLikeReturnsNullForAnAbsentClass(): void
    {
        self::assertNull(
            $this->backend()->lookupClassLike(self::className('Fixtures\Does\Not\Exist')),
            'a name the autoload map cannot locate is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testLookupClassLikeCachesAResolvedClass(): void
    {
        $backend = $this->backend();
        $name = self::className('Fixtures\Domain\User');

        $first = $backend->lookupClassLike($name);
        $second = $backend->lookupClassLike($name);

        self::assertNotNull($first, 'the first lookup must resolve so the cache is populated');
        self::assertSame($first, $second, 'a second lookup must return the cached instance, not re-parse');
    }

    public function testLookupClassLikeReturnsNullWhenTheLocatedFileIsUnreadable(): void
    {
        $backend = $this->backendWithLocator($this->locatorReturning('/no/such/file/Ghost.php'));

        self::assertNull(
            $backend->lookupClassLike(self::className('Ghost')),
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
            $backend->lookupClassLike(self::className('Fixtures\TypeInference\NotDeclaredHere')),
            'a located file that does not declare the requested class resolves to null',
        );
    }

    public function testInvalidateEvictsTheCachedClassSoTheNextLookupReParses(): void
    {
        $backend = $this->backend();
        $name = self::className('Fixtures\Domain\User');

        $first = $backend->lookupClassLike($name);
        self::assertNotNull($first, 'the first lookup must resolve so the cache is populated');

        $backend->invalidate('file://' . $this->fixturesRoot . '/src/Domain/User.php');
        $second = $backend->lookupClassLike($name);

        self::assertNotNull($second, 'the class must resolve again after invalidation');
        self::assertNotSame(
            $first,
            $second,
            'invalidate must evict the cached class so the changed file is re-parsed from disk (RFC 1 §5.3)',
        );
    }

    public function testInvalidateAlsoDropsCachedNamespaceListings(): void
    {
        $counting = new CountingNamespaceCatalog();
        $backend = new FilesystemBackend(
            self::createStub(SymbolLocator::class),
            new CachedNamespaceCatalog($counting, CacheFactory::inMemory()),
            $this->parser,
            $this->factory,
            CacheFactory::inMemory(),
        );

        $backend->childrenOf(new NamespaceName('Psr\Log'));
        $backend->invalidate('file:///any/changed/File.php');
        $backend->childrenOf(new NamespaceName('Psr\Log'));

        self::assertSame(
            2,
            $counting->calls,
            'invalidate must drop cached namespace listings so a create or delete is reflected (RFC 1 §5.3)',
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
            $name = self::className('Spaced');

            $first = $backend->lookupClassLike($name);
            self::assertNotNull($first, 'the first lookup must resolve so the cache is populated');

            $backend->invalidate('file://' . str_replace(' ', '%20', $path));
            $second = $backend->lookupClassLike($name);

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

    /**
     * Driven end to end through the locator chain the stack actually wires, rather
     * than a mock: the autoload.files index is derived from disk, so an external
     * change must reach it too. Evicting only the ClassInfo cache would leave the
     * name -> file map itself stale, and a class added by the edit would stay
     * invisible however many times it was asked for (RFC 1 §5.2, §5.3).
     */
    public function testInvalidateReachesALocatorHoldingDerivedState(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'php-lsp-fsb-files-');
        self::assertNotFalse($path, 'a temp file must be creatable');

        try {
            self::assertNotFalse(
                file_put_contents($path, '<?php class DerivedBefore {}'),
                'the temp file must be writable',
            );

            $backend = $this->backendWithLocator(new CompositeSymbolLocator([
                new AutoloadFilesLocator(
                    new ComposerAutoloadMap([], [], [], [$path]),
                    $this->parser,
                    new DeclarationScanner(),
                ),
            ]));

            self::assertNotNull(
                $backend->lookupClassLike(self::className('DerivedBefore')),
                'a class-like declared in a files entry must resolve through the derived index',
            );

            self::assertNotFalse(
                file_put_contents($path, '<?php class DerivedAfter {}'),
                'the rewrite must succeed',
            );
            $backend->invalidate(FileUri::fromPath($path));

            self::assertNotNull(
                $backend->lookupClassLike(self::className('DerivedAfter')),
                'invalidate must re-derive the index so a class added on disk resolves',
            );
        } finally {
            unlink($path);
        }
    }

    public function testInvalidateAnUncachedFileIsHarmless(): void
    {
        $backend = $this->backend();

        $backend->invalidate('file:///never/looked/up.php');

        self::assertNotNull(
            $backend->lookupClassLike(self::className('Fixtures\Domain\User')),
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
            $backend->lookupClassLike(self::className('Fixtures\Domain\User')),
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
            $this->factory,
            CacheFactory::inMemory(),
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

    private function backend(): FilesystemBackend
    {
        $map = ComposerAutoloadMap::fromProjectRoot($this->fixturesRoot);

        return new FilesystemBackend(
            new ComposerSymbolLocator($map),
            new ComposerNamespaceSource($map),
            $this->parser,
            $this->factory,
            CacheFactory::inMemory(),
        );
    }

    private function backendWithLocator(SymbolLocator $locator): FilesystemBackend
    {
        return new FilesystemBackend(
            $locator,
            self::createStub(NamespaceCatalog::class),
            $this->parser,
            $this->factory,
            CacheFactory::inMemory(),
        );
    }

    private function locatorReturning(string $path): SymbolLocator
    {
        $locator = self::createStub(SymbolLocator::class);
        $locator->method('locate')->willReturn($path);

        return $locator;
    }

    private static function className(string $fqn): ClassName
    {
        /** @phpstan-ignore argument.type (fixture and virtual names are not analyzed) */
        return new ClassName($fqn);
    }
}
