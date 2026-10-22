<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;

/**
 * The symbol a word written at a position names; null where no word is
 * written there or it names nothing that resolves.
 */
interface SymbolAtPositionInterface
{
    public function symbolAt(TextDocument $document, ByteOffset $offset): ?ResolvedSymbolInterface;
}
