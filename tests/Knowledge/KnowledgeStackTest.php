<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\CompositeDocumentSource;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\SourceFileReader;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\CatalogSymbol;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Knowledge\ComposerAutoloadMapReader;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use Firehed\PhpLsp\Tests\Parser\CountingSyntaxSource;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The knowledge tier's composition root: these prove the assembled stack resolves a
 * workspace class through the backends, that a document opened through the sink is
 * visible to the read source (they share one open-document backend), and that a
 * pre-populated index is honored.
 */
final class KnowledgeStackTest extends TestCase
{
    private string $fixturesRoot;
    private MemoizingSyntaxSource $parser;
    private SourceFileReader $reader;
    private CountingSyntaxSource $counter;

    protected function setUp(): void
    {
        $this->fixturesRoot = dirname(__DIR__, 2) . '/tests/Fixtures';
        $production = ProductionSyntaxSource::create();
        $this->parser = $production->source;
        $this->reader = $production->reader;
        $this->counter = $production->counter;
    }

    public function testSourceResolvesAWorkspaceClassThroughTheBackends(): void
    {
        $stack = KnowledgeStack::forProject(
            new ComposerAutoloadMapReader($this->fixturesRoot),
            $this->parser,
            $this->reader,
        );

        self::assertNotNull(
            $stack->source->lookupClassLike(self::className('Fixtures\Domain\User')),
            'a fixture class must resolve through the assembled filesystem backend',
        );
    }

    /**
     * @return iterable<string, array{string}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function autoloadFilesClassLikes(): iterable
    {
        // Composer's maps address none of these: they are declared in an
        // `autoload.files` entry, which is keyed by no name at all. Every flavour is
        // covered, because a scan narrowed to `class` would lose three of them
        // silently (#181).
        yield 'interface' => ['Fixtures\Helpers\HelperContract'];
        yield 'trait' => ['Fixtures\Helpers\HelperFallback'];
        yield 'enum' => ['Fixtures\Helpers\HelperMode'];
        yield 'class' => ['Fixtures\Helpers\HelperRegistry'];
        yield 'global class' => ['FixtureGlobalRegistry'];
    }

    #[DataProvider('autoloadFilesClassLikes')]
    public function testSourceResolvesAClassLikeDeclaredInAnAutoloadFilesEntry(string $fqn): void
    {
        $stack = KnowledgeStack::forProject(
            new ComposerAutoloadMapReader($this->fixturesRoot),
            $this->parser,
            $this->reader,
        );

        $info = $stack->source->lookupClassLike(self::className($fqn));

        self::assertNotNull($info, "a class-like declared in an autoload.files entry must resolve: {$fqn}");
        self::assertSame(
            $fqn,
            $info->name->qualifiedName->fullyQualifiedName(),
            'the located class-like must be the one asked for',
        );
    }

    /**
     * RFC 1 §4.2: lookup and enumeration are distinct operations that must draw on
     * the same backends, so their coverage is identical. A `files`-declared name
     * that resolved on hover while being absent from namespace completion is exactly
     * the per-surface split this series exists to prevent.
     */
    #[DataProvider('autoloadFilesClassLikes')]
    public function testAClassLikeInAnAutoloadFilesEntryIsEnumeratedWhereverItResolves(string $fqn): void
    {
        $stack = KnowledgeStack::forProject(
            new ComposerAutoloadMapReader($this->fixturesRoot),
            $this->parser,
            $this->reader,
        );

        self::assertNotNull(
            $stack->source->lookupClassLike(self::className($fqn)),
            "lookup must reach the name for enumeration to be held to it: {$fqn}",
        );

        $namespace = NamespaceName::namespaceOf($fqn);
        $fqns = array_map(
            static fn(CatalogSymbol $symbol): string => $symbol->fullyQualifiedName,
            $stack->source->childrenOf(new NamespaceName($namespace))->symbols,
        );
        self::assertContains(
            $fqn,
            $fqns,
            "a resolvable name must also be enumerated in its own namespace: {$fqn}",
        );
    }

    /**
     * `autoload.files` entries sit outside every PSR-4 and PSR-0 prefix, so the
     * namespace they declare into cannot be reached by a directory listing. Both
     * routes must survive the merge: the composer source keeps enumerating the
     * PSR-4 tree, and the derived index adds what it structurally cannot see.
     */
    public function testAnAutoloadFilesNamespaceIsReachableAlongsideThePsr4Tree(): void
    {
        $stack = KnowledgeStack::forProject(
            new ComposerAutoloadMapReader($this->fixturesRoot),
            $this->parser,
            $this->reader,
        );

        $children = $stack->source->childrenOf(new NamespaceName('Fixtures'))->childNamespaces;

        self::assertContains(
            'Fixtures\Helpers',
            $children,
            'a namespace declared only by an autoload.files entry must be navigable',
        );
        self::assertContains(
            'Fixtures\Domain',
            $children,
            'merging the derived index must not displace the directory listing',
        );
    }

