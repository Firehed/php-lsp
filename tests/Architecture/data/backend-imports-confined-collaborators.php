<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Repository\ClassRepository;

/**
 * The SymbolSource/SymbolSink backend package is where the collaborators legitimately
 * compose (RFC 1 §4.2, §5.3), so it may name them directly.
 */
final class BackendImportsConfinedCollaborators
{
    public function __construct(
        private readonly SymbolIndex $index,
        private readonly ClassRepository $classes,
    ) {
    }
}
