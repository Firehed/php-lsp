<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

/**
 * The fully-qualified namespace path that {@see SymbolSource::childrenOf} enumerates,
 * as a typed identifier rather than a bare string (RFC 1 §5.1). The global namespace
 * is the empty path.
 *
 * A namespace is not a symbol: it has no declaration site and no {@see \Firehed\PhpLsp\Resolution\NameKind},
 * existing only because something is declared beneath it (RFC 1 §5.1; Plan 0002 §5.6).
 * So this carries the path alone.
 */
final readonly class NamespaceName
{
    public function __construct(
        public string $path,
    ) {
    }
}
