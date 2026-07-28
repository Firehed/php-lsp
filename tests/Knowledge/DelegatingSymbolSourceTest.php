<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Knowledge\DelegatingSymbolSource;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassRepository;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use PHPUnit\Framework\TestCase;

/**
 * The Step-2 facade is pure delegation, so these tests prove it forwards to today's
 * collaborators unchanged: the read side against real fixture-backed repositories and
 * indexes, the namespace enumeration against a forwarding assertion, and the write
 * side against the double write it must reproduce (Plan 0002 §5.5; RFC 1 §4.2–§4.3,
 * §5.1–§5.2). Behavior parity with the surfaces themselves is frozen separately by the
 * Step P golden harness (tests/Parity), which the facade rides unchanged.
 */
final class DelegatingSymbolSourceTest extends TestCase
{
    private DefaultClassInfoFactory $factory;
    private SymbolIndex $index;
    private DocumentIndexer $indexer;
    private ParserService $parser;
    private string $projectRoot;
    private ClassRepository $repository;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->parser = new ParserService();
        $this->factory = new DefaultClassInfoFactory();
        $this->index = new SymbolIndex();
        $this->indexer = new DocumentIndexer($this->parser, new SymbolExtractor(), $this->index);
        $this->repository = new DefaultClassRepository(
            $this->factory,
            new ComposerClassLocator($this->projectRoot . '/tests/Fixtures'),
            $this->parser,
        );
    }

    public function testLookupClassLikeReturnsTheRepositoryResult(): void
    {
        $source = $this->facade($this->unusedCatalog());

        $info = $source->lookupClassLike(self::className('Fixtures\Domain\User'));

        self::assertNotNull($info, 'a fixture class must resolve through the repository');
        self::assertSame(
            'Fixtures\Domain\User',
            $info->name->fqn,
            'lookupClassLike must return the repository result unchanged',
        );
    }

    public function testLookupClassLikeReturnsNullForAnAbsentClass(): void
    {
        $source = $this->facade($this->unusedCatalog());

        self::assertNull(
            $source->lookupClassLike(self::className('Fixtures\Does\Not\Exist')),
            'a name nothing declares must resolve to null, not an error (RFC 1 §5.3)',
        );
    }

    public function testSearchClassLikesReturnsOnlyClassLikeKinds(): void
    {
        // The index also holds methods and functions; searchClassLikes searches the
        // class-like namespace only (function/constant search is Step 3b). Index one
        // fixture of every class-like kind so a kind dropped from the search set is
        // caught by its missing FQN, not only by the allowlist below.
        $this->indexFixture('src/Domain/User.php'); // class
        $this->indexFixture('src/Enum/Status.php'); // enum
        $this->indexFixture('src/Hierarchy/BaseInterface.php'); // interface
        $this->indexFixture('src/Traits/HasTimestamps.php'); // trait
        $this->indexFixture('src/Catalog/functions.php'); // function (must be excluded)
        $source = $this->facade($this->unusedCatalog());

        $results = $source->searchClassLikes('');

        self::assertNotEmpty(
            $this->index->findByPrefix('', [SymbolKind::Function_]),
            'the corpus must index at least one function so its exclusion is meaningful',
        );
        foreach ($results as $symbol) {
            self::assertContains(
                $symbol->kind,
                [SymbolKind::Class_, SymbolKind::Enum_, SymbolKind::Interface_, SymbolKind::Trait_],
                'searchClassLikes must return class-likes only, never methods or functions',
            );
        }
        $fqns = self::fqns($results);
        foreach (
            [
                'Fixtures\Domain\User',
                'Fixtures\Enum\Status',
                'Fixtures\Hierarchy\BaseInterface',
                'Fixtures\Traits\HasTimestamps',
            ] as $expected
        ) {
            self::assertContains(
                $expected,
                $fqns,
                "every class-like kind must be searchable; {$expected} was dropped",
            );
        }
    }

    public function testSearchClassLikesFiltersByPrefix(): void
    {
        $this->indexFixture('src/Domain/User.php');
        $this->indexFixture('src/Domain/Entity.php');
        $source = $this->facade($this->unusedCatalog());

        $fqns = self::fqns($source->searchClassLikes('User'));

        self::assertContains('Fixtures\Domain\User', $fqns, 'a matching prefix must be included');
        self::assertNotContains(
            'Fixtures\Domain\Entity',
            $fqns,
            'a symbol whose name does not begin with the prefix must be excluded',
        );
    }

    public function testChildrenOfForwardsTheNamespacePath(): void
    {
        $expected = new NamespaceContents(['Fixtures\Domain\Sub'], []);
        $catalog = $this->createMock(NamespaceCatalog::class);
        $catalog->expects($this->once())
            ->method('childrenOf')
            ->with('Fixtures\Domain')
            ->willReturn($expected);

        self::assertSame(
            $expected,
            $this->facade($catalog)->childrenOf(new NamespaceName('Fixtures\Domain')),
            'childrenOf must forward the namespace path and return the catalog result unchanged',
        );
    }

    public function testChildrenOfForwardsTheGlobalNamespaceAsAnEmptyPath(): void
    {
        $expected = new NamespaceContents(['Fixtures'], []);
        $catalog = $this->createMock(NamespaceCatalog::class);
        $catalog->expects($this->once())
            ->method('childrenOf')
            ->with('')
            ->willReturn($expected);

        self::assertSame(
            $expected,
            $this->facade($catalog)->childrenOf(new NamespaceName('')),
            'the global namespace must forward as the empty path',
        );
    }

    public function testOpenDocumentWritesToBothTheRepositoryAndTheIndex(): void
    {
        $source = $this->facade($this->unusedCatalog());
        $uri = 'file:///virtual/Widget.php';
        $content = "<?php\nnamespace Virtual;\nfinal class Widget { public function tick(): void {} }\n";

        $source->openDocument(new TextDocument($uri, 'php', 1, $content));

        $info = $this->repository->get(self::className('Virtual\Widget'));
        self::assertNotNull($info, 'openDocument must register the class with the repository');
        self::assertSame('Virtual\Widget', $info->name->fqn, 'the registered class must be the opened one');
        self::assertNotNull(
            $this->index->findByFqn('Virtual\Widget'),
            'openDocument must also index the document — the second of the double write',
        );
    }

    public function testUpdateDocumentReplacesThePriorSymbolsInBothStores(): void
    {
        $source = $this->facade($this->unusedCatalog());
        $uri = 'file:///virtual/Doc.php';

        $source->openDocument(new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nclass Alpha {}\n"));
        $source->updateDocument(new TextDocument($uri, 'php', 2, "<?php\nnamespace V;\nclass Beta {}\n"));

        self::assertNull($this->index->findByFqn('V\Alpha'), 'update must clear the prior symbols from the index');
        self::assertNotNull($this->index->findByFqn('V\Beta'), 'update must index the new symbols');
        self::assertNotNull(
            $this->repository->get(self::className('V\Beta')),
            'update must register the new class with the repository',
        );
        self::assertNull(
            $this->repository->get(self::className('V\Alpha')),
            'update must drop the prior class from the repository',
        );
    }

    public function testCloseDocumentClearsBothStores(): void
    {
        $source = $this->facade($this->unusedCatalog());
        $uri = 'file:///virtual/Ephemeral.php';
        $source->openDocument(new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nclass Ephemeral {}\n"));

        $source->closeDocument($uri);

        self::assertNull($this->index->findByFqn('V\Ephemeral'), 'close must clear the indexed symbols');
        self::assertNull(
            $this->repository->get(self::className('V\Ephemeral')),
            'close must drop the registered class from the repository',
        );
    }

    public function testWriteSurvivesAMalformedDocument(): void
    {
        // A mid-edit broken file the parser cannot recover to an AST must leave both
        // stores untouched rather than crash (RFC 1 §9): the null-AST guard skips class
        // registration, so no bogus class reaches the repository, and the indexer no-ops.
        $source = $this->facade($this->unusedCatalog());
        $uri = 'file:///virtual/Broken.php';
        $broken = file_get_contents($this->projectRoot . '/tests/Fixtures/src/IncompleteCode/VeryBroken.php');
        self::assertNotFalse($broken, 'the broken fixture should be readable');

        $source->openDocument(new TextDocument($uri, 'php', 1, $broken));

        self::assertSame(
            [],
            $this->indexedFqnsFor($uri),
            'a malformed document must contribute no indexed symbols and must not crash',
        );
    }

    private function facade(NamespaceCatalog $catalog): DelegatingSymbolSource
    {
        return new DelegatingSymbolSource(
            $this->repository,
            $this->index,
            $catalog,
            $this->indexer,
            $this->factory,
            $this->parser,
        );
    }

    private function indexFixture(string $relative): void
    {
        $path = $this->projectRoot . '/tests/Fixtures/' . $relative;
        $content = file_get_contents($path);
        self::assertNotFalse($content, "fixture document should be readable: {$relative}");
        $this->indexer->index(new TextDocument('file://' . $path, 'php', 0, $content));
    }

    /**
     * @return list<string>
     */
    private function indexedFqnsFor(string $uri): array
    {
        $fqns = [];
        foreach ($this->index->findByPrefix('') as $symbol) {
            if ($symbol->location->uri === $uri) {
                $fqns[] = $symbol->fullyQualifiedName;
            }
        }
        sort($fqns);

        return $fqns;
    }

    private function unusedCatalog(): NamespaceCatalog
    {
        return self::createStub(NamespaceCatalog::class);
    }

    /**
     * Fixture and virtual names are outside PHPStan's autoload path, so they are not
     * seen as class-strings; the repository reads only the FQN, so the concession is
     * harmless and confined here.
     */
    private static function className(string $fqn): ClassName
    {
        /** @phpstan-ignore argument.type (fixture and virtual names are not analyzed) */
        return new ClassName($fqn);
    }

    /**
     * @param list<Symbol> $symbols
     * @return list<string>
     */
    private static function fqns(array $symbols): array
    {
        return array_map(static fn(Symbol $symbol): string => $symbol->fullyQualifiedName, $symbols);
    }
}
