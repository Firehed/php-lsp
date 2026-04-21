<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a class property.
 */
final readonly class PropertyInfo
{
    public function __construct(
        public PropertyName $name,
        public Visibility $visibility,
        public bool $isStatic,
        public bool $isReadonly,
        public bool $isPromoted,
        public ?string $type,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
        public ClassName $declaringClass,
    ) {
    }
}
