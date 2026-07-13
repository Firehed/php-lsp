<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

/**
 * The name-resolution context at a cursor: the enclosing namespace and the
 * import tables in effect.
 *
 * PHP keeps three separate import tables (`use`, `use function`, `use const`),
 * and consults a different one depending on the kind of the name being
 * resolved — so they cannot be collapsed into a single map. See
 * {@see ReferenceResolver} for how each is used.
 *
 * Each table maps the short name (or alias) to the fully qualified name it
 * binds, without a leading separator.
 */
final readonly class NameContext
{
    /**
     * @param array<string, string> $classImports
     * @param array<string, string> $functionImports
     * @param array<string, string> $constantImports
     */
    public function __construct(
        public string $namespace,
        public array $classImports = [],
        public array $functionImports = [],
        public array $constantImports = [],
    ) {
    }

    /**
     * The import table consulted for an *unqualified* name of the given kind.
     *
     * PHP manual, name resolution rule 5.
     *
     * @return array<string, string>
     */
    public function importsFor(NameKind $kind): array
    {
        return match ($kind) {
            NameKind::ClassLike => $this->classImports,
            NameKind::Constant => $this->constantImports,
            NameKind::Function_ => $this->functionImports,
        };
    }
}
