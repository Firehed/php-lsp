<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a class-like member, in the terms the hierarchy walk needs:
 * whether the caller may see it, and whether it is reached on the class or on an
 * instance. Everything else about a member is specific to its {@see MemberKind}.
 */
interface MemberInfo extends ResolvedMember
{
}
