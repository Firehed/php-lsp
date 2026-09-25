<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FileUri;
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

    public function testSearchFindsClassLikesFromPsr4Roots(): void
    {
        $fqns = self::fqnsOfSearch($this->backend()->search('User', NameKind::ClassLike));

        self::assertContains(
            'Fixtures\Domain\User',
            $fqns,
            'a class-like reachable through a PSR-4 prefix must be found by short-name prefix',
        );
    }

    public function testSearchFindsClassLikesFromPsr0Roots(): void
    {
        $fqns = self::fqnsOfSearch($this->backend()->search('Psr0F', NameKind::ClassLike));

        self::assertContains(
            'Psr0\Psr0Fixture',
            $fqns,
            'a class-like reachable through a PSR-0 prefix must be found by short-name prefix',
        );
    }

    public function testSearchFindsClassLikesFromTheClassmap(): void
    {
        $fqns = self::fqnsOfSearch($this->backend()->search('Classmap', NameKind::ClassLike));

        self::assertContains(
            'Firehed\PhpLsp\Tests\Fixtures\Autoload\ClassmapFixture',
            $fqns,
            'a class-like reachable only through the classmap must be found by short-name prefix',
        );
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $fqns = self::fqnsOfSearch($this->backend()->search('user', NameKind::ClassLike));

        self::assertContains(
            'Fixtures\Domain\User',
            $fqns,
            'PHP matches class-like names case-insensitively, so search must too',
        );
    }

    public function testSearchHasNoReachForFunctions(): void
    {
        self::assertSame(
            [],
            $this->backend()->search('help', NameKind::Function_),
            'no autoload map addresses a function by name outside the files set',
        );
    }

    public function testSearchHasNoReachForConstants(): void
    {
        self::assertSame(
            [],
            $this->backend()->search('HELPER', NameKind::Constant),
            'no autoload map addresses a constant by name outside the files set',
        );
    }

    public function testInvalidateAddsANewFileToTheIndex(): void
    {
        $newFile = $this->fixturesRoot . '/src/Domain/Ephemeral.php';
        self::assertFileDoesNotExist($newFile, 'the fixture must not shadow a real file');

        $backend = $this->backend();
        self::assertNotContains(
            'Fixtures\Domain\Ephemeral',
            self::fqnsOfSearch($backend->search('Ephemeral', NameKind::ClassLike)),
            'the pre-invalidate index must not know about the file',
        );

        file_put_contents($newFile, "<?php\n\nnamespace Fixtures\\Domain;\n\nclass Ephemeral {}\n");
        try {
            $backend->invalidate(FileUri::fromPath($newFile));

            self::assertContains(
                'Fixtures\Domain\Ephemeral',
                self::fqnsOfSearch($backend->search('Ephemeral', NameKind::ClassLike)),
                'a file added after the walk must appear after invalidation (RFC 1 §5.2)',
            );
            self::assertContains(
                'Fixtures\Domain\Ephemeral',
                self::fqns($backend->childrenOf(new NamespaceName('Fixtures\Domain'))),
                'enumeration must reflect the same index that search reads',
            );
        } finally {
            unlink($newFile);
        }
    }

    public function testInvalidateRemovesAFileTheLoaderNoLongerConfirms(): void
    {
        $newFile = $this->fixturesRoot . '/src/Domain/Transient.php';
        file_put_contents($newFile, "<?php\n\nnamespace Fixtures\\Domain;\n\nclass Transient {}\n");

        try {
            $backend = $this->backend();
            self::assertContains(
                'Fixtures\Domain\Transient',
                self::fqnsOfSearch($backend->search('Transient', NameKind::ClassLike)),
                'sanity: the initial walk must see the file we just created',
            );

            unlink($newFile);
            $backend->invalidate(FileUri::fromPath($newFile));

            self::assertNotContains(
                'Fixtures\Domain\Transient',
                self::fqnsOfSearch($backend->search('Transient', NameKind::ClassLike)),
                'a file removed on disk must stop appearing after invalidation',
            );
        } finally {
            if (is_file($newFile)) {
                unlink($newFile);
            }
        }
    }

    public function testInvalidateIsANoOpWhenTheFileStillResolvesToTheSameName(): void
    {
        $backend = $this->backend();
        $before = self::fqnsOfSearch($backend->search('User', NameKind::ClassLike));
        self::assertContains('Fixtures\Domain\User', $before, 'sanity: the walk sees User');

        $backend->invalidate(FileUri::fromPath($this->fixturesRoot . '/src/Domain/User.php'));

        self::assertSame(
            $before,
            self::fqnsOfSearch($backend->search('User', NameKind::ClassLike)),
            'a file whose walked name is unchanged must leave the index alone',
        );
    }

    public function testInvalidateIgnoresAPathOutsideEveryPrefix(): void
    {
        // An existing `.php` file outside every prefix must walk through the
        // PSR-4 and PSR-0 loops in the derive step and land on the null-return
        // rather than a match, so nothing is added.
        $orphan = tempnam(sys_get_temp_dir(), 'php-lsp-orphan-') . '.php';
        file_put_contents($orphan, "<?php\n");

        try {
            $backend = $this->backend();
            $before = self::fqnsOfSearch($backend->search('User', NameKind::ClassLike));

            $backend->invalidate(FileUri::fromPath($orphan));

            self::assertSame(
                $before,
                self::fqnsOfSearch($backend->search('User', NameKind::ClassLike)),
                'a path no prefix maps must leave the index alone',
            );
        } finally {
            unlink($orphan);
        }
    }

    public function testInvalidateBeforeTheFirstReadIsANoOp(): void
    {
        // The index is built lazily on the first read: a stray invalidate that
        // preceded any query has nothing to adjust and must not force a walk.
        $backend = $this->backend();
        $backend->invalidate(FileUri::fromPath($this->fixturesRoot . '/src/Domain/User.php'));

        self::assertContains(
            'Fixtures\Domain\User',
            self::fqnsOfSearch($backend->search('User', NameKind::ClassLike)),
            'the first read builds the index from disk regardless of prior invalidations',
        );
    }

    public function testInvalidateAddsANewPsr0File(): void
    {
        $newFile = $this->fixturesRoot . '/Autoload/Psr0/Psr0New.php';
        self::assertFileDoesNotExist($newFile, 'the fixture must not shadow a real file');

        $backend = $this->backend();
        self::assertNotContains(
            'Psr0\Psr0New',
            self::fqnsOfSearch($backend->search('Psr0New', NameKind::ClassLike)),
            'sanity: the pre-invalidate index does not know about the file',
        );

        file_put_contents($newFile, "<?php\n\nclass Psr0_Psr0New {}\n");
        try {
            $backend->invalidate(FileUri::fromPath($newFile));

            self::assertContains(
                'Psr0\Psr0New',
                self::fqnsOfSearch($backend->search('Psr0New', NameKind::ClassLike)),
                'a PSR-0 file added after the walk must appear after invalidation',
            );
        } finally {
            unlink($newFile);
        }
    }

    public function testBuildIndexSkipsAFileAlreadyIndexedByALongerPsr4Prefix(): void
    {
        // Two PSR-4 prefixes overlap on disk: the longer prefix walks first
        // and files the class; the shorter prefix's walk must skip the same
        // path rather than adding a false-positive candidate under its own
        // namespace.
        $overlapRoot = $this->fixturesRoot . '/Psr4Overlap';
        $backend = $this->backendForMap(new ComposerAutoloadMap(
            psr4: [
                'Root\\Sub\\' => [$overlapRoot . '/Sub'],
                'Root\\' => [$overlapRoot],
            ],
        ));

        $enumerated = self::fqns($backend->childrenOf(new NamespaceName('Root\Sub')));

        self::assertSame(
            ['Root\Sub\Thing'],
            $enumerated,
            'the shorter PSR-4 prefix walk must skip the file the longer one already indexed',
        );
        self::assertSame(
            [],
            self::fqns($backend->childrenOf(new NamespaceName('Root'))),
            'the base prefix must not gain a false-positive candidate for the walked file',
        );
    }

    public function testBuildIndexDedupesFileAlreadyIndexedThroughAPsr4PrefixFromPsr0Walk(): void
    {
        // A PSR-4 root and a PSR-0 root that overlap on disk: `File.php` under
        // the shared directory is indexable through both. The longer PSR-4
        // prefix owns it first, so the PSR-0 walk must skip the file.
        $base = sys_get_temp_dir() . '/php-lsp-psr0-overlap-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($base . '/App/Sub', 0777, true), 'the overlap directory must be creatable');
        file_put_contents(
            $base . '/App/Sub/Thing.php',
            "<?php\n\nnamespace App\\Sub;\n\nclass Thing {}\n",
        );

        try {
            $backend = $this->backendForMap(new ComposerAutoloadMap(
                psr4: ['App\\Sub\\' => [$base . '/App/Sub']],
                psr0: ['App' => [$base]],
            ));

            $enumerated = self::fqns($backend->childrenOf(new NamespaceName('App\Sub')));

            self::assertSame(
                ['App\Sub\Thing'],
                $enumerated,
                'a file the longer PSR-4 prefix already indexed must not be re-added by a PSR-0 walk',
            );
        } finally {
            unlink($base . '/App/Sub/Thing.php');
            rmdir($base . '/App/Sub');
            rmdir($base . '/App');
            rmdir($base);
        }
    }

    public function testBuildIndexDedupesClassmapAgainstPsr4Walk(): void
    {
        // A classmap entry and a PSR-4 walked file can both point at the same
        // FQN: e.g. a project's own classmap listing a class it also autoloads
        // through a prefix. The second write to the catalog must be dropped so
        // that childrenOf returns one row rather than two.
        $backend = $this->backendForMap(new ComposerAutoloadMap(
            psr4: ['Fixtures\\' => [$this->fixturesRoot . '/src']],
            classMap: ['Fixtures\Domain\User' => $this->fixturesRoot . '/src/Domain/User.php'],
        ));

        $enumerated = self::fqns($backend->childrenOf(new NamespaceName('Fixtures\Domain')));

        self::assertSame(
            1,
            count(array_filter($enumerated, static fn(string $fqn): bool => $fqn === 'Fixtures\Domain\User')),
            'a name reachable both ways must appear once in the enumeration',
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

    /**
     * @param list<\Firehed\PhpLsp\Index\Symbol> $results
     * @return list<string>
     */
    private static function fqnsOfSearch(array $results): array
    {
        return array_map(
            static fn(\Firehed\PhpLsp\Index\Symbol $symbol): string => $symbol->fullyQualifiedName,
            $results,
        );
    }
}
