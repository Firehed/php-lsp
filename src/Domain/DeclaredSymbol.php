<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Registration carries the kind rather than splitting into a parameter per kind, so
 * a new kind is a case in the info factories and not a signature change on every
 * write path.
 */
final readonly class DeclaredSymbol
{
    public function __construct(
        public QualifiedName $name,
        public NameKind $kind,
        public SymbolInfoInterface $info,
    ) {
    }

    public function declares(QualifiedName $name, NameKind $kind): bool
    {
        return $this->kind->keyFor($this->name) === $kind->keyFor($name);
    }
}
