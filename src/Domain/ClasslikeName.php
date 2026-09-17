<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * A fully-qualified name intended to be a class-like. Not a `class-string`:
 * text-derived names (RFC 1 §5.3), fixtures, and forward references all produce a
 * `ClasslikeName` before any lookup, so the runtime existence of the class is a
 * separate question the resolution tier answers.
 */
final readonly class ClasslikeName
{
    public function __construct(
        public string $fqn,
    ) {
    }

    public function equals(self $other): bool
    {
        return NameKind::ClassLike->normalize(QualifiedName::fromClasslikeName($this))
            === NameKind::ClassLike->normalize(QualifiedName::fromClasslikeName($other));
    }

    public function namespace(): ?string
    {
        $namespace = NamespacePath::namespaceOf($this->fqn);

        return $namespace === '' ? null : $namespace;
    }

    public function shortName(): string
    {
        return NamespacePath::shortNameOf($this->fqn);
    }
}
