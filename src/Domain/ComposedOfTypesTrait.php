<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Late binding for a type made of member types, such as a union or an
 * intersection, whose `$members` hold them.
 */
trait ComposedOfTypesTrait
{
    public function resolveLateBound(LateBinding $binding): TypeInterface
    {
        $resolved = array_map(
            static fn (TypeInterface $member): TypeInterface => $member->resolveLateBound($binding),
            $this->members,
        );
        return $resolved === $this->members ? $this : new self($resolved);
    }
}
