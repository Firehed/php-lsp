<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\QualifiedName;

/**
 * The function and constant names one file declares — the two symbol namespaces
 * Composer's autoload maps cannot address by name (Plan 0002 §3).
 *
 * Class-likes are absent because PSR-4, PSR-0 and the classmap address them by name.
 * A class declared in an `autoload.files` entry escapes all three — Composer never
 * scans those into the classmap — and is the known gap Plan 0002 §3 records.
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
