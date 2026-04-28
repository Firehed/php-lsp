<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a class property.
 */
final readonly class PropertyInfo implements Formattable
{
    public function __construct(
        public PropertyName $name,
        public Visibility $visibility,
        public bool $isStatic,
        public bool $isReadonly,
        public bool $isPromoted,
        public ?string $type,
        public ?Type $typeInfo,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
        public ClassName $declaringClass,
    ) {
    }

    public function format(): string
    {
        $parts = [$this->visibility->format()];
        if ($this->isStatic) {
            $parts[] = 'static';
        }
        if ($this->isReadonly) {
            $parts[] = 'readonly';
        }
        if ($this->type !== null) {
            $parts[] = $this->type;
        }
        $parts[] = '$' . $this->name->name;
        return implode(' ', $parts);
    }
}
