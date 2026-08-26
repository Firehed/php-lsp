<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\Visibility;

final readonly class MemberAccessContext
{
    /**
     * @codeCoverageIgnore
     */
    public function __construct(
        public Type $type,
        public Visibility $minVisibility,
        public MemberAccessKind $kind,
        public string $prefix,
        public MemberFilter $memberFilter = MemberFilter::Instance,
        public bool $offersClassConstant = false,
        private bool $methodsOnly = false,
    ) {
    }

    public static function forInstance(
        Type $type,
        Visibility $minVisibility,
        string $prefix,
    ): self {
        return new self($type, $minVisibility, MemberAccessKind::Instance, $prefix, MemberFilter::Instance);
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
            offersClassConstant: true,
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
            methodsOnly: true,
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
