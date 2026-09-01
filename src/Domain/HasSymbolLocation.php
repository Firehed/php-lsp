<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The ResolvedSymbol location and documentation methods, shared across every
 * *Info that carries a `$file`, `$line`, and `$docblock` field.
 */
trait HasSymbolLocation
{
    public function getDefinitionLocation(): ?Location
    {
        return Location::fromFileLine($this->file, $this->line);
    }

    /**
     * The raw docblock text with `@tag` lines intact. Display consumers strip
     * tags at render time; the step-30 presenter is where that lands.
     */
    public function getDocumentation(): ?string
    {
        return $this->docblock;
    }
}
