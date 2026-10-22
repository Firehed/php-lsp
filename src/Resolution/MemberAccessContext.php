<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\ResolvedMemberInterface;
use Firehed\PhpLsp\Domain\TypeInterface;

/**
 * A member access being written: what it is on, and the class-like the access
 * is written in ($from; null outside any class), which decides the members it
 * may use.
 */
final readonly class MemberAccessContext
{
    private function __construct(
        public TypeInterface $type,
        public ?ClasslikeName $from,
        public MemberAccessKind $kind,
        public string $prefix,
        public MemberFilter $memberFilter,
        public bool $offersClassConstant,
        private bool $methodsOnly,
    ) {
    }

    public static function forInstance(
        TypeInterface $type,
        ?ClasslikeName $from,
        string $prefix,
    ): self {
        return new self(
            $type,
            $from,
            MemberAccessKind::Instance,
            $prefix,
            MemberFilter::Instance,
            false,
            false,
        );
    }

    public static function forStatic(
        TypeInterface $type,
        ?ClasslikeName $from,
        string $prefix,
    ): self {
        return new self(
            $type,
            $from,
            MemberAccessKind::Static,
            $prefix,
            MemberFilter::Static,
            true,
            false,
        );
    }

    public static function forParent(
        TypeInterface $type,
        ?ClasslikeName $from,
        string $prefix,
    ): self {
        return new self(
            $type,
            $from,
            MemberAccessKind::Parent,
            $prefix,
            MemberFilter::All,
            false,
            true,
        );
    }

    public function accepts(ResolvedMemberInterface $member): bool
    {
        if ($this->methodsOnly && !$member->getMemberKind()->isMethod()) {
            return false;
        }
        return true;
    }
}
