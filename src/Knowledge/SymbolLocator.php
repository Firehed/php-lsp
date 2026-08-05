<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Resolution\NameKind;

/**
 * Resolves a qualified name to the file that declares it, for any of PHP's three
 * symbol namespaces (Plan 0002 §3b: `ClassLocator` generalized to a kind-agnostic
 * `SymbolLocator`).
 *
 * The kind is a parameter rather than a method per kind because the cross-products
 * §5.6 keeps closed are *consumers × kinds* and *backends × kinds*: a new caller
 * resolves through the same entry, whichever namespace it is asking about. It is not
 * redundant with the name, because {@see QualifiedName} is deliberately kind-neutral
 * — `Foo\bar` names a different symbol as a function than as a constant, and only
 * the syntactic position the caller read it from can say which.
 *
 * Absence is a bare `null` rather than an exception (RFC 1 §5.3). A name this
 * locator cannot reach is not an error: it may be a built-in, declared in an open
 * document, or not exist at all — all questions for a caller above it.
 */
interface SymbolLocator
{
    /**
     * @return ?string Absolute path to the file declaring $name, or null when this
     *         locator cannot reach a declaration of it
     */
    public function locate(QualifiedName $name, NameKind $kind): ?string;
}
