<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Type-safe wrapper for enum case names.
 */
final readonly class EnumCaseName
{
    public function __construct(
        public string $name,
    ) {
    }
}
