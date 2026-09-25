<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;

/**
 * The write contract for open-document symbol state (RFC 1 §4.3, §5.2). Kept
 * separate from the read side so a read-only consumer depends only on
 * SymbolSourceInterface; one class may implement both.
 *
 * External on-disk change flows through {@see \Firehed\PhpLsp\Cache\InvalidatableInterface}
 * instead: the two paths share no logic, and coupling them here forced open-document
 * writes and cache invalidations through a single object for no gain.
 */
interface SymbolSinkInterface
{
    public function closeDocument(string $uri): void;

    public function openDocument(TextDocument $document): void;

    public function updateDocument(TextDocument $document): void;
}
