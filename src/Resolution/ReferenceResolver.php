<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Utility\NamespacePath;

/**
 * Computes how a symbol must be written at a given cursor: the shortest
 * reference that resolves back to it, or that no unqualified reference does.
 *
 * This is the inverse of what the parser does. PhpParser's `NameResolver` turns
 * source text into an FQN; this turns an FQN back into the source text that
 * would produce it, under the namespace and imports in effect at the cursor.
 *
 * The rules are PHP's, from the manual's "Name resolution rules"
 * (language.namespaces.rules), applied shortest-first:
 *
 * 1. Declared in the current namespace → its last segment (rule 6).
 * 2. Bound by an import of its own kind → that alias (rule 5).
 * 3. Reached through an imported parent namespace → alias + remaining segments
 *    (rule 3 — which consults the *class* import table even when the symbol is
 *    a function or constant, because the resulting name is qualified).
 * 4. In a sub-namespace of the current one → a relative qualified name.
 * 5. A global function or constant → its last segment, via the runtime fallback
 *    (rule 7). Class-likes have no such fallback (rule 6).
 * 6. Otherwise unreachable: it needs a leading `\` or an added import.
 *
 * Each unqualified form is only offered when an import has not bound that name
 * to something else; an import always wins over the current namespace, so a
 * shadowed symbol is not reachable by its short name at all.
 *
 * The rules are ordered, and in one corner that is not the shortest form: an
 * import of an *ancestor of the cursor's own namespace* (`use App;` inside
 * `namespace App\Model;`) makes rule 3 fire with a longer result than rule 4
 * would give. Both resolve to the symbol; the import is redundant in the first
 * place, since everything it reaches is already reachable relatively.
 */
final class ReferenceResolver
{
    public static function resolve(string $fullyQualifiedName, NameKind $kind, NameContext $context): Reference
    {
        $fqn = ltrim($fullyQualifiedName, '\\');
        $namespace = NamespacePath::namespaceOf($fqn);
        $shortName = NamespacePath::shortNameOf($fqn);

        if (
            NamespacePath::equals($namespace, $context->namespace)
            && !self::isShadowed($shortName, $fqn, $context->importsFor($kind), $kind)
        ) {
            return new Reference($shortName, ReferenceKind::CurrentNamespace);
        }

        $alias = self::findImportOf($fqn, $context->importsFor($kind), $kind);
        if ($alias !== null) {
            return new Reference($alias, ReferenceKind::Import);
        }

        $viaPrefix = self::findPrefixImport($namespace, $context->classImports);
        if ($viaPrefix !== null) {
            [$prefixAlias, $remainder] = $viaPrefix;
            return new Reference(
                NamespacePath::join($prefixAlias, $remainder, $shortName),
                ReferenceKind::PrefixImport,
            );
        }

        $relative = self::relativeNamespace($namespace, $context->namespace);
        if (
            $relative !== null
            // The leading segment of a qualified name is bound by the class
            // import table (rule 3), so it matches on that table's terms —
            // case-insensitively — whatever the leaf symbol's kind.
            && !self::isShadowed(
                NamespacePath::firstSegment($relative),
                $fqn,
                $context->classImports,
                NameKind::ClassLike,
            )
        ) {
            return new Reference(
                NamespacePath::join($relative, $shortName),
                ReferenceKind::SubNamespace,
            );
        }

        if (
            $namespace === ''
            && $kind->fallsBackToGlobal()
            && !self::isShadowed($shortName, $fqn, $context->importsFor($kind), $kind)
        ) {
            return new Reference($shortName, ReferenceKind::GlobalFallback);
        }

        return new Reference('\\' . $fqn, ReferenceKind::Unreachable);
    }

    /**
     * Whether an import binds this name to some *other* symbol. Imports take
     * precedence over the current namespace and over the global fallback, so a
     * shadowed name cannot be used unqualified.
     *
     * @param array<string, string> $imports
     */
    private static function isShadowed(string $name, string $fqn, array $imports, NameKind $kind): bool
    {
        foreach ($imports as $alias => $target) {
            if (!self::namesMatch($alias, $name, $kind)) {
                continue;
            }
            return !self::namesMatch($target, $fqn, $kind);
        }

        return false;
    }

    /**
     * The alias under which an import binds exactly this symbol.
     *
     * @param array<string, string> $imports
     */
    private static function findImportOf(string $fqn, array $imports, NameKind $kind): ?string
    {
        foreach ($imports as $alias => $target) {
            if (self::namesMatch($target, $fqn, $kind)) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * The longest class import that names the symbol's namespace or an ancestor
     * of it, as [alias, remaining segments].
     *
     * @param array<string, string> $classImports
     * @return array{string, string}|null
     */
    private static function findPrefixImport(string $namespace, array $classImports): ?array
    {
        $best = null;

        foreach ($classImports as $alias => $target) {
            $remainder = self::relativeNamespace($namespace, $target, allowExact: true);
            if ($remainder === null) {
                continue;
            }
            if ($best !== null && strlen($target) <= $best[0]) {
                continue;
            }
            $best = [strlen($target), $alias, $remainder];
        }

        return $best === null ? null : [$best[1], $best[2]];
    }

    /**
     * The portion of $namespace below $ancestor, or null when $ancestor does not
     * contain it. Matching is on segment boundaries, so `App\Models` is not
     * inside `App\Model`.
     */
    private static function relativeNamespace(string $namespace, string $ancestor, bool $allowExact = false): ?string
    {
        if ($allowExact && NamespacePath::equals($namespace, $ancestor)) {
            return '';
        }

        return NamespacePath::relativeTo($namespace, $ancestor);
    }

    /**
     * Namespaces are case-insensitive even for constants — only a constant's
     * final segment is case-sensitive.
     */
    private static function namesMatch(string $a, string $b, NameKind $kind): bool
    {
        if (!$kind->isCaseSensitive()) {
            return strcasecmp($a, $b) === 0;
        }

        return NamespacePath::equals(NamespacePath::namespaceOf($a), NamespacePath::namespaceOf($b))
            && NamespacePath::shortNameOf($a) === NamespacePath::shortNameOf($b);
    }
}
