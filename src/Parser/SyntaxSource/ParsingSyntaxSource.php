<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\Recovery\RecoveryInterface;
use Firehed\PhpLsp\Parser\TreeAnnotator;

/**
 * The {@see SyntaxSourceInterface}: reads the document's code, recovers what
 * the parser could not read, then annotates the tree once. Valid code and
 * code being edited take the same route, so every reader sees one structure.
 */
final class ParsingSyntaxSource implements SyntaxSourceInterface
{
    public function __construct(
        private readonly CodeParserInterface $parser,
        private readonly RecoveryInterface $recovery,
        private readonly TreeAnnotator $annotator,
    ) {
    }

    public function parse(TextDocument $document): ParsedDocument
    {
        $code = $document->getContent();
        $attempt = $this->recovery->recover($code, $this->parser->parse($code));
        return new ParsedDocument($document, $this->annotator->annotate($attempt->tree));
    }
}
