<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;

/**
 * The read contract for symbol knowledge (RFC 1 §4.2, §5.1): every query for symbol
 * existence, metadata, and namespace enumeration is answered here, so that *where* a
 * symbol comes from is a backend concern (Section 5.3) with no change to consumers.
 *
 * Queries are FQN-based (RFC 1 §4.4): this takes an already-qualified name and never
 * a cursor position or a syntax tree. Turning "`Foo` in this namespace with these
 * imports" into a candidate FQN is the positional layer's job, not this interface's.
 *
 * The method set is the Step-2 subset the migrated features need — exact class-like
 * lookup, class-like prefix search, and namespace enumeration. Per-kind function and
 * constant lookup, and a kind-parameterized search, arrive with the features that
 * first need them (Plan 0002 §5.2); a method with no caller is not carried ahead.
 */
interface SymbolSource
{
    /**
     * Enumerate a namespace's immediate children — its child namespaces and the
     * symbols declared directly in it.
     */
    public function childrenOf(NamespaceName $namespace): NamespaceContents;

    /**
     * Full metadata for a class-like by its exact name, or null when nothing the
     * source can reach declares it (RFC 1 §5.3: absence is a bare null).
     */
    public function lookupClassLike(ClassName $name): ?ClassInfo;

    /**
     * The class-likes whose short name begins with $prefix. The prefix is the partial
     * fragment the user is typing, not a complete identifier, so a bare string is
     * correct here (Plan 0002 §5.3).
     *
     * @return list<Symbol>
     */
    public function searchClassLikes(string $prefix): array;
}
