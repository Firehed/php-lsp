<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Index\Location;

/**
 * A resolved variable with its inferred type.
 *
 * Variables don't have persistent definition locations (they're assigned
 * inline), so getDefinitionLocation() always returns null.
 */
final readonly class ResolvedVariable implements ResolvedSymbol
{
    public function __construct(
        private string $name,
        private ?Type $type,
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
