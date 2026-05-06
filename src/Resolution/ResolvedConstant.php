<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\Visibility;

/**
 * A resolved class constant wrapping ConstantInfo.
 */
final readonly class ResolvedConstant implements ResolvedMember
{
    use ResolvesFromInfo;

    public function __construct(
        private ConstantInfo $info,
    ) {
    }

    public function getType(): ?Type
    {
        return $this->info->type;
    }

    public function getDeclaringClass(): ClassName
    {
        return $this->info->declaringClass;
    }

    public function getName(): ConstantName
    {
        return $this->info->name;
    }

    public function getVisibility(): Visibility
    {
        return $this->info->visibility;
    }

    public function isStatic(): bool
    {
        return true;
    }
}
