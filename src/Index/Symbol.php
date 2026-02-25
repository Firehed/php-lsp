<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

final readonly class Symbol
{
    public function __construct(
        public string $name,
        public string $fullyQualifiedName,
        public SymbolKind $kind,
        public Location $location,
        public ?string $containerName = null,
    ) {
    }
}
