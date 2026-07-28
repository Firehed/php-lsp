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

    public function openDocument(TextDocument $document): void;

    public function updateDocument(TextDocument $document): void;
}
