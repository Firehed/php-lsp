<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

interface TypeInterface extends FormattableInterface
{
    /**
     * @return list<ClasslikeName>
     */
    public function getResolvableClasslikeNames(): array;

    public function isNullable(): bool;

    /**
     * This type with `static`, `self`, and `parent` replaced by the classes the
     * binding names, or this same instance when it has none to replace.
     */
    public function resolveLateBound(LateBinding $binding): TypeInterface;

    public function equals(TypeInterface $other): bool;
}
