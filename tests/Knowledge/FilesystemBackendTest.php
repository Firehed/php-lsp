<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Index\ComposerNamespaceSource;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Knowledge\FilesystemBackend;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassInfoFactory;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
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
            self::createStub(ClassLocator::class),
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
            new ComposerClassLocator($map),
            new ComposerNamespaceSource($map),
            $this->parser,
            $this->factory,
            CacheFactory::inMemory(),
        );
    }

    private function backendWithLocator(ClassLocator $locator): FilesystemBackend
    {
        return new FilesystemBackend(
            $locator,
            self::createStub(NamespaceCatalog::class),
            $this->parser,
            $this->factory,
            CacheFactory::inMemory(),
        );
    }

    private function locatorReturning(string $path): ClassLocator
    {
        $locator = self::createStub(ClassLocator::class);
        $locator->method('locate')->willReturn($path);

        return $locator;
    }

    private static function className(string $fqn): ClassName
    {
        /** @phpstan-ignore argument.type (fixture and virtual names are not analyzed) */
        return new ClassName($fqn);
    }
}
