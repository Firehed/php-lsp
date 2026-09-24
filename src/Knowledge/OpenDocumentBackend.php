<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\DeclaredSymbol;

/**
 * The highest-precedence {@see SymbolSourceInterface}: the documents the editor has open
 * (RFC 1 §5.3). Its answers override every on-disk backend, so a user's unsaved
 * edits are honored — including edits to a vendored file opened in the editor.
 *
 * Open documents change on every keystroke and are never cached (RFC 1 §5.3). One
 * document's declarations are held under its URI in the {@see DeclaredSymbolStoreTrait}
 * store, which the three read surfaces share so lookup, `childrenOf` and `search`
 * can never disagree about what an open document declares.
 */
final class OpenDocumentBackend implements SymbolSourceInterface, DocumentSymbolStoreInterface
{
    use DeclaredSymbolStoreTrait;
    use LooksUpByKindTrait;

    public function removeDocument(string $uri): void
    {
        $this->removeSymbolsFor($uri);
    }

    /**
     * Register the symbols declared in an open document, replacing any previously
     * registered for the same URI.
     */
    public function updateDocument(string $uri, DeclaredSymbol ...$symbols): void
    {
        $this->setSymbolsFor($uri, ...$symbols);
    }
}
