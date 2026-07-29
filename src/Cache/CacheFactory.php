<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Cache;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Builds the default in-process PSR-16 cache for symbol data that is stable for
 * the life of the server.
 *
 * This is a composition helper, not a cache implementation: consumers depend on
 * {@see CacheInterface} and receive the backend from their composition root, so a
 * different policy (eviction, bounds, TTL) is a one-line swap here rather than a
 * change to any consumer.
 */
final class CacheFactory
{
    public static function inMemory(): CacheInterface
    {
        // ArrayAdapter's second argument disables its copy-on-read (named
        // `storeSerialized` in symfony/cache 7, `deepClone` in 8), so a hit
        // returns the cached instance rather than a clone.
        return new Psr16Cache(new ArrayAdapter(0, false));
    }
}
