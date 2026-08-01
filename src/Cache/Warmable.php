<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Cache;

/**
 * Derives its on-disk-backed state on demand, and can be asked to do so ahead of
 * the first query that needs it.
 *
 * Warming is a latency concern, never a correctness one: an unwarmed implementation
 * MUST answer exactly as a warmed one does, deriving what it needs when asked. That
 * is what keeps the server lazy-first (Plan 0002 §1) — the eager pass is an
 * optimization layered over the on-demand path, not a precondition of it, so
 * nothing depends on when (or whether) it ran.
 *
 * Lives in the cache leaf alongside {@see Invalidatable} so both `Knowledge` and
 * `Index` can implement it without a cross-dependency.
 */
interface Warmable
{
    public function warm(): void;
}
