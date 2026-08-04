<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\QualifiedName;

/**
 * The function and constant names one file declares. Composer publishes no name ->
 * file map for either symbol namespace, so one is derived by parsing the
 * `autoload.files` set (Plan 0002 §3).
 *
 * Class-likes are out because the common case already has a cheaper route: PSR-4,
 * PSR-0 and the classmap turn a class name into a path by arithmetic, with no parse.
 * A class reachable only through a `files` entry has no such route and is missed —
 * the known gap Plan 0002 §3 records, tracked by #181.
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
