<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a class constant.
 */
final readonly class ConstantInfo
{
    public function __construct(
        public ConstantName $name,
        public Visibility $visibility,
        public bool $isFinal,
        public ?string $type,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
        public ClassName $declaringClass,
    ) {
    }
}
