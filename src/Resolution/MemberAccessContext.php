<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\ResolvedMemberInterface;

/**
 * A member access being written, and the part of its member's name typed so
 * far. The kind of access decides which members it offers.
 */
final readonly class MemberAccessContext
{
    public function __construct(
        public MemberAccess $access,
        public string $prefix,
    ) {
    }

    public function memberFilter(): MemberFilter
    {
        return match ($this->access->kind) {
            MemberAccessKind::Instance => MemberFilter::Instance,
            MemberAccessKind::Static => MemberFilter::Static,
            MemberAccessKind::Parent => MemberFilter::All,
        };
    }

    public function offersClassConstant(): bool
    {
        return $this->access->kind === MemberAccessKind::Static;
    }

    public function accepts(ResolvedMemberInterface $member): bool
    {
        return $this->access->kind !== MemberAccessKind::Parent || $member->getMemberKind()->isMethod();
    }
}
