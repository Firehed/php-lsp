<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\ResolvedMemberInterface;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\Visibility;

final readonly class MemberAccessContext
{
    private function __construct(
        public TypeInterface $type,
        public Visibility $minVisibility,
        public MemberAccessKind $kind,
        public string $prefix,
        public MemberFilter $memberFilter,
        public bool $offersClassConstant,
        private bool $methodsOnly,
    ) {
    }

    public static function forInstance(
        TypeInterface $type,
        Visibility $minVisibility,
        string $prefix,
    ): self {
        return new self(
            $type,
            $minVisibility,
            MemberAccessKind::Instance,
            $prefix,
            MemberFilter::Instance,
            false,
            false,
        );
    }

    public static function forStatic(
        TypeInterface $type,
        Visibility $minVisibility,
        string $prefix,
    ): self {
        return new self(
            $type,
            $minVisibility,
            MemberAccessKind::Static,
            $prefix,
            MemberFilter::Static,
            true,
            false,
        );
    }

    public static function forParent(
        TypeInterface $type,
        Visibility $minVisibility,
        string $prefix,
    ): self {
        return new self(
            $type,
            $minVisibility,
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
