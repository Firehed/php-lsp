<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\DeclaredSymbol;

/**
 * The write seam the {@see DocumentSymbolSink} holds for open-document lookup
 * state: the two operations a document lifecycle event needs — register the
 * document's declared symbols, or drop them — with no read surface.
 *
 * Splitting the write seam from {@see SymbolBackendInterface} keeps the sink typed on the
 * one route it drives rather than on a full backend it does not read (RFC 1
 * §4.11, one route per fact).
 */
interface DocumentSymbolStore
{
    public function removeDocument(string $uri): void;

    public function updateDocument(string $uri, DeclaredSymbol ...$symbols): void;
}
