<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a symbol in one of PHP's three symbol namespaces.
 *
 * Not {@see FormattableInterface}, which says a value renders itself rather than that it is a
 * symbol; the two sets only coincide today.
 */
interface SymbolInfoInterface
{
}
