<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
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
 * Lookup is **kind-parameterized here and per-kind at the facade**, and the split is
 * deliberate (Plan 0002 §5.6). {@see SymbolSource} carries a typed method per kind
 * because RFC 1 §5.1 requires a concrete return type; a backend takes the kind as an
 * argument because the kind changes only the case rule
 * ({@see NameKind::normalize()}) and which factory builds the metadata — never how a
 * declaring file is found or how a namespace is listed. So a new kind is a name
 * type, an info type, and one factory case, rather than a method on every backend.
 * Do not re-derive a per-kind backend method from the facade's closed method set.
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
     * Full metadata for the symbol $name names *as a $kind*, or `null` when this
     * backend cannot reach such a declaration (RFC 1 §5.3: absence is a bare null).
     *
     * PHP's three symbol namespaces are independent, so one name may be both a
     * class and a function; $kind is what says which is meant.
     */
    public function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfo;

    /**
     * The class-likes this backend can enumerate whose short name begins with
     * $prefix. A backend with no affordable prefix enumeration returns an empty
     * list rather than walking its source (RFC 1 §5.3).
     *
     * A kind parameter arrives with S3.9a, which widens search the way this
     * interface's lookup is already widened.
     *
     * @return list<Symbol>
     */
    public function searchClassLikes(string $prefix): array;
}
