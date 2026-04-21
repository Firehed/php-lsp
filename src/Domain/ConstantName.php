<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Type-safe wrapper for class constant names.
 */
final readonly class ConstantName
{
    public function __construct(
        public string $name,
    ) {
    }
}
