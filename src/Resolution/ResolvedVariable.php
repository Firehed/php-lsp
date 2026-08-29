<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Index\Location;

/**
 * A resolved variable with its inferred type and, when known, the nearest
 * preceding binding site (parameter, assignment, foreach, catch, or long-
 * closure `use` clause). #301: variable JTD lands on the binding node.
 */
final readonly class ResolvedVariable implements ResolvedSymbol
{
    public function __construct(
        private string $name,
        private ?Type $type,
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

    public function getType(): ?Type
    {
        return $this->type;
    }

    /**
     * Returns the variable signature for display (e.g., "string $name").
     */
    public function format(): string
    {
        if ($this->type === null) {
            return '$' . $this->name;
        }
        return $this->type->format() . ' $' . $this->name;
    }
}
