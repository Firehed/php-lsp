<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\ComposerNamespaceSource;
use Firehed\PhpLsp\Index\ComposerSymbolLocator;
use Firehed\PhpLsp\Index\NamespaceCatalogInterface;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\FilesystemBackend;
use Firehed\PhpLsp\Knowledge\SymbolLocatorInterface;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\TestCase;

/**
 * The filesystem backend resolves class-likes by locating and parsing one file
 * through Composer's PSR-4, PSR-0 and classmap entries, and enumerates
 * namespaces through the autoload map. These prove lookup, the not-found
 * paths, the empty prefix search, and that enumeration forwards to the
 * injected catalog. Names declared in `autoload.files` entries are the
 * separate {@see \Firehed\PhpLsp\Knowledge\AutoloadFilesBackend}'s concern.
 */
final class FilesystemBackendTest extends TestCase
{
    use LooksUpBackendSymbolsTrait;

    private string $fixturesRoot;
    private MemoizingSyntaxSource $parser;
    private SourceFileReader $reader;
    private DeclarationSymbolInfoFactory $infoFactory;

    protected function setUp(): void
    {
        $this->fixturesRoot = dirname(__DIR__, 2) . '/tests/Fixtures';
        $production = ProductionSyntaxSource::create();
        $this->parser = $production->source;
        $this->reader = $production->reader;
        $this->infoFactory = new DeclarationSymbolInfoFactory();
    }

    public function testLookupClassLikeResolvesAndParsesAFixtureClass(): void
    {
        $info = self::classLikeIn($this->backend(), 'Fixtures\Domain\User');

        self::assertNotNull($info, 'a class reachable through the autoload map must resolve');
        self::assertSame(
            'Fixtures\Domain\User',
            $info->name->qualifiedName->fullyQualifiedName(),
            'the located class must be returned',
        );
    }

    public function testLookupClassLikeReturnsNullForAnAbsentClass(): void
    {
        self::assertNull(
            self::classLikeIn($this->backend(), 'Fixtures\Does\Not\Exist'),
            'a name the autoload map cannot locate is absent from this backend (RFC 1 §5.3)',
        );
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

    public function testSearchIsEmpty(): void
    {
        self::assertSame(
            [],
            $this->backend()->search('User', NameKind::ClassLike),
            'project-wide prefix search over disk is the deferred workspace-index scope (RFC 1 §3)',
        );
    }

    public function testChildrenOfForwardsToTheInjectedCatalog(): void
    {
        $expected = new NamespaceContents(['Fixtures\Domain\Sub'], []);
        $catalog = $this->createMock(NamespaceCatalogInterface::class);
        $catalog->expects($this->once())
            ->method('childrenOf')
            ->with('Fixtures\Domain')
            ->willReturn($expected);

        $backend = new FilesystemBackend(
            self::createStub(SymbolLocatorInterface::class),
            $catalog,
            $this->parser,
            $this->reader,
            $this->infoFactory,
            new DeclarationScanner(),
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
     * Wired with the composer locator that answers by name — the autoload maps
     * address class-likes, and nothing else reaches this backend.
     */
    private function backend(): FilesystemBackend
    {
        $map = ComposerAutoloadMap::fromProjectRoot($this->fixturesRoot);

        return new FilesystemBackend(
            new ComposerSymbolLocator($map),
            new ComposerNamespaceSource($map),
            $this->parser,
            $this->reader,
            $this->infoFactory,
            new DeclarationScanner(),
        );
    }

    private function backendWithLocator(SymbolLocatorInterface $locator): FilesystemBackend
    {
        return new FilesystemBackend(
            $locator,
            self::createStub(NamespaceCatalogInterface::class),
            $this->parser,
            $this->reader,
            $this->infoFactory,
            new DeclarationScanner(),
        );
    }

    private function locatorReturning(string $path): SymbolLocatorInterface
    {
        $locator = self::createStub(SymbolLocatorInterface::class);
        $locator->method('locate')->willReturn($path);

        return $locator;
    }
}
