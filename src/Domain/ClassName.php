<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * A fully-qualified name intended to be a class-like. Not a `class-string`:
 * text-derived names (RFC 1 §5.3), fixtures, and forward references all produce a
 * `ClassName` before any lookup, so the runtime existence of the class is a
 * separate question the resolution tier answers.
 */
final readonly class ClassName implements Type
{
    /**
     * @param list<Type> $typeArguments
     */
    public function __construct(
        public string $fqn,
        /** @phpstan-ignore property.onlyWritten (for future generics support) */
        private array $typeArguments = [],
    ) {
    }

    public function format(): string
    {
        return $this->fqn;
    }

    /**
     * @return list<ClassName>
     */
    public function getResolvableClassNames(): array
    {
        return [$this];
    }

    public function isNullable(): bool
    {
        return false;
    }

    public function shortName(): string
    {
        return NamespacePath::shortNameOf($this->fqn);
    }

    public function namespace(): ?string
    {
        $namespace = NamespacePath::namespaceOf($this->fqn);

        return $namespace === '' ? null : $namespace;
    }

    public function equals(self $other): bool
    {
        return NameKind::ClassLike->normalize(QualifiedName::fromClassName($this))
            === NameKind::ClassLike->normalize(QualifiedName::fromClassName($other));
    }

    public function resolveLateBound(string $callingClass, bool $declaringClassIsTrait = false): Type
    {
        return $this;
    }
}
