<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;

/**
 * The write contract for open-document symbol state (RFC 1 §4.3, §5.2). Kept
 * separate from the read side so a read-only consumer depends only on
 * {@see SymbolSourceInterface}; one class may implement both.
 */
interface SymbolSinkInterface
{
    public function closeDocument(string $uri): void;

    public function openDocument(TextDocument $document): void;

    public function updateDocument(TextDocument $document): void;
}
