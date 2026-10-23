<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a class-like member, in the terms the hierarchy walk needs:
 * whether the caller may see it, and whether it is reached on the class or on an
 * instance. Everything else about a member is specific to its {@see MemberKind}.
 */
interface MemberInfoInterface extends ResolvedMemberInterface
{
    /**
     * This member with `static`, `self`, and `parent` in its types replaced by
     * the classes the binding names, or this same instance when it has none.
     */
    public function resolveLateBound(LateBinding $binding): static;
}
