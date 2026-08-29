<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\ResolvedMember;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\Visibility;

final readonly class MemberAccessContext
{
    private function __construct(
        public Type $type,
        public Visibility $minVisibility,
        public MemberAccessKind $kind,
        public string $prefix,
        public MemberFilter $memberFilter,
        public bool $offersClassConstant,
        private bool $methodsOnly,
    ) {
    }

    public static function forInstance(
        Type $type,
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
        Type $type,
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
        Type $type,
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

    public function accepts(ResolvedMember $member): bool
    {
        if ($this->methodsOnly && !$member->getMemberKind()->isMethod()) {
            return false;
        }
        return true;
    }
}
