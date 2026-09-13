<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\InvalidatableInterface;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSource;

/**
 * The single write path for open-document symbol state (RFC 1 §4.3, §5.2): document
 * lifecycle events register the document's declared symbols with the
 * {@see DocumentSymbolStore}, so lookup, enumeration and prefix search all draw
 * from one map (build-manifest step-46). The skeleton source in the composite
 * recovers the structural shape of a document php-parser drops, so a mid-edit
 * still yields declarations (RFC 1 §5.3).
 */
final class DocumentSymbolSink implements SymbolSink
{
    /**
     * @param list<InvalidatableInterface> $onDiskBackends the cached on-disk backends
     *        (workspace, vendor) whose entry for a file must be dropped when that
     *        file changes on disk or is closed after being edited (RFC 1 §5.2, §5.3)
     */
    public function __construct(
        private readonly DocumentSymbolStore $store,
        private readonly DeclarationSymbolInfoFactory $infoFactory,
        private readonly SyntaxSource $parser,
        private readonly DeclarationScanner $scanner,
        private readonly array $onDiskBackends = [],
    ) {
    }

    public function closeDocument(string $uri): void
    {
        $this->store->removeDocument($uri);

        // Closing a file that was edited in the editor must re-read from disk on
        // the next query rather than restore the pre-edit cached value (RFC 1 §5.3):
        // the open-document answer is gone, so drop any stale on-disk cache too.
        $this->invalidate($uri);
    }

    public function invalidate(string $uri): void
    {
        foreach ($this->onDiskBackends as $backend) {
            $backend->invalidate($uri);
        }
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
