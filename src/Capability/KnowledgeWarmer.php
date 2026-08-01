<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Capability;

use Firehed\PhpLsp\Cache\Warmable;

/**
 * Derives the symbol tier's on-demand state once the session is initialized, so the
 * cost lands before the user's first keystroke rather than inside the request that
 * happens to need it.
 *
 * `initialized` is the earliest point this can run: the project root — and so the
 * autoload configuration everything is derived from — is not known until the client
 * has sent `initialize`.
 *
 * This is latency, never correctness. Nothing downstream may assume it ran: an
 * unwarmed {@see Warmable} answers identically, deriving what it needs when asked
 * (Plan 0002 §1, lazy-first). That is also why it is ungated by any client
 * capability — there is nothing for a client to support or decline.
 */
final class KnowledgeWarmer implements InitializedListener
{
    public function __construct(
        private readonly Warmable $knowledge,
    ) {
    }

    public function onInitialized(SessionCapabilities $capabilities): void
    {
        $this->knowledge->warm();
    }
}
