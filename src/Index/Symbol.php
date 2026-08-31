<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\NameKind;

final readonly class Symbol
{
    /**
     * @param ?NameKind $nameKind The name-resolution category, or null for
     *     kinds not addressable by a bare name (class members). Provided by the
     *     construction site, which knows both the LSP kind and the resolution
     *     category — this avoids re-deriving the mapping at every read.
     */
    public function __construct(
        public string $name,
        public string $fullyQualifiedName,
        public SymbolKind $kind,
        public Location $location,
        public ?string $containerName = null,
        public ?NameKind $nameKind = null,
    ) {
    }
}
