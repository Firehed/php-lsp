<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Type-safe wrapper for fully-qualified class names.
 */
final readonly class ClassName implements Type
{
    /**
     * @param class-string $fqn
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
