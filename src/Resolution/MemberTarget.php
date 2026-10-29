<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\TypeInterface;

/**
 * What a member access is looked up on: the type it is on, the class-like the
 * access is written in ($from; null outside any class), and how it is written.
 */
final readonly class MemberTarget
{
    public function __construct(
        public TypeInterface $type,
        public ?ClasslikeName $from,
        public MemberAccessKind $kind,
    ) {
    }

    /**
     * The class `static` names when it is not the class accessed: a
     * `parent::` access passes on the class it is written in (PHP manual,
     * Late Static Bindings).
     */
    public function static(): ?ClasslikeName
    {
        return $this->kind === MemberAccessKind::Parent ? $this->from : null;
    }
}