    /**
     * Enumeration is wired onto data already derived, not
     * a second scan: the set is parsed once at construction and enumerating it
     * groups what is already in memory.
     */
    public function testEnumeratingTheAutoloadFilesSetCostsNoFurtherParse(): void
    {
        $stack = KnowledgeStack::forProject(
            new ComposerAutoloadMapReader($this->fixturesRoot),
            $this->parser,
            $this->reader,
        );
        $afterConstruction = $this->counter->parseCount;

        $stack->source->childrenOf(new NamespaceName('Fixtures\Helpers'));
        $stack->source->childrenOf(new NamespaceName('Fixtures'));

        self::assertSame(
            $afterConstruction,
            $this->counter->parseCount,
            'enumeration reads the index built at construction rather than re-scanning the set',
        );
    }

    /**
     * The index is built eagerly, so the set is parsed at construction rather than
     * mid-keystroke. This pins that it happens, and that indexing never reaches
     * outside the `files` set into the PSR-4 tree. It cannot see the same content
     * parsed twice — the memo is keyed by content — so it bounds no cost.
     */
    public function testTheAutoloadFilesIndexIsBuiltOnceAtConstruction(): void
    {
        KnowledgeStack::forProject(
            new ComposerAutoloadMapReader($this->fixturesRoot),
            $this->parser,
            $this->reader,
        );

        self::assertSame(
            2,
            $this->counter->parseCount,
            'construction parses each autoload.files entry exactly once',
        );
    }

    public function testARepeatedLookupIsServedWithoutReParsing(): void
    {
        $stack = KnowledgeStack::forProject(
            new ComposerAutoloadMapReader($this->fixturesRoot),
            $this->parser,
            $this->reader,
        );
        $name = self::className('Fixtures\Domain\User');

        self::assertNotNull($stack->source->lookupClassLike($name));
        $afterFirst = $this->counter->parseCount;
        // The parser's own memo lasts one message; only the stack's cache can
        // answer across messages.
        $this->parser->endMessage();
        $stack->source->lookupClassLike($name);

        self::assertSame(
            $afterFirst,
            $this->counter->parseCount,
            'a repeated lookup across messages is remembered, not re-parsed from disk',
        );
    }

    public function testTwoNamesInOneFileCostOneParse(): void
    {
        $file = $this->fixturesRoot . '/MultiClass/MultiClass.php';
        $stack = KnowledgeStack::forProject(
            ComposerAutoloadMapReader::fromMap(new ComposerAutoloadMap(classMap: [
                'Fixtures\Completion\FirstUnrelated' => $file,
                'Fixtures\Completion\SecondUnrelated' => $file,
            ])),
            $this->parser,
            $this->reader,
        );

        self::assertNotNull(
            $stack->source->lookupClassLike(self::className('Fixtures\Completion\FirstUnrelated')),
            'the first name must resolve for the parse count to mean anything',
        );
        $afterFirst = $this->counter->parseCount;
        $this->parser->endMessage();

        self::assertNotNull(
            $stack->source->lookupClassLike(self::className('Fixtures\Completion\SecondUnrelated')),
            'the second name is declared in the same file',
        );
        self::assertSame(
            $afterFirst,
            $this->counter->parseCount,
            'what a file declares is remembered for the file, not for the name that was asked',
        );
    }

    public function testAClassRemovedInAnOpenBufferNoLongerResolves(): void
    {
        $file = $this->fixturesRoot . '/src/Domain/User.php';
        $buffers = new DocumentManager();
        $stack = KnowledgeStack::forProject(
            new ComposerAutoloadMapReader($this->fixturesRoot),
            $this->parser,
            new CompositeDocumentSource($buffers, $this->reader),
        );
        $name = self::className('Fixtures\Domain\User');

        self::assertNotNull(
            $stack->source->lookupClassLike($name),
            'the class must resolve from disk first, so a remembered answer has the chance to outlive the edit',
        );
        $this->parser->endMessage();

        // The buffer now holds what another fixture declares: User is gone from it.
        $uri = FileUri::fromPath($file);
        $buffers->open($uri, 'php', 1, (string) file_get_contents($this->fixturesRoot . '/src/Domain/Entity.php'));
        $document = $buffers->get($uri);
        self::assertNotNull($document, 'the buffer store must hold what was just opened');
        $stack->sink->openDocument($document);

        self::assertNull(
            $stack->source->lookupClassLike($name),
            'while a document is open its content is the client\'s, and the client\'s no longer declares the class',
        );
    }

    public function testADocumentOpenedThroughTheSinkIsVisibleToTheSource(): void
    {
        $stack = KnowledgeStack::forProject(
            ComposerAutoloadMapReader::fromMap(new ComposerAutoloadMap()),
            $this->parser,
            $this->reader,
        );

        $stack->sink->openDocument(new TextDocument(
            'file:///virtual/Widget.php',
            'php',
            1,
            "<?php\nnamespace V;\nclass Widget {}\n",
        ));

        self::assertNotNull(
            $stack->source->lookupClassLike(self::className('V\Widget')),
            'the read source and the write sink share one open-document backend',
        );
    }

    private static function className(string $fqn): ClasslikeName
    {
        return ClasslikeName::fromFullyQualified($fqn);
    }
}
