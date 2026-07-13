<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

/**
 * How a symbol is written at a particular cursor: the shortest form that
 * resolves back to it, plus why.
 *
 * When the symbol is not reachable, the text is its fully qualified form with a
 * leading separator — the reference that would work if qualified.
 */
final readonly class Reference
{
    public function __construct(
        public string $text,
        public ReferenceKind $kind,
    ) {
    }

    public function isReachable(): bool
    {
        return $this->kind !== ReferenceKind::Unreachable;
    }
}
