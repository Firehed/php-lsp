<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\Visibility;

/**
 * A resolved enum case wrapping EnumCaseInfo.
 *
 * Enum cases are effectively singleton constants: implicitly public and static.
 */
final readonly class ResolvedEnumCase implements ResolvedMember
{
    use ResolvesFromInfo;

    public function __construct(
        private EnumCaseInfo $info,
    ) {
    }

    public function getDeclaringClass(): ClassName
    {
        return $this->info->declaringClass;
    }

    public function getMemberKind(): MemberKind
    {
        return MemberKind::EnumCase;
    }

    public function getName(): EnumCaseName
    {
        return $this->info->name;
    }

    public function getType(): Type
    {
        return $this->info->declaringClass;
    }

    public function getVisibility(): Visibility
    {
        return $this->info->getVisibility();
    }

    public function isStatic(): bool
    {
        return $this->info->isStatic();
    }
}
