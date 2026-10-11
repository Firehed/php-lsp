<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use PhpParser\ErrorHandler;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * The {@see SyntaxSourceInterface} backed by php-parser: the one class that names
 * {@see \PhpParser\Parser}. Recovers from partial or invalid input through the
 * error-collecting handler, and hands the resulting tree to {@see TreeAnnotator}
 * so parent links and resolved names are set the same way every other
 * tree-producing source has them set.
 */
final class PhpParserSyntaxSource implements SyntaxSourceInterface
{
    private readonly Parser $parser;

    public function __construct(
        private readonly TreeAnnotator $annotator,
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function parse(TextDocument $document): ParsedDocument
    {
        $errorHandler = new ErrorHandler\Collecting();

        try {
            $ast = $this->parser->parse($document->getContent(), $errorHandler);
            if ($ast === null) {
                return new ParsedDocument($document, []);
            }
            return new ParsedDocument($document, $this->annotator->annotate($ast, $this->parser->getTokens()));
        } catch (\PhpParser\Error) {
            return new ParsedDocument($document, []);
        }
    }
}
