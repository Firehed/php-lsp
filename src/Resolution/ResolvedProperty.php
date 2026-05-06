<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\Visibility;

/**
 * A resolved property wrapping PropertyInfo.
 */
final readonly class ResolvedProperty implements ResolvedMember
{
    use ResolvesFromInfo;

    public function __construct(
        private PropertyInfo $info,
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

    public function getName(): PropertyName
    {
        return $this->info->name;
    }

    public function getVisibility(): Visibility
    {
        return $this->info->visibility;
    }

    public function isStatic(): bool
    {
        return $this->info->isStatic;
    }
}
