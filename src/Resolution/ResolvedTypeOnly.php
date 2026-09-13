<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Domain\TypeInterface;

/**
 * A ResolvedSymbolInterface that carries only a type — used when an expression's value
 * type is known but there is no persistent symbol to point at (`$this`, an
 * arithmetic result, a pre-resolved fallback type on a synthetic node).
 *
 * @internal
 */
final readonly class ResolvedTypeOnly implements ResolvedSymbolInterface
{
    public function __construct(
        private TypeInterface $type,
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

    public function getType(): TypeInterface
    {
        return $this->type;
    }

    public function format(): string
    {
        return $this->type->format();
    }
}
