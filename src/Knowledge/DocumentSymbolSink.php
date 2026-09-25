<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;

/**
 * The single write path for open-document symbol state (RFC 1 §4.3, §5.2): document
 * lifecycle events register the document's declared symbols with the
 * {@see DocumentSymbolStoreInterface}, so lookup, enumeration and prefix search all draw
 * from one map. The skeleton source in the composite recovers the structural shape
 * of a document php-parser drops, so a mid-edit still yields declarations (RFC 1 §5.3).
 */
final class DocumentSymbolSink implements SymbolSinkInterface
{
    public function __construct(
        private readonly DocumentSymbolStoreInterface $store,
        private readonly DeclarationSymbolInfoFactory $infoFactory,
        private readonly SyntaxSourceInterface $parser,
        private readonly DeclarationScanner $scanner,
    ) {
    }

    public function closeDocument(string $uri): void
    {
        $this->store->removeDocument($uri);
    }

    public function openDocument(TextDocument $document): void
    {
        $this->write($document);
    }

    public function updateDocument(TextDocument $document): void
    {
        $this->write($document);
    }

    private function write(TextDocument $document): void
    {
        $ast = $this->parser->parse($document);
        $declarations = $this->scanner->scan($ast);
        $filePath = FileUri::toPath($document->uri);
        $symbols = $this->infoFactory->allIn($declarations, $filePath);

        $this->store->updateDocument($document->uri, ...$symbols);
    }
}
