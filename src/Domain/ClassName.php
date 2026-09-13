<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * A fully-qualified name intended to be a class-like. Not a `class-string`:
 * text-derived names (RFC 1 §5.3), fixtures, and forward references all produce a
 * `ClassName` before any lookup, so the runtime existence of the class is a
 * separate question the resolution tier answers.
 */
final readonly class ClassName implements TypeInterface
{
    /**
     * @param list<TypeInterface> $typeArguments
     */
    public function __construct(
        public string $fqn,
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

    public function equals(TypeInterface $other): bool
    {
        if (!$other instanceof self) {
            return false;
        }
        $sameFqn = NameKind::ClassLike->normalize(QualifiedName::fromClassName($this))
            === NameKind::ClassLike->normalize(QualifiedName::fromClassName($other));
        if (!$sameFqn) {
            return false;
        }
        if (count($this->typeArguments) !== count($other->typeArguments)) {
            return false;
        }
        foreach ($this->typeArguments as $i => $arg) {
            if (!$arg->equals($other->typeArguments[$i])) {
                return false;
            }
        }
        return true;
    }

    public function resolveLateBound(string $callingClass, bool $declaringClassIsTrait = false): TypeInterface
    {
        return $this;
    }

    public function valueType(): ?TypeInterface
    {
        return $this->typeArguments[0] ?? null;
    }
}
