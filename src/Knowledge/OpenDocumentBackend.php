<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;

/**
 * The highest-precedence {@see SymbolSourceInterface}: the documents the editor has open
 * (RFC 1 §5.3). Its answers override every on-disk backend, so a user's unsaved
 * edits are honored — including edits to a vendored file opened in the editor.
 *
 * Also owns the write path for open-document symbol state (RFC 1 §4.3, §5.2).
 */
final class OpenDocumentBackend implements SymbolSourceInterface, SymbolSinkInterface
{
    use DeclaredSymbolStoreTrait;
    use LooksUpByKindTrait;

    public function __construct(
        private readonly DeclarationSourceInterface $declarations,
    ) {
    }

    public function closeDocument(string $uri): void
    {
        $this->removeSymbolsFor($uri);
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
        $this->setSymbolsFor($document->uri, ...$this->declarations->declarationsIn($document));
    }
}
