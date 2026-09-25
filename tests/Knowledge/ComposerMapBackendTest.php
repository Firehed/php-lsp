<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Knowledge\ComposerMapBackend;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\TestCase;

/**
 * The Composer-map backend resolves class-likes by locating and parsing one
 * file through Composer's PSR-4, PSR-0 and classmap entries, and enumerates
 * namespaces through the same maps. These prove lookup, the not-found paths,
 * the empty prefix search, and directory-listing enumeration through every
 * autoload strategy. Names declared in `autoload.files` entries are the
 * separate {@see \Firehed\PhpLsp\Knowledge\AutoloadFilesBackend}'s concern.
 */
final class ComposerMapBackendTest extends TestCase
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
        $backend = $this->backendForMap(new ComposerAutoloadMap(
            classMap: ['Ghost' => '/no/such/file/Ghost.php'],
        ));

        self::assertNull(
            self::classLikeIn($backend, 'Ghost'),
            'a located path that is not readable degrades to not-found rather than an error',
        );
    }

    public function testLookupClassLikeReturnsNullWhenTheFileDoesNotDeclareTheClass(): void
    {
        // The located file declares a different named class and, nested in a method, an
        // anonymous class: the AST scan skips the unnamed declaration and finds no match.
        $file = $this->fixturesRoot . '/src/TypeInference/AnonymousClass.php';
        $backend = $this->backendForMap(new ComposerAutoloadMap(
            classMap: ['Fixtures\TypeInference\NotDeclaredHere' => $file],
        ));

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
        $backend = $this->backendForMap(new ComposerAutoloadMap(
            classMap: [
                'Fixtures\Completion\ConditionalInMultiFile' => $this->fixturesRoot . '/MultiClass/MultiClass.php',
            ],
        ));

        self::assertNotNull(
            self::classLikeIn($backend, 'Fixtures\Completion\ConditionalInMultiFile'),
            'a conditionally declared class must resolve like any other declaration',
        );
    }

    public function testLookupClassLikeIsCaseInsensitive(): void
    {
        // The classmap entry uses the requested casing (Composer looks up its
        // classmap as a literal string), and the file declares the class with
        // canonical casing. The scanner must match the two case-insensitively,
        // as PHP does for every class-like at runtime.
        $backend = $this->backendForMap(new ComposerAutoloadMap(
            classMap: ['fixtures\domain\user' => $this->fixturesRoot . '/src/Domain/User.php'],
        ));

        self::assertNotNull(
            self::classLikeIn($backend, 'fixtures\domain\user'),
            'PHP matches class names case-insensitively',
        );
    }

    public function testLookupHasNoReachForFunctions(): void
    {
        // Composer's PSR-4, PSR-0 and classmap entries all address class-likes, so a
        // function in an unopened PSR-4 file has no name -> file route at all.
        self::assertNull(
            self::functionIn($this->backend(), 'Fixtures\Completion\calculateSum'),
            'no autoload map addresses a function by name outside the files set',
        );
    }

    public function testLookupHasNoReachForConstants(): void
    {
        self::assertNull(
            $this->backend()->lookupConstant(ConstantName::fromFullyQualified('Fixtures\SOME_CONSTANT')),
            'no autoload map addresses a constant by name outside the files set',
        );
    }

    public function testLookupDoesNotRegisterAdditionalAutoloaders(): void
    {
        $before = spl_autoload_functions();

        self::classLikeIn($this->backend(), 'Fixtures\Domain\User');

        self::assertSame(
            count($before),
            count(spl_autoload_functions()),
            'the backend must not leave its Composer loader registered globally',
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

    public function testChildrenOfEnumeratesPsr4ContentsFromTheDirectoryListing(): void
    {
        $contents = $this->backend()->childrenOf(new NamespaceName('Fixtures\Domain'));

        self::assertContains(
            'Fixtures\Domain\User',
            self::fqns($contents),
            'a class declared under a PSR-4 prefix must be enumerated from the directory listing',
        );
    }

    public function testChildrenOfListsPsr4PrefixesAsChildrenOfTheGlobalNamespace(): void
    {
        $contents = $this->backend()->childrenOf(new NamespaceName(''));

        self::assertContains(
            'Fixtures',
            $contents->childNamespaces,
            'a PSR-4 prefix is a child of the global namespace',
        );
        self::assertContains(
            'Psr',
            $contents->childNamespaces,
            "a vendor package's prefix is discoverable without indexing vendor/",
        );
    }

    public function testChildrenOfIntermediateNamespacesComeFromThePrefixItself(): void
    {
        $contents = $this->backend()->childrenOf(new NamespaceName('Psr'));

        self::assertSame(
            ['Psr\Http'],
            $contents->childNamespaces,
            'the intermediate segments of a PSR-4 prefix are known without touching the disk',
        );
        self::assertSame([], $contents->symbols, 'no files map to this namespace');
    }

    public function testChildrenOfEnumeratesVendorSymbolsFromTheDirectory(): void
    {
        $contents = $this->backend()->childrenOf(new NamespaceName('Psr\Http\Message'));

        self::assertContains(
            'Psr\Http\Message\RequestInterface',
            self::fqns($contents),
            'a PSR-4 namespace maps to a directory, so its contents are a directory listing',
        );
        self::assertContains(
            'Psr\Http\Message\ServerRequestInterface',
            self::fqns($contents),
            'all files in the directory are symbols of that namespace',
        );
    }

    public function testChildrenOfSymbolsFromADirectoryAreClassLikes(): void
    {
        $contents = $this->backend()->childrenOf(new NamespaceName('Psr\Http\Message'));

        foreach ($contents->symbols as $symbol) {
            self::assertSame(
                NameKind::ClassLike,
                $symbol->kind,
                'a file in an autoloaded directory declares a class-like; which one it is takes parsing',
            );
        }
    }

    public function testChildrenOfSubdirectoriesBecomeChildNamespaces(): void
    {
        $contents = $this->backend()->childrenOf(new NamespaceName('Fixtures'));

        self::assertContains(
            'Fixtures\Domain',
            $contents->childNamespaces,
            'a subdirectory of a PSR-4 root is a child namespace',
        );
    }

    public function testChildrenOfEnumeratesPsr0Contents(): void
    {
        $contents = $this->backend()->childrenOf(new NamespaceName('Psr0'));

        self::assertContains(
            'Psr0\Psr0Fixture',
            self::fqns($contents),
            'PSR-0 nests the whole namespace under the base directory, unlike PSR-4',
        );
    }

    public function testChildrenOfEnumeratesClassmapEntries(): void
    {
        $contents = $this->backend()->childrenOf(new NamespaceName('Firehed\PhpLsp\Tests\Fixtures\Autoload'));

        self::assertContains(
            'Firehed\PhpLsp\Tests\Fixtures\Autoload\ClassmapFixture',
            self::fqns($contents),
            'a classmapped class is discoverable even though no prefix maps its namespace',
        );
    }

    public function testChildrenOfListsClassmapEntriesInTheGlobalNamespace(): void
    {
        $contents = $this->backend()->childrenOf(new NamespaceName(''));

        self::assertContains(
            'GlobalConfig',
            self::fqns($contents),
            'a classmapped class with no namespace belongs to the global namespace',
        );
    }

    public function testChildrenOfMatchingIsCaseInsensitive(): void
    {
        $contents = $this->backend()->childrenOf(new NamespaceName('psr\http\message'));

        self::assertContains(
            'Psr\Http\Message\RequestInterface',
            self::fqns($contents),
            'namespaces are case-insensitive in PHP',
        );
    }

    public function testChildrenOfUnknownNamespaceIsEmpty(): void
    {
        $contents = $this->backend()->childrenOf(new NamespaceName('No\Such\Namespace'));

        self::assertSame([], $contents->childNamespaces, 'an unknown namespace has no children');
        self::assertSame([], $contents->symbols, 'an unknown namespace has no symbols');
    }

    public function testChildrenOfRootNamespacePrefixEnumeratesFromItsDirectory(): void
    {
        $backend = $this->backendForMap(new ComposerAutoloadMap(
            psr4: ['' => [$this->fixturesRoot . '/src']],
        ));

        self::assertContains(
            'Domain',
            $backend->childrenOf(new NamespaceName(''))->childNamespaces,
            'a root-namespace ("": [dir]) mapping lists its directory as children of the global namespace',
        );
    }

    public function testChildrenOfWithoutComposerYieldsNothing(): void
    {
        $backend = $this->backendForMap(ComposerAutoloadMap::fromProjectRoot('/nonexistent'));

        $contents = $backend->childrenOf(new NamespaceName(''));

        self::assertSame([], $contents->childNamespaces, 'a project with no vendor/ still works');
        self::assertSame([], $contents->symbols, 'a project with no vendor/ still works');
    }

    public function testChildrenOfPrefixWhoseDirectoryIsMissingYieldsNothing(): void
    {
        $backend = $this->backendForMap(new ComposerAutoloadMap(
            psr4: ['Stale\\' => ['/nonexistent/src']],
        ));

        self::assertSame(
            [],
            $backend->childrenOf(new NamespaceName('Stale'))->symbols,
            'an autoload map can outlive the directory it points at; that is not a crash',
        );
        self::assertSame(
            [],
            $backend->childrenOf(new NamespaceName('Stale\Sub'))->symbols,
            'nor when the walk into the missing directory has segments left to match',
        );
    }

    public function testChildrenOfNamespaceWithNoDirectoryUnderItsPrefixYieldsNothing(): void
    {
        $contents = $this->backend()->childrenOf(new NamespaceName('Fixtures\NotADirectory'));

        self::assertSame(
            [],
            $contents->symbols,
            'the prefix matches but nothing on disk does',
        );
        self::assertSame([], $contents->childNamespaces, 'the prefix matches but nothing on disk does');
    }

    public function testChildrenOfSkipsNonPhpFiles(): void
    {
        // The fixtures root holds composer.json and a vendor/ directory beside
        // its PHP, so pointing a prefix at it exercises both skips.
        $backend = $this->backendForMap(new ComposerAutoloadMap(
            psr4: ['Root\\' => [$this->fixturesRoot]],
        ));

        $contents = $backend->childrenOf(new NamespaceName('Root'));

        self::assertNotContains(
            'Root\composer',
            self::fqns($contents),
            'composer.json is not a class-like; only .php files are',
        );
        self::assertContains(
            'Root\NoNamespace',
            self::fqns($contents),
            'the .php files beside it still are',
        );
        self::assertContains(
            'Root\src',
            $contents->childNamespaces,
            'directories are child namespaces',
        );
    }

    public function testChildrenOfMergesMultipleDirectoriesForOnePrefix(): void
    {
        $base = sys_get_temp_dir() . '/php-lsp-multi-dir-' . getmypid();
        @mkdir($base . '/src', recursive: true);
        @mkdir($base . '/tests', recursive: true);
        file_put_contents($base . '/src/Foo.php', "<?php\n");
        file_put_contents($base . '/tests/Bar.php', "<?php\n");

        try {
            $backend = $this->backendForMap(new ComposerAutoloadMap(
                psr4: ['App\\' => [$base . '/src', $base . '/tests']],
            ));

            $fqns = self::fqns($backend->childrenOf(new NamespaceName('App')));

            self::assertContains('App\Foo', $fqns, 'symbol from first directory is listed');
            self::assertContains('App\Bar', $fqns, 'symbol from second directory is listed');
        } finally {
            @unlink($base . '/src/Foo.php');
            @unlink($base . '/tests/Bar.php');
            @rmdir($base . '/src');
            @rmdir($base . '/tests');
            @rmdir($base);
        }
    }

    /**
     * Wired against the fixtures project so lookups run through the same
     * Composer maps every fixture-based test uses.
     */
    private function backend(): ComposerMapBackend
    {
        return $this->backendForMap(ComposerAutoloadMap::fromProjectRoot($this->fixturesRoot));
    }

    private function backendForMap(ComposerAutoloadMap $map): ComposerMapBackend
    {
        return new ComposerMapBackend(
            $map,
            $this->parser,
            $this->reader,
            $this->infoFactory,
            new DeclarationScanner(),
        );
    }

    /**
     * @return list<string>
     */
    private static function fqns(NamespaceContents $contents): array
    {
        return array_map(
            static fn(CatalogSymbol $symbol): string => $symbol->fullyQualifiedName,
            $contents->symbols,
        );
    }
}
