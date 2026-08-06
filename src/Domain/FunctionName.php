<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use Firehed\PhpLsp\Resolution\NameKind;

/**
 * The fully-qualified name of a standalone function (Plan 0002 §5.3): a
 * {@see QualifiedName} that carries its {@see NameKind} intrinsically, so a lookup
 * taking one needs no separate kind argument. `Foo\bar` names a different symbol as
 * a function than as a constant, and the type is what says which.
 */
final readonly class FunctionName
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
        return NameKind::Function_;
    }
}
