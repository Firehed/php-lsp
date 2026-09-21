<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a class-owned constant.
 *
 * Class-owned means declared inside a class-like — a class, interface, trait,
 * or enum. The declaring class is carried on the {@see ClasslikeConstantName}
 * identity; the free-standing sibling is {@see ConstantInfo}.
 */
final readonly class ClasslikeConstantInfo implements MemberInfoInterface, SymbolInfoInterface
{
    use HasSymbolLocationTrait;

    public function __construct(
        public ClasslikeConstantName $name,
        public Visibility $visibility,
        public bool $isFinal,
        public ?TypeInterface $type,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
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

    public function getDeclaringClass(): ClasslikeName
    {
        return $this->name->owner;
    }

    public function getMemberKind(): MemberKind
    {
        return MemberKind::Constant;
    }

    public function getName(): ClasslikeOwnedNameInterface
    {
        return $this->name;
    }

    public function getType(): ?TypeInterface
    {
        return $this->type;
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    /**
     * A class constant is reached on the class, never on an instance.
     */
    public function isStatic(): bool
    {
        return true;
    }

    public function symbolKind(): SymbolKind
    {
        return SymbolKind::Constant;
    }
}
