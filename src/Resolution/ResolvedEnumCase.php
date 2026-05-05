<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\Type;

/**
 * A resolved enum case wrapping EnumCaseInfo.
 *
 * Note: Enum cases do not implement ResolvedMember because they don't have
 * visibility or static modifiers in PHP - they are implicitly public and
 * static-like by nature.
 */
final readonly class ResolvedEnumCase implements ResolvedSymbol
{
    use ResolvesFromInfo;

    public function __construct(
        private EnumCaseInfo $info,
    ) {
    }

    /**
     * Returns the declaring enum's ClassName as the type.
     */
    public function getType(): Type
    {
        return $this->info->declaringClass;
    }

    /**
     * Returns the class that declares this enum case.
     */
    public function getDeclaringClass(): ClassName
    {
        return $this->info->declaringClass;
    }
}
