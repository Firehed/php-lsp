<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\Type;

/**
 * A resolved global constant wrapping ConstantInfo.
 */
final readonly class ResolvedGlobalConstant implements ResolvedSymbol
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
}
