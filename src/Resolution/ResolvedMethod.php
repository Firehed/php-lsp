<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\Visibility;

/**
 * A resolved method wrapping MethodInfo.
 */
final readonly class ResolvedMethod implements ResolvedMember, ResolvedCallable
{
    use ResolvesFromInfo;
    use ResolvesCallableParameters;

    public function __construct(
        private MethodInfo $info,
    ) {
    }

    public function format(): string
    {
        return $this->info->format(showDefaults: true);
    }

    public function getType(): ?Type
    {
        return $this->info->returnType;
    }

    public function getDeclaringClass(): ClassName
    {
        return $this->info->declaringClass;
    }

    public function getName(): MethodName
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
