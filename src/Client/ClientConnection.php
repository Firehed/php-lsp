<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Client;

/**
 * The channel for server-initiated messages to the client. Today its sole use is
 * dynamic capability registration (`client/registerCapability`); server-initiated
 * notifications (diagnostics) are the deferred scheduler tier (Plan 0002 Step 6).
 */
interface ClientConnection
{
    /**
     * Send a server-initiated request. The reply is not correlated — the read loop
     * drops it (RFC 1 §5.2) — because the effect applies client-side regardless.
     *
     * @param array<array-key, mixed> $params
     */
    public function request(string $method, array $params): void;
}
