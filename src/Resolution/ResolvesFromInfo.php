<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Utility\DocblockParser;

/**
 * Provides common ResolvedSymbol implementations for classes wrapping Info objects.
 *
 * Expects the using class to have a `$this->info` property with `file`, `line`,
 * `docblock` properties and a `format()` method.
 */
trait ResolvesFromInfo
{
    public function getDefinitionLocation(): ?Location
    {
        return Location::fromFileLine($this->info->file, $this->info->line);
    }

    public function getDocumentation(): ?string
    {
        if ($this->info->docblock === null) {
            return null;
        }
        return DocblockParser::extractDescription($this->info->docblock);
    }

    public function format(): string
    {
        return $this->info->format();
    }
}
