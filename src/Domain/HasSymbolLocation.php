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

    public function getDocumentation(): ?string
    {
        if ($this->docblock === null) {
            return null;
        }
        return DocblockParser::extractDescription($this->docblock);
    }
}
