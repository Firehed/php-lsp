<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Document;

/**
 * An open document's content is the client's, and the server must not read it
 * from the URI; once closed, its content is whatever the URI points to
 * ([LSP] textDocument/didOpen, textDocument/didClose).
 */
final readonly class CompositeDocumentSource implements DocumentSourceInterface
{
    public function __construct(
        private DocumentManager $open,
        private SourceFileReader $disk,
    ) {
    }

    public function read(string $uri): ?TextDocument
    {
        return $this->open->read($uri) ?? $this->disk->read($uri);
    }
}
