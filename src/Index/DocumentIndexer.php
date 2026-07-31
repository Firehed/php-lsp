<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParserService;
use PhpParser\Node\Stmt;

final class DocumentIndexer
{
    public function __construct(
        private readonly ParserService $parser,
        private readonly SymbolExtractor $extractor,
        private readonly SymbolIndex $index,
    ) {
    }

    public function index(TextDocument $document): void
    {
        $this->indexParsed($document, $this->parser->parse($document) ?? []);
    }

    /**
     * Index a document from an already-parsed AST rather than reparsing it. The
     * single write path (RFC 1 §4.3) parses once and feeds that AST here, so the
     * index and the class-lookup store share one parse (Plan 0002 §5.5, Step 3a(iv)).
     *
     * @param array<Stmt> $ast
     */
    public function indexParsed(TextDocument $document, array $ast): void
    {
        $this->index->clearByUri($document->uri);

        foreach ($this->extractor->extract($document, $ast) as $symbol) {
            $this->index->add($symbol);
        }
    }

    public function remove(string $uri): void
    {
        $this->index->clearByUri($uri);
    }
}
