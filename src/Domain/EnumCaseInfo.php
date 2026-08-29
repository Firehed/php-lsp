<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about an enum case.
 */
final readonly class EnumCaseInfo implements MemberInfo
{
    use HasSymbolLocation;

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

    public function getDeclaringClass(): ClassName
    {
        return $this->declaringClass;
    }

    public function getMemberKind(): MemberKind
    {
        return MemberKind::EnumCase;
    }

    public function getName(): EnumCaseName
    {
        return $this->name;
    }

    /**
     * An enum case's value type is the enum itself: each case is a singleton
     * instance of the declaring enum.
     */
    public function getType(): ClassName
    {
        return $this->declaringClass;
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
