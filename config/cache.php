<?php

declare(strict_types=1);

use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

return [
    CacheInterface::class => Psr16Cache::class,
    Psr16Cache::class,
    CacheItemPoolInterface::class => ArrayAdapter::class,
    // TODO:
    // - Copy comment from CacheFactory
    // - Ensure actual single cache instance is ok (no key collisions)
    ArrayAdapter::class => fn () => new ArrayAdapter(0, false),
];
