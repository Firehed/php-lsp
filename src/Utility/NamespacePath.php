<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

/**
 * Operations on namespace and fully-qualified-name strings.
 *
 * Namespaces are case-insensitive in PHP, and comparisons here reflect that.
 * Nothing in this class knows what a symbol *is* — it is string manipulation on
 * segment boundaries, shared by name resolution and by symbol discovery.
 */
final class NamespacePath
{
    /**
     * Every ancestor of a namespace mapped to the child leading towards it:
     * `A\B\C` yields `'' => 'A'`, `'A' => 'A\B'`, `'A\B' => 'A\B\C'`.
     *
     * @return array<string, string>
     */
    public static function ancestors(string $namespace): array
    {
        if ($namespace === '') {
            return [];
        }

        $ancestors = [];
        $parent = '';

        foreach (explode('\\', $namespace) as $segment) {
            $child = self::join($parent, $segment);
            $ancestors[$parent] = $child;
            $parent = $child;
        }

        return $ancestors;
    }

    public static function equals(string $a, string $b): bool
    {
        return strcasecmp($a, $b) === 0;
    }

    public static function firstSegment(string $name): string
    {
        $separator = strpos($name, '\\');

        return $separator === false ? $name : substr($name, 0, $separator);
    }

    public static function join(string ...$parts): string
    {
        return implode('\\', array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    public static function namespaceOf(string $fullyQualifiedName): string
    {
        $separator = strrpos($fullyQualifiedName, '\\');

        return $separator === false ? '' : substr($fullyQualifiedName, 0, $separator);
    }

    /**
     * The portion of $namespace below $ancestor, or null when $ancestor does not
     * strictly contain it. Everything is below the global namespace.
     *
     * Matching is on segment boundaries, so `App\Models` is not inside
     * `App\Model`.
     */
    public static function relativeTo(string $namespace, string $ancestor): ?string
    {
        if (self::equals($namespace, $ancestor)) {
            return null;
        }

        if ($ancestor === '') {
            return $namespace;
        }

        $prefix = $ancestor . '\\';
        if (strncasecmp($namespace, $prefix, strlen($prefix)) !== 0) {
            return null;
        }

        return substr($namespace, strlen($prefix));
    }

    public static function shortNameOf(string $fullyQualifiedName): string
    {
        $separator = strrpos($fullyQualifiedName, '\\');

        return $separator === false
            ? $fullyQualifiedName
            : substr($fullyQualifiedName, $separator + 1);
    }
}
