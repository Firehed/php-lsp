<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;

final readonly class ParsedDeclarationSource implements DeclarationSourceInterface
{
    public function __construct(
        private SyntaxSourceInterface $parser,
        private DeclarationScanner $scanner,
        private DeclarationSymbolInfoFactory $infoFactory,
    ) {
    }

    public function declarationsIn(TextDocument $document): array
    {
        return $this->infoFactory->allIn(
            $this->scanner->scan($this->parser->parse($document)->tree),
            FileUri::toPath($document->uri),
        );
    }
}
