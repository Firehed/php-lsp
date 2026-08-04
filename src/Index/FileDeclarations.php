<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\QualifiedName;

/**
 * The function and constant names one file declares — the two symbol namespaces
 * Composer's autoload maps cannot address by name (Plan 0002 §3).
 *
 * Class-likes are absent by design: they already have a name -> file map, so nothing
 * has to scan a file to discover them.
 */
final readonly class FileDeclarations
{
    /**
     * @param list<QualifiedName> $functions
     * @param list<QualifiedName> $constants
     */
    public function __construct(
        public array $functions,
        public array $constants,
    ) {
    }
}
