<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about an enum case.
 */
final readonly class EnumCaseInfo
{
    public function __construct(
        public EnumCaseName $name,
        public int|string|null $backingValue,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
        public ClassName $declaringClass,
    ) {
    }
}
