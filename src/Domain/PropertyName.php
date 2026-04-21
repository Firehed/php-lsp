<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Type-safe wrapper for property names.
 */
final readonly class PropertyName
{
    public function __construct(
        public string $name,
    ) {
    }
}
