<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Document;

/**
 * Tracks the open documents an LSP client is editing, keyed by URI.
 * The stored snapshot is the client's view of the file — every consumer
 * that needs the live buffer contents reads it from here.
 */
interface DocumentManagerInterface
{
    public function open(string $uri, string $languageId, int $version, string $content): void;

    public function update(string $uri, string $content, int $version): void;

    public function close(string $uri): void;

    public function get(string $uri): ?TextDocument;

    public function isOpen(string $uri): bool;
}
