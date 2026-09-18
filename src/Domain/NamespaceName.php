<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The fully-qualified namespace path that {@see \Firehed\PhpLsp\Knowledge\SymbolSourceInterface::childrenOf}
 * enumerates, as a typed identifier rather than a bare string (RFC 1 §5.1). The global namespace
 * is the empty path.
 *
 * A namespace is not a symbol: it has no declaration site and no {@see \Firehed\PhpLsp\Domain\NameKind},
 * existing only because something is declared beneath it (RFC 1 §5.1; Plan 0002 §5.6).
 * So this carries the path alone.
 *
 * Namespaces are case-insensitive in PHP, and comparisons here reflect that.
 */
final readonly class NamespaceName
{
    public function __construct(
        public string $path,
    ) {
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

    public static function shortNameOf(string $fullyQualifiedName): string
    {
        $separator = strrpos($fullyQualifiedName, '\\');

        return $separator === false
            ? $fullyQualifiedName
            : substr($fullyQualifiedName, $separator + 1);
    }

    /**
     * Every ancestor of this namespace mapped to the child leading towards it:
     * `A\B\C` yields `'' => 'A'`, `'A' => 'A\B'`, `'A\B' => 'A\B\C'`.
     *
     * @return array<string, string>
     */
    public function ancestors(): array
    {
        if ($this->path === '') {
            return [];
        }

        $ancestors = [];
        $parent = '';

        foreach (explode('\\', $this->path) as $segment) {
            $child = self::join($parent, $segment);
            $ancestors[$parent] = $child;
            $parent = $child;
        }

        return $ancestors;
    }

    public function equals(NamespaceName $other): bool
    {
        return strcasecmp($this->path, $other->path) === 0;
    }

    /**
     * A namespace path as a lookup key. Paths are case-insensitive whatever kind
     * of symbol they qualify, so the rule is kind-independent — the per-kind
     * short-name rule lives in NameKind.
     */
    public function normalize(): string
    {
        return strtolower($this->path);
    }

    /**
     * The portion of this namespace below $ancestor, or null when $ancestor does
     * not strictly contain it. Everything is below the global namespace.
     *
     * Matching is on segment boundaries, so `App\Models` is not inside
     * `App\Model`.
     */
    public function relativeTo(NamespaceName $ancestor): ?string
    {
        if ($this->equals($ancestor)) {
            return null;
        }

        if ($ancestor->path === '') {
            return $this->path;
        }

        $prefix = $ancestor->path . '\\';
        if (strncasecmp($this->path, $prefix, strlen($prefix)) !== 0) {
            return null;
        }

        return substr($this->path, strlen($prefix));
    }
}
