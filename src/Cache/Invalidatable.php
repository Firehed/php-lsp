<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Cache;

/**
 * A component that memoizes an on-disk answer and can be told to drop what it
 * holds when the disk changes underneath it (RFC 1 §5.3).
 *
 * The signal carries no key: the caller knows a file changed, not which cached
 * namespace listings or entries derive from it, so invalidation is wholesale.
 * External file changes are infrequent (an edit, a branch checkout, a delete) and
 * the cache repopulates lazily, so dropping everything is cheaper to get right
 * than a per-entry reverse index would be. A component with nothing cached to drop
 * simply does not implement this.
 */
interface Invalidatable
{
    public function invalidate(): void;
}
