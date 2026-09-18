<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The fully-qualified name of a class-like (Plan 0002 §5.3): a {@see QualifiedName}
 * that carries its {@see NameKind} intrinsically. Not a `class-string`: text-derived
 * names (RFC 1 §5.3), fixtures, and forward references all produce a `ClasslikeName`
 * before any lookup, so the runtime existence of the class is a separate question
 * the resolution tier answers.
 */
final readonly class ClasslikeName implements NamespaceOwnedNameInterface
{
    public function __construct(
        public QualifiedName $qualifiedName,
    ) {
    }

    public static function fromFullyQualified(string $fullyQualifiedName): self
    {
        return new self(QualifiedName::fromFullyQualified($fullyQualifiedName));
    }

    public function equals(self $other): bool
    {
        return NameKind::ClassLike->normalize($this->qualifiedName)
            === NameKind::ClassLike->normalize($other->qualifiedName);
    }

    public function fullyQualifiedName(): string
    {
        return $this->qualifiedName->fullyQualifiedName();
    }

    public function kind(): NameKind
    {
        return NameKind::ClassLike;
    }
}
