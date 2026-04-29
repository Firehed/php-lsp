<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a class constant.
 */
final readonly class ConstantInfo implements Formattable
{
    public function __construct(
        public ConstantName $name,
        public Visibility $visibility,
        public bool $isFinal,
        public ?Type $type,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
        public ClassName $declaringClass,
    ) {
    }

    public function format(): string
    {
        $parts = [$this->visibility->format()];
        if ($this->isFinal) {
            $parts[] = 'final';
        }
        $parts[] = 'const';
        if ($this->type !== null) {
            $parts[] = $this->type->format();
        }
        $parts[] = $this->name->name;
        return implode(' ', $parts);
    }
}
