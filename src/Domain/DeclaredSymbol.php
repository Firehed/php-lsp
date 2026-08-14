<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Registration carries the kind rather than splitting into a parameter per kind, so
 * a new kind is a case in the info factories and not a signature change on every
 * write path (Plan 0002 §5.6).
 */
final readonly class DeclaredSymbol
{
    public function __construct(
        public QualifiedName $name,
        public NameKind $kind,
        public SymbolInfo $info,
    ) {
    }
}
