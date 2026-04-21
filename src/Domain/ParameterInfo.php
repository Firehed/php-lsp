<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a method or function parameter.
 */
final readonly class ParameterInfo
{
    public function __construct(
        public string $name,
        public ?string $type,
        public bool $hasDefault,
        public bool $isVariadic,
        public bool $isPassedByReference,
    ) {
    }
}
