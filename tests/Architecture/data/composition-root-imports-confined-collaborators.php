<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

use Firehed\PhpLsp\Index\SymbolIndex;

/**
 * The composition root (Server) wires the concrete collaborators into the backend, so
 * the root namespace names them directly (RFC 1 §4.2).
 */
final class CompositionRootImportsConfinedCollaborators
{
    public function __construct(
        private readonly SymbolIndex $index,
    ) {
    }
}
