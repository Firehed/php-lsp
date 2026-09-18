<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The fully-qualified namespace path that {@see \Firehed\PhpLsp\Knowledge\SymbolSourceInterface::childrenOf}
 * enumerates, as a typed identifier rather than a bare string (RFC 1 §5.1). The global namespace
 * is the empty path.
 *
 * A namespace is not a symbol: it has no declaration site and no {@see \Firehed\PhpLsp\Domain\NameKind},
 * existing only because something is declared beneath it (RFC 1 §5.1; Plan 0002 §5.6).
 * So this carries the path alone.
 */
final readonly class NamespaceName
{
    public function __construct(
        public string $path,
    ) {
    }

    /**
     * Every ancestor of this namespace mapped to the child leading towards it:
     * `A\B\C` yields `'' => 'A'`, `'A' => 'A\B'`, `'A\B' => 'A\B\C'`.
     *
     * @return array<string, string>
     */
    public function ancestors(): array
    {
        if ($this->path === '') {
            return [];
        }

        $ancestors = [];
        $parent = '';

        foreach (explode('\\', $this->path) as $segment) {
            $child = NamespacePath::join($parent, $segment);
            $ancestors[$parent] = $child;
            $parent = $child;
        }

        return $ancestors;
    }
}
