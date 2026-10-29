<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\ResolvedMemberInterface;

/**
 * A member access being written: what it is, and the part of the member's name
 * typed so far.
 */
final readonly class MemberAccessContext
{
    public function __construct(
        public MemberTarget $target,
        public string $prefix,
    ) {
    }

    public function memberFilter(): MemberFilter
    {
        return match ($this->target->kind) {
            MemberAccessKind::Instance => MemberFilter::Instance,
            MemberAccessKind::Static => MemberFilter::Static,
            MemberAccessKind::Parent => MemberFilter::All,
        };
    }

    public function offersClassConstant(): bool
    {
        return $this->target->kind === MemberAccessKind::Static;
    }

    public function accepts(ResolvedMemberInterface $member): bool
    {
        return $this->target->kind !== MemberAccessKind::Parent || $member->getMemberKind()->isMethod();
    }
}
