<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\CacheKey;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Psr\SimpleCache\CacheInterface;

/**
 * The replaceable seam a backend memoizes resolved symbols behind (RFC 1 §5.3), and
 * the one place the read-through is written — a backend supplies only how to resolve
 * a miss.
 *
 * A name alone does not identify a symbol: PHP's three symbol namespaces are
 * independent, so one file may declare both a class and a function called `Foo`.
 * The kind is therefore part of the key, not just of the query — without it a
 * cached class would be served to a function lookup.
 */
final readonly class SymbolCache
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function forget(QualifiedName $name, NameKind $kind): void
    {
        $this->cache->delete($this->keyFor($name, $kind));
    }

    /**
     * @param callable(): ?SymbolInfo $resolve Consulted only on a miss
     */
    public function remember(QualifiedName $name, NameKind $kind, callable $resolve): ?SymbolInfo
    {
        $key = $this->keyFor($name, $kind);

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            assert($cached instanceof SymbolInfo);
            return $cached;
        }

        $info = $resolve();
        if ($info !== null) {
            $this->cache->set($key, $info);
        }

        return $info;
    }

    private function keyFor(QualifiedName $name, NameKind $kind): string
    {
        return CacheKey::from($kind->keyFor($name));
    }
}
