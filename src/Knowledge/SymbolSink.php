<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;

/**
 * The write contract for symbol state (RFC 1 §4.3, §5.2): the sole means of mutating
 * what {@see SymbolSource} reports, keyed by document lifecycle. Kept a separate
 * interface from the read side so a read-only consumer depends only on SymbolSource;
 * a single class may implement both (RFC 1 §4.3).
 *
 * There MUST be exactly one write path for symbol state (RFC 1 §4.3): any other
 * producer — background indexing, external on-disk change — writes through this
 * interface rather than a second store.
 */
interface SymbolSink
{
    public function closeDocument(string $uri): void;

    /**
     * Invalidate any cached workspace state for a file that changed on disk while
     * it was not open in the editor — an external edit, a branch checkout, or a
     * deletion (RFC 1 §5.2). External change is the third producer of symbol-state
     * mutation, alongside the editor lifecycle and background indexing, and so
     * flows through this one write path rather than reaching into a backend
     * directly.
     */
    public function invalidate(string $uri): void;

    public function openDocument(TextDocument $document): void;

    public function updateDocument(TextDocument $document): void;
}
