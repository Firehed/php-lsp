<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\SymbolKind;
use Firehed\PhpLsp\Index\AutoloadFilesLocator;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\ComposerNamespaceSource;
use Firehed\PhpLsp\Index\ComposerSymbolLocator;
use Firehed\PhpLsp\Index\NamespaceCatalogInterface;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\PrefixSearchableInterface;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Knowledge\CompositeSymbolLocator;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\FilesystemBackend;
use Firehed\PhpLsp\Knowledge\SymbolLocatorInterface;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\TestCase;

/**
 * The filesystem backend resolves symbols by locating and parsing one file, and
 * enumerates namespaces through the autoload map. These prove lookup, its caching,
 * the not-found paths, the empty prefix search, and that enumeration forwards to
 * the injected catalog.
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

    public function testSearchClassLikeIsEmpty(): void
    {
        self::assertSame(
            [],
            $this->backend()->search('User', NameKind::ClassLike),
            'project-wide prefix search over disk is the deferred workspace-index scope (RFC 1 §3)',
        );
    }

    public function testSearchFindsFunctionsFromAutoloadFiles(): void
    {
        $results = $this->backend()->search('helperF', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'Fixtures\Helpers\helperFormat',
            $fqns,
            'a function declared in the autoload.files set must be found by prefix search',
        );
    }

    public function testSearchFindsConstantsFromAutoloadFiles(): void
    {
        $results = $this->backend()->search('HELPER_L', NameKind::Constant);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'Fixtures\Helpers\HELPER_LIMIT',
            $fqns,
            'a constant declared in the autoload.files set must be found by prefix search',
        );
    }

    public function testSearchReturnsCorrectSymbolKindForFunctions(): void
    {
        $results = $this->backend()->search('helperF', NameKind::Function_);

        self::assertNotEmpty($results, 'the prefix must match at least one function');
        foreach ($results as $symbol) {
            self::assertSame(
                SymbolKind::Function_,
                $symbol->kind,
                'every symbol returned for a Function_ search must carry SymbolKind::Function_',
            );
        }
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
            self::createStub(PrefixSearchableInterface::class),
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
        $autoloadFiles = new AutoloadFilesLocator($map, $this->parser, $this->reader, new DeclarationScanner());

        return new FilesystemBackend(
            new CompositeSymbolLocator([
                new ComposerSymbolLocator($map),
                $autoloadFiles,
            ]),
            new ComposerNamespaceSource($map),
            $this->parser,
            $this->reader,
            $this->infoFactory,
            new DeclarationScanner(),
            $autoloadFiles,
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
            self::createStub(PrefixSearchableInterface::class),
        );
    }

    private function locatorReturning(string $path): SymbolLocatorInterface
    {
        $locator = self::createStub(SymbolLocatorInterface::class);
        $locator->method('locate')->willReturn($path);

        return $locator;
    }
}
