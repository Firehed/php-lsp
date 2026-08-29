<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * A resolved symbol represents a code element that has been located and analyzed.
 * The metadata objects (ClassInfo, MethodInfo, etc.) implement this directly so
 * handlers do not branch on kind.
 */
interface ResolvedSymbol extends Formattable
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
}
