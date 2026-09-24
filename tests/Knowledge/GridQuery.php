<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

/**
 * The queries every {@see \Firehed\PhpLsp\Knowledge\SymbolSourceInterface} answers,
 * crossed with {@see \Firehed\PhpLsp\Domain\NameKind} to form
 * {@see SymbolCoverageGridTest}'s columns.
 *
 * This is the grid's one listed axis: a query needs an argument list and a way to
 * read its answer, which no derivation supplies. So a query added to the interface
 * does not add cells on its own.
 */
enum GridQuery: string
{
    case ChildrenOf = 'childrenOf';

    case Lookup = 'lookup';

    case Search = 'search';
}
