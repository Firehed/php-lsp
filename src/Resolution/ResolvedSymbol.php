<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Index\Location;

/**
 * A resolved symbol represents a code element that has been located and analyzed.
 * Implementations wrap domain objects (ClassInfo, MethodInfo, etc.) to provide
 * a uniform interface for handlers.
 */
interface ResolvedSymbol
{
    /**
     * Returns the source location where this symbol is defined, or null if unknown.
     */
    public function getDefinitionLocation(): ?Location;

    /**
     * Returns the docblock description (first paragraph), or null if none.
     */
    public function getDocumentation(): ?string;

    /**
     * Returns the symbol's type. For callables, this is the return type.
     */
    public function getType(): ?Type;

    /**
     * Returns a formatted signature suitable for display (e.g., hover tooltips).
     */
    public function format(): string;
}
