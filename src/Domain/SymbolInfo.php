<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a symbol declared in one of PHP's three symbol namespaces, as
 * returned by a kind-parameterized backend lookup (Plan 0002 §5.6).
 *
 * {@see Formattable} is not reused for this: it says a value can render itself,
 * not that it is a symbol, and the two sets only happen to coincide today.
 */
interface SymbolInfo
{
}
