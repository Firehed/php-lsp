<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a class property.
 */
final readonly class PropertyInfo implements Formattable, MemberInfo
{
    use HasSymbolLocation;


    public function __construct(
        public PropertyName $name,
        public Visibility $visibility,
        public bool $isStatic,
        public bool $isReadonly,
        public bool $isPromoted,
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
        if ($this->isStatic) {
            $parts[] = 'static';
        }
        if ($this->isReadonly) {
            $parts[] = 'readonly';
        }
        if ($this->type !== null) {
            $parts[] = $this->type->format();
        }
        $parts[] = '$' . $this->name->name;
        return implode(' ', $parts);
    }

    public function getDeclaringClass(): ClassName
    {
        return $this->declaringClass;
    }

    public function getMemberKind(): MemberKind
    {
        return MemberKind::Property;
    }

    public function getName(): PropertyName
    {
        return $this->name;
    }

    public function getType(): ?Type
    {
        return $this->type;
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    public function isStatic(): bool
    {
        return $this->isStatic;
    }
}
