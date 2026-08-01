<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Cache;

/**
 * Memoizes on-disk-derived data and can drop it when the file behind it changes
 * (RFC 1 §5.2, §5.3). A fine-grained holder evicts just that file's entries; a
 * coarse one (a directory-listing cache that cannot map a path back to a listing)
 * drops everything. Lives in the cache leaf so both `Knowledge` backends and
 * `Index` caches can implement it without a cross-dependency.
 */
interface Invalidatable
{
    public function invalidate(string $uri): void;
}
