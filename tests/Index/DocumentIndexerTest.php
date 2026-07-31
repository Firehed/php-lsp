<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use PHPUnit\Framework\TestCase;

/**
 * {@see DocumentIndexer::indexParsed} is the seam that lets the single write path
 * (RFC 1 §4.3) hand one parse to the index rather than reparsing: the indexer
 * indexes the AST it is given, so the sink and the indexer share a parse
 * (Plan 0002 §5.5, Step 3a(iv)).
 */
final class DocumentIndexerTest extends TestCase
{
    private SymbolIndex $index;
    private DocumentIndexer $indexer;

    protected function setUp(): void
    {
        $this->index = new SymbolIndex();
        $this->indexer = new DocumentIndexer(new ParserService(), new SymbolExtractor(), $this->index);
    }

    public function testIndexParsedIndexesTheGivenAstRatherThanReparsingTheDocument(): void
    {
        // The document content declares a class, but the supplied AST is empty. If the
        // indexer reparsed the content it would index the class; indexing the given AST
        // means it must not — that is what lets the sink feed one parse to both stores.
        $document = new TextDocument('file:///Reparse.php', 'php', 1, "<?php\nnamespace V;\nclass Reparsed {}\n");

        $this->indexer->indexParsed($document, []);

        self::assertNull(
            $this->index->findByFqn('V\Reparsed'),
            'indexParsed must index the supplied AST, not a reparse of the document content',
        );
    }

    public function testIndexParsedReplacesThePriorSymbolsForTheDocument(): void
    {
        $uri = 'file:///Doc.php';
        $parser = new ParserService();

        $first = new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nclass Alpha {}\n");
        $this->indexer->indexParsed($first, $parser->parse($first) ?? []);
        self::assertNotNull($this->index->findByFqn('V\Alpha'), 'the first AST must be indexed');

        $second = new TextDocument($uri, 'php', 2, "<?php\nnamespace V;\nclass Beta {}\n");
        $this->indexer->indexParsed($second, $parser->parse($second) ?? []);

        self::assertNull($this->index->findByFqn('V\Alpha'), 'reindexing must clear the prior symbols');
        self::assertNotNull($this->index->findByFqn('V\Beta'), 'reindexing must add the new symbols');
    }
}
