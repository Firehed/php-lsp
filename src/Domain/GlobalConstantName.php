<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The fully-qualified name of a global constant (Plan 0002 §5.3): a
 * {@see QualifiedName} that carries its {@see NameKind} intrinsically, so a lookup
 * taking one needs no separate kind argument. `Foo\BAR` names a different symbol as
 * a constant than as a class, and the type is what says which.
 *
 * Distinct from {@see ConstantName}, which is the unqualified name of a class
 * constant member.
 */
final readonly class GlobalConstantName
{
    public function __construct(
        public QualifiedName $qualifiedName,
    ) {
    }

    public static function fromFullyQualified(string $fullyQualifiedName): self
    {
        return new self(QualifiedName::fromFullyQualified($fullyQualifiedName));
    }

    public function fullyQualifiedName(): string
    {
        return $this->qualifiedName->fullyQualifiedName();
    }

    public function kind(): NameKind
    {
        return NameKind::Constant;
    }
}
