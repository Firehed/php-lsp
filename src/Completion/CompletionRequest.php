<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;

/**
 * The position a completion source is asked about.
 */
final class CompletionRequest
{
    public readonly ByteOffset $offset;

    public function __construct(
        public readonly TextDocument $document,
        public readonly int $line,
        public readonly int $character,
    ) {
        $this->offset = $document->offsetAt($line, $character);
    }
}
