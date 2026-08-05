<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\QualifiedName;

/**
 * The names one file declares, across all three of PHP's symbol namespaces.
 *
 * An `autoload.files` entry has no name -> file map of any kind, so the only way to
 * learn what it declares is to parse it — and once parsed, every kind costs the same
 * walk. Narrowing this to functions and constants would leave a class-like declared
 * there reachable at runtime but invisible here, for no saving (Plan 0002 §3).
 */
final readonly class FileDeclarations
{
    /**
     * @param list<QualifiedName> $classLikes
     * @param list<QualifiedName> $functions
     * @param list<QualifiedName> $constants
     */
    public function __construct(
        public array $classLikes,
        public array $functions,
        public array $constants,
    ) {
    }
}
