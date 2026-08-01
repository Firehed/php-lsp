<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\Invalidatable;
use Firehed\PhpLsp\Document\TextDocument;

/**
 * The write contract for symbol state (RFC 1 §4.3, §5.2): the sole means of mutating
 * what {@see SymbolSource} reports. Kept separate from the read side so a read-only
 * consumer depends only on SymbolSource; one class may implement both.
 *
 * There is exactly one write path (RFC 1 §4.3), and it has three producers: the
 * editor lifecycle (open/update/close) and external on-disk change. The latter is
 * {@see Invalidatable::invalidate()}, extended here so it flows through this write
 * path rather than reaching a backend directly.
 */
interface SymbolSink extends Invalidatable
{
    public function closeDocument(string $uri): void;

    public function openDocument(TextDocument $document): void;

    public function updateDocument(TextDocument $document): void;
}
