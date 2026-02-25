<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParserService;

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
        // Clear old symbols from this file
        $this->index->clearByUri($document->uri);

        // Parse
        $ast = $this->parser->parse($document);
        if ($ast === null) {
            return; // Parse error, skip indexing
        }

        // Extract and index symbols
        $symbols = $this->extractor->extract($document, $ast);
        foreach ($symbols as $symbol) {
            $this->index->add($symbol);
        }
    }

    public function remove(string $uri): void
    {
        $this->index->clearByUri($uri);
    }
}
