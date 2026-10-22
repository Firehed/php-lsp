<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassCandidateFilter;
use Firehed\PhpLsp\Domain\NameKind;

/**
 * The names a position admits: their kinds, which class-likes among them, and
 * how a name written there resolves.
 */
final readonly class NameQuery
{
    /**
     * @param list<NameKind> $kinds
     * @param bool $absolute Whether a name resolves from the global namespace
     *        whatever the context, as an import's does
     */
    public function __construct(
        public array $kinds,
        public ClassCandidateFilter $filter,
        public NameContext $context,
        public bool $absolute = false,
    ) {
    }
}
