<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Index\Location;

/**
 * A resolved parameter wrapping ParameterInfo.
 *
 * Parameters don't have persistent definition locations (they're defined
 * inline in function/method signatures), so getDefinitionLocation() returns null.
 */
final readonly class ResolvedParameter implements ResolvedSymbol
{
    public function __construct(
        private ParameterInfo $info,
    ) {
    }

    public function getDefinitionLocation(): ?Location
    {
        return null;
    }

    public function getDocumentation(): ?string
    {
        return null;
    }

    public function getType(): ?Type
    {
        return $this->info->type;
    }

    public function format(): string
    {
        return $this->info->format();
    }
}
