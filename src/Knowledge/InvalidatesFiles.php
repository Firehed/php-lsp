<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

/**
 * A backend that memoizes on-disk symbol data and can drop a single file's cached
 * entry when that file changes on disk (RFC 1 §5.2, §5.3): an external edit, a
 * branch checkout, a deletion, or closing a file that was edited in the editor.
 * The next query for that file re-reads from disk rather than serving the stale
 * pre-change value, and the pre-change value is not restored.
 *
 * A backend that does not cache from disk — open documents (the editor buffer is
 * authoritative) and built-ins — has nothing to invalidate and does not implement
 * this.
 */
interface InvalidatesFiles
{
    public function invalidate(string $uri): void;
}
