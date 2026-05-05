<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\Type;

/**
 * A resolved function wrapping FunctionInfo.
 */
final readonly class ResolvedFunction implements ResolvedCallable
{
    use ResolvesFromInfo;
    use ResolvesCallableParameters;

    public function __construct(
        private FunctionInfo $info,
    ) {
    }

    public function getType(): ?Type
    {
        return $this->info->returnType;
    }
}
