<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\TypeInterface;

/**
 * A member access as written: the type it is on, the class-like it is written
 * in ($from; null outside any class), which decides the members it may use,
 * and the class `static` names when it is not the class accessed.
 */
final readonly class MemberAccess
{
    public function __construct(
        public TypeInterface $on,
        public ?ClasslikeName $from,
        public ?ClasslikeName $static,
        public MemberAccessKind $kind,
    ) {
    }
}
