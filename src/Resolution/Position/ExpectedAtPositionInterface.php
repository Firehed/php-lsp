<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Resolution\Expected;

interface ExpectedAtPositionInterface
{
    public function expectedAt(TextDocument $document, ByteOffset $offset): Expected;
}
