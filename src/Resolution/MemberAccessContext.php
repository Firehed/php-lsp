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
    public MemberFilter $memberFilter;

    public bool $offersClassConstant;

    private bool $methodsOnly;

    public function __construct(
        public MemberAccess $access,
        public string $prefix,
    ) {
        $this->memberFilter = match ($access->kind) {
            MemberAccessKind::Instance => MemberFilter::Instance,
            MemberAccessKind::Static => MemberFilter::Static,
            MemberAccessKind::Parent => MemberFilter::All,
        };
        $this->offersClassConstant = $access->kind === MemberAccessKind::Static;
        $this->methodsOnly = $access->kind === MemberAccessKind::Parent;
    }

    public function accepts(ResolvedMemberInterface $member): bool
    {
        if ($this->methodsOnly && !$member->getMemberKind()->isMethod()) {
            return false;
        }
        return true;
    }
}
