<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;

/**
 * The highest-precedence {@see SymbolSourceInterface}: the documents the editor has open
 * (RFC 1 §5.3). Its answers override every on-disk backend, so a user's unsaved
 * edits are honored — including edits to a vendored file opened in the editor.
 *
 * Also owns the write path for open-document symbol state (RFC 1 §4.3, §5.2).
 * Open documents change on every keystroke and are never cached (RFC 1 §5.3).
 */
final class OpenDocumentBackend implements SymbolSourceInterface, SymbolSinkInterface
{
    use DeclaredSymbolStoreTrait;
    use LooksUpByKindTrait;

    public function __construct(
        private readonly SyntaxSourceInterface $parser,
        private readonly DeclarationScanner $scanner,
        private readonly DeclarationSymbolInfoFactory $infoFactory,
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
        $ast = $this->parser->parse($document);
        $declarations = $this->scanner->scan($ast);
        $filePath = FileUri::toPath($document->uri);
        $symbols = $this->infoFactory->allIn($declarations, $filePath);

        $this->setSymbolsFor($document->uri, ...$symbols);
    }
}
