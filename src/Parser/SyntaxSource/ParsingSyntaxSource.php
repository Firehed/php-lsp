<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\TreeAnnotator;

/**
 * The {@see SyntaxSourceInterface}: reads the document's code, then annotates
 * the tree once.
 */
final class ParsingSyntaxSource implements SyntaxSourceInterface
{
    public function __construct(
        private readonly CodeParserInterface $parser,
        private readonly TreeAnnotator $annotator,
    ) {
    }

    public function parse(TextDocument $document): ParsedDocument
    {
        $attempt = $this->parser->parse($document->getContent());
        return new ParsedDocument($document, $this->annotator->annotate($attempt->tree));
    }
}
