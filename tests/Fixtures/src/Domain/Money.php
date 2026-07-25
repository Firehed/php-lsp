<?php

declare(strict_types=1);

namespace Fixtures\Domain;

/**
 * A readonly value object, exercising the class-level `readonly` modifier that the
 * class-like lookup parity corpus otherwise never captures as true.
 */
final readonly class Money
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {
    }
}
