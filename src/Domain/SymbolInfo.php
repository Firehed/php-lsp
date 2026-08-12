<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a symbol in one of PHP's three symbol namespaces (Plan 0002 §5.6).
 *
 * Not {@see Formattable}, which says a value renders itself rather than that it is a
 * symbol; the two sets only coincide today.
 */
interface SymbolInfo
{
}
