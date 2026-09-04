<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\TestCase;

/**
 * {@see DocumentIndexer::indexParsed} is the seam that lets the single write path
 * (RFC 1 §4.3) hand one parse to the index rather than reparsing: the indexer
 * indexes the AST it is given, so the sink and the indexer share a parse
 * (Plan 0002 §5.5, Step 3a(iv)).
 */
final class DocumentIndexerTest extends TestCase
{
    use LoadsFixturesTrait;

    private ParserService $parser;
    private SymbolIndex $index;
    private DocumentIndexer $indexer;

    protected function setUp(): void
    {
        $this->parser = new ParserService();
        $this->index = new SymbolIndex();
        $this->indexer = new DocumentIndexer($this->parser, new SymbolExtractor(), $this->index);
    }

    public function testIndexParsedIndexesTheGivenAstRatherThanReparsingTheDocument(): void
    {
        // A document whose content declares a class, indexed against an empty AST: were
        // the indexer to reparse the content it would index the class; indexing the AST
        // it is given means it must not. That is what lets the sink feed one parse to
        // both stores instead of each reparsing.
        $document = new TextDocument('file:///User.php', 'php', 1, $this->loadFixture('src/Domain/User.php'));

        $this->indexer->indexParsed($document, []);
        self::assertNull(
            $this->index->findByFqn('Fixtures\Domain\User'),
            'indexParsed must index the supplied AST, not a reparse of the document content',
        );

        $this->indexer->indexParsed($document, $this->parser->parse($document));
        self::assertNotNull(
            $this->index->findByFqn('Fixtures\Domain\User'),
            'indexParsed must index the symbols in the AST it is given',
        );
    }
}
