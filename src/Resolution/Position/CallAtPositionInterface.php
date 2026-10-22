<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Resolution\CallContext;

/**
 * The call whose argument list holds a position, and where in it; null where
 * no call's arguments hold it or what is called does not resolve.
 */
interface CallAtPositionInterface
{
    public function callAt(TextDocument $document, ByteOffset $offset): ?CallContext;
}
