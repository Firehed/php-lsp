<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\Location;

/**
 * A ResolvedSymbol that carries only a type — used when an expression's value
 * type is known but there is no persistent symbol to point at (`$this`, an
 * arithmetic result, a pre-resolved fallback type on a synthetic node).
 *
 * @internal
 */
final readonly class ResolvedTypeOnly implements ResolvedSymbol
{
    public function __construct(
        private Type $type,
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

    public function getType(): Type
    {
        return $this->type;
    }

    public function format(): string
    {
        return $this->type->format();
    }
}
