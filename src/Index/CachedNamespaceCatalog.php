<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

/**
 * Remembers what a namespace contains, for sources whose answers cannot change.
 *
 * Completion re-queries on every keystroke, so without this, navigating into
 * `Psr\Http\Message\` would re-read the directory once per character typed.
 * Caching per namespace preserves laziness: an entry appears only for a
 * namespace someone actually looked at.
 *
 * Only wrap sources that are stable for the life of the process — `vendor/` and
 * the language's built-ins. The workspace is not one of them.
 */
final class CachedNamespaceCatalog implements NamespaceCatalog
{
    /** @var array<string, NamespaceContents> Lowercase namespace -> contents */
    private array $cache = [];

    public function __construct(
        private readonly NamespaceCatalog $source,
    ) {
    }

    public function childrenOf(string $namespace): NamespaceContents
    {
        // PHP namespaces are case-insensitive, so `Psr\Log` and `psr\log` are one
        // namespace and must not be two cache entries.
        return $this->cache[strtolower($namespace)] ??= $this->source->childrenOf($namespace);
    }
}
