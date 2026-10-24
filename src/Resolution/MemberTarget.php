<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\TypeInterface;

/**
 * What a member access is looked up on: the type it is on, the class-like the
 * access is written in ($from; null outside any class), and, for a `parent::`
 * access, the class `static` names ($static).
 */
final readonly class MemberTarget
{
    public function __construct(
        public TypeInterface $type,
        public ?ClasslikeName $from,
        public ?ClasslikeName $static,
    ) {
    }
}
