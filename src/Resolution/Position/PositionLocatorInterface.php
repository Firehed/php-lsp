<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;

/**
 * Reads what a document's structure has at a position. Used only by the
 * position services in this namespace.
 */
interface PositionLocatorInterface
{
    public function locate(TextDocument $document, ByteOffset $offset): Located;
}
