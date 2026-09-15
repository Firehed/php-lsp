<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\Container\TypedContainerInterface as TC;
use Psr\SimpleCache\CacheInterface;

return [
    NamespaceCatalogInterface::class => CachedNamespaceCatalog::class,

    CachedNamespaceCatalog::class => fn (TC $c) => new CachedNamespaceCatalog(
        $c->get(ReflectionNamespaceSource::class),
        $c->get(CacheInterface::class),
    ),

    PrefixSearchableInterface::class => ReflectionNamespaceSource::class,
    ReflectionNamespaceSource::class,
];
