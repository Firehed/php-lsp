<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\Symbol;

/**
 * The read contract for symbol knowledge (RFC 1 §4.2, §5.1): every query for symbol
 * existence, metadata, and namespace enumeration is answered here, so that *where* a
 * symbol comes from is a backend concern (Section 5.3) with no change to consumers.
 *
 * Queries are FQN-based (RFC 1 §4.4): this takes an already-qualified name and never
 * a cursor position or a syntax tree. Turning "`Foo` in this namespace with these
 * imports" into a candidate FQN is the positional layer's job, not this interface's.
 *
 * Lookup is per-kind, because PHP's three symbol namespaces are independent: the
 * name type says which is meant, so no separate kind argument travels with it.
 * Constant lookup and a kind-parameterized search arrive with the slices that first
 * need them (Plan 0002 §5.2); a method with no caller is not carried ahead.
 */
interface SymbolSourceInterface
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
    public function lookupClassLike(ClasslikeName $name): ?ClassInfo;

    /**
     * Full metadata for a global constant by its exact name, or null when
     * nothing the source can reach declares it (RFC 1 §5.3).
     *
     * Reach is what a name can be resolved *through*: an open document, an
     * `autoload.files` entry, or a built-in. A constant in an unopened PSR-4
     * file has no name -> file route at all, which is Plan 0002 §3's
     * locate-only limitation rather than an absence.
     *
     * Covers `const` declarations and literal-name `define()` calls; a
     * computed-name `define()` is a runtime call invisible to static parse
     * (Plan 0002 §3).
     */
    public function lookupConstant(ConstantName $name): ?ConstantInfo;

    /**
     * Full metadata for a standalone function by its exact name, or null when
     * nothing the source can reach declares it (RFC 1 §5.3).
     *
     * Reach is what a name can be resolved *through*: an open document, an
     * `autoload.files` entry, or a built-in. A function in an unopened PSR-4 file
     * has no name -> file route at all, which is Plan 0002 §3's locate-only
     * limitation rather than an absence.
     */
    public function lookupFunction(FunctionName $name): ?FunctionInfo;

    /**
     * The symbols of $kind whose short name begins with $prefix. The prefix is
     * the partial fragment the user is typing, not a complete identifier, so a
     * bare string is correct here (Plan 0002 §5.3).
     *
     * @return list<Symbol>
     */
    public function search(string $prefix, NameKind $kind): array;
}
