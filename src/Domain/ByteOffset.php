<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * A position in a document's text, counted in bytes from its start: the one
 * encoding positions have inside the server.
 */
final readonly class ByteOffset
{
    public function __construct(
        public int $value,
    ) {
    }
}
