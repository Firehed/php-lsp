<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about an enum case.
 */
final readonly class EnumCaseInfo implements Formattable, MemberInfo
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

    public function format(): string
    {
        $str = 'case ' . $this->name->name;
        if ($this->backingValue !== null) {
            $str .= is_string($this->backingValue)
                ? " = '" . $this->backingValue . "'"
                : ' = ' . $this->backingValue;
        }
        return $str;
    }

    /**
     * An enum case cannot be given a visibility; it is always public.
     */
    public function getVisibility(): Visibility
    {
        return Visibility::Public;
    }

    /**
     * A case is reached on the enum, never on an instance.
     */
    public function isStatic(): bool
    {
        return true;
    }
}
