<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;

/**
 * One source of symbol knowledge (RFC 1 §5.3): open documents, the workspace on
 * disk, a vendored dependency, or the language's built-ins. Every backend answers
 * the same queries, so *where* a symbol comes from is a matter of which backends
 * the {@see CompositeSymbolSource} composes — not a change to any consumer (§4.2).
 *
 * Backends are substitutable and signal absence rather than raising: a lookup that
 * nothing the backend can reach declares returns `null`, and an enumeration or
 * search a backend cannot answer returns an empty collection (RFC 1 §5.3). A
 * backend never returns a partial answer presented as complete.
 *
 * Precedence between backends — an open document overrides the workspace, a
 * vendored file, and the built-ins — is the composite's concern, not the
 * backend's: each answers only for its own source.
 *
 * Lookup is per-kind: PHP's symbol namespaces are independent, so one name may be
 * both a class and a function, and the query says which is meant. Constant lookup
 * and a kind-parameterized search arrive with the slices that first need them
 * (Plan 0002 §5.2); a method with no caller is not carried ahead.
 */
interface SymbolBackend
{
    /**
     * The immediate children of a namespace this backend can see — its child
     * namespaces and the symbols it declares directly. Empty when the backend
     * knows nothing under $namespace.
     */
    public function childrenOf(NamespaceName $namespace): NamespaceContents;

    /**
     * Full metadata for a class-like this backend declares, or `null` when it
     * cannot reach a declaration of $name (RFC 1 §5.3: absence is a bare null).
     */
    public function lookupClassLike(ClassName $name): ?ClassInfo;

    /**
     * Full metadata for a standalone function this backend declares, or `null` when
     * it cannot reach a declaration of $name (RFC 1 §5.3).
     */
    public function lookupFunction(FunctionName $name): ?FunctionInfo;

    /**
     * The class-likes this backend can enumerate whose short name begins with
     * $prefix. A backend with no affordable prefix enumeration returns an empty
     * list rather than walking its source (RFC 1 §5.3).
     *
     * @return list<Symbol>
     */
    public function searchClassLikes(string $prefix): array;
}
