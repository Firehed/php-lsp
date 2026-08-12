<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Knowledge\SymbolBackend;

/**
 * The queries every {@see SymbolBackend} answers, crossed with
 * {@see \Firehed\PhpLsp\Domain\NameKind} to form {@see SymbolCoverageGridTest}'s
 * columns; an enum so a new query breaks the grid's match rather than falling
 * through a default.
 */
enum GridQuery: string
{
    case ChildrenOf = 'childrenOf';

    case Lookup = 'lookup';

    case Search = 'search';
}
