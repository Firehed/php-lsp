<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\TestCase;

/**
 * Golden parity for the document write path — the symbol state a document
 * open/update/close produces, which Step 2 migrates behind `SymbolSink`. The
 * golden freezes the indexed symbol state for a fixed corpus spanning every
 * extracted kind; a companion test freezes the open → update → close lifecycle so
 * re-indexing and clearing are proven, not assumed. All inputs are in-repo.
 *
 * See docs/architecture/0002-execution-plan.md, Step P; RFC 1 §4.3, §5.2.
 */
final class WritePathParityTest extends TestCase
{
    use AssertsGolden;

    /**
     * Documents whose combined symbol state is frozen. The set spans class,
     * interface, trait, enum, function, and method extraction, plus a document
     * with an anonymous class (whose members are deliberately not indexed).
     *
     * @var list<string>
     */
    private const array INDEXED_DOCUMENTS = [
        'src/Catalog/functions.php',
        'src/Domain/Entity.php',
        'src/Domain/User.php',
        'src/Enum/Status.php',
        'src/Traits/HasTimestamps.php',
        'src/TypeInference/AnonymousClass.php',
    ];

    private string $projectRoot;
    private SymbolIndex $index;
    private DocumentIndexer $indexer;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->index = new SymbolIndex();
        $this->indexer = new DocumentIndexer(
            ProductionSyntaxSource::create()->source,
            new SymbolExtractor(),
            $this->index,
        );
    }

    public function testWritePathSymbolStateMatchesGolden(): void
    {
        foreach (self::INDEXED_DOCUMENTS as $relative) {
            $this->indexer->index($this->document($relative));
        }

        $this->assertGoldenMatches('write-path', $this->stateByUri());
    }

    public function testUpdateReindexesInPlaceAndCloseClears(): void
    {
        $uri = 'file:///virtual/Document.php';

        $opened = new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nclass Alpha {}\n");
        $this->indexer->index($opened);
        self::assertSame(
            ['V\Alpha'],
            $this->fqnsFor($uri),
            'opening a document must index its symbols',
        );

        $updated = new TextDocument($uri, 'php', 2, "<?php\nnamespace V;\nclass Beta {}\ninterface Gamma {}\n");
        $this->indexer->index($updated);
        self::assertSame(
            ['V\Beta', 'V\Gamma'],
            $this->fqnsFor($uri),
            'updating a document must replace the prior symbols, not accumulate them',
        );

        $this->indexer->remove($uri);
        self::assertSame(
            [],
            $this->fqnsFor($uri),
            'closing a document must clear its symbols from the index',
        );
    }

    public function testUnparseableDocumentIndexesNoSymbols(): void
    {
        // The write path must survive a document that does not parse: it indexes
        // nothing rather than crashing, so a mid-edit broken file leaves the index
        // consistent (RFC 1 §9).
        $uri = 'file:///virtual/Broken.php';
        $broken = file_get_contents($this->projectRoot . '/tests/Fixtures/src/IncompleteCode/VeryBroken.php');
        self::assertNotFalse($broken, 'the broken fixture should be readable');

        $this->indexer->index(new TextDocument($uri, 'php', 1, $broken));

        self::assertSame(
            [],
            $this->fqnsFor($uri),
            'a document that does not parse must contribute no symbols',
        );
    }

    private function document(string $relative): TextDocument
    {
        $path = $this->projectRoot . '/tests/Fixtures/' . $relative;
        $content = file_get_contents($path);
        self::assertNotFalse($content, "fixture document should be readable: {$relative}");

        return new TextDocument('file://' . $path, 'php', 0, $content);
    }

    /**
     * The full indexed symbol state, grouped by the document it came from.
     *
     * @return array<string, list<array{fqn: string, name: string, kind: string, containerName: ?string}>>
     */
    private function stateByUri(): array
    {
        $byUri = [];
        foreach ($this->index->findByPrefix('') as $symbol) {
            $uri = GoldenCodec::relativizePath($symbol->location->uri, $this->projectRoot);
            $byUri[$uri][] = $this->serialize($symbol);
        }

        ksort($byUri);
        foreach ($byUri as &$symbols) {
            usort($symbols, static fn(array $a, array $b): int => strcmp($a['fqn'], $b['fqn']));
        }

        return $byUri;
    }

    /**
     * @return list<string>
     */
    private function fqnsFor(string $uri): array
    {
        $relative = GoldenCodec::relativizePath($uri, $this->projectRoot);
        $fqns = [];
        foreach ($this->index->findByPrefix('') as $symbol) {
            if (GoldenCodec::relativizePath($symbol->location->uri, $this->projectRoot) === $relative) {
                $fqns[] = $symbol->fullyQualifiedName;
            }
        }
        sort($fqns);

        return $fqns;
    }

    /**
     * @return array{fqn: string, name: string, kind: string, containerName: ?string}
     */
    private function serialize(Symbol $symbol): array
    {
        return [
            'fqn' => $symbol->fullyQualifiedName,
            'name' => $symbol->name,
            'kind' => $symbol->kind->name,
            'containerName' => $symbol->containerName,
        ];
    }
}
