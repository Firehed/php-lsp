<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\TypeInterface;

/**
 * A member access as written: the type it is on, and the class-like it is
 * written in ($from; null outside any class), which decides the members it
 * may use.
 */
final readonly class MemberAccess
{
    public function __construct(
        public TypeInterface $on,
        public ?ClasslikeName $from,
        public MemberAccessKind $kind,
    ) {
    }

    /**
     * The class `static` names when it is not the class accessed: a
     * `parent::` access passes on the class it is written in (PHP manual,
     * Late Static Bindings).
     */
    public function staticClass(): ?ClasslikeName
    {
        return $this->kind === MemberAccessKind::Parent ? $this->from : null;
    }
}
