<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Knowledge\SymbolBackend;

/**
 * The queries every {@see SymbolBackend} answers, as an axis
 * {@see SymbolCoverageGridTest} crosses with {@see \Firehed\PhpLsp\Domain\NameKind}
 * to derive its columns.
 *
 * An enum rather than a list of strings so the grid's dispatch is exhaustive: a
 * query added to the interface without a probe fails to compile the match instead of
 * falling through a default arm that no cell would ever reach.
 */
enum GridQuery: string
{
    case ChildrenOf = 'childrenOf';

    case Lookup = 'lookup';

    case Search = 'search';
}
