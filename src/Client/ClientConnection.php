<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Client;

/**
 * The channel for server-initiated messages to the client — the outbound
 * counterpart to request handling. Today its sole use is dynamic capability
 * registration (`client/registerCapability`, [LSP] Register Capability); the
 * scheduler / notification tier (Plan 0002 Step 6) will send server-initiated
 * notifications (e.g. `textDocument/publishDiagnostics`) through the same seam.
 */
interface ClientConnection
{
    /**
     * Send a server-initiated request to the client. The response is neither
     * awaited nor correlated — the read loop drops it (RFC 1 §5.2) — because the
     * effect is applied client-side regardless of the reply.
     *
     * @param array<array-key, mixed> $params
     */
    public function request(string $method, array $params): void;
}
