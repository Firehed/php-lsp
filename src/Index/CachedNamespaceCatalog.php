<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Cache\CacheKey;
use Firehed\PhpLsp\Cache\Invalidatable;
use Psr\SimpleCache\CacheInterface;

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
final class CachedNamespaceCatalog implements NamespaceCatalog, Invalidatable
{
    public function __construct(
        private readonly NamespaceCatalog $source,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Drop every cached listing so the next enumeration re-reads the source. Used
     * when a workspace file changed on disk (RFC 1 §5.3): a create or delete alters
     * a directory listing this has already memoized.
     */
    public function invalidate(): void
    {
        $this->cache->clear();
    }

    public function childrenOf(string $namespace): NamespaceContents
    {
        // PHP namespaces are case-insensitive, so `Psr\Log` and `psr\log` are one
        // namespace and must not be two cache entries.
        $key = CacheKey::from(strtolower($namespace));

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            assert($cached instanceof NamespaceContents);
            return $cached;
        }

        $contents = $this->source->childrenOf($namespace);
        $this->cache->set($key, $contents);

        return $contents;
    }
}
