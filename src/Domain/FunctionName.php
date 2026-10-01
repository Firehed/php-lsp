<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The fully-qualified name of a standalone function: a
 * {@see QualifiedName} that carries its {@see NameKind} intrinsically, so a lookup
 * taking one needs no separate kind argument. `Foo\bar` names a different symbol as
 * a function than as a constant, and the type is what says which.
 */
final class FunctionName implements NamespaceOwnedNameInterface
{
    public NameKind $kind { get => NameKind::Function_; }

    public function __construct(
        public readonly QualifiedName $qualifiedName,
    ) {
    }

    public static function fromFullyQualified(string $fullyQualifiedName): self
    {
        return new self(QualifiedName::fromFullyQualified($fullyQualifiedName));
    }
}
