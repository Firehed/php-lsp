<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Utility\DocblockParser;

/**
 * A resolved class, interface, trait, or enum wrapping ClassInfo.
 */
final readonly class ResolvedClass implements ResolvedSymbol
{
    public function __construct(
        private ClassInfo $info,
    ) {
    }

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

    /**
     * Returns the class name as a Type. This allows class references to be
     * typed in the same way as other symbols.
     */
    public function getType(): Type
    {
        return $this->info->name;
    }

    public function format(): string
    {
        return $this->info->format();
    }
}
