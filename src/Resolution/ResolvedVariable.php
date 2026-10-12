<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Domain\TypeInterface;

/**
 * A resolved variable with its inferred type and, when known, the nearest
 * preceding binding site (parameter, assignment, foreach, catch, or long-
 * closure `use` clause). #301: variable JTD lands on the binding node.
 */
final readonly class ResolvedVariable implements ResolvedSymbolInterface
{
    public function __construct(
        private string $name,
        private ?TypeInterface $type,
        private ?Location $definitionLocation = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDefinitionLocation(): ?Location
    {
        return $this->definitionLocation;
    }

    public function getDocumentation(): ?string
    {
        return null;
    }

    public function getType(): ?TypeInterface
    {
        return $this->type;
    }

    public function format(): string
    {
        return $this->type?->format() ?? 'mixed';
    }
}
