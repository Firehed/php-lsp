<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Client;

use Firehed\PhpLsp\Protocol\OutgoingRequest;
use Firehed\PhpLsp\Transport\TransportInterface;

/**
 * Sends server-initiated requests over the same transport the response path
 * writes to, so both share one output channel (RFC 1 §9 framing).
 *
 * Request ids are namespaced with a `server-` prefix and drawn from a monotonic
 * counter so they cannot collide with the client's own ids; correlating the
 * client's response is not needed today, so the id only has to be unique and keep
 * the frame well-formed.
 */
final class TransportClientConnection implements ClientConnection
{
    private int $nextId = 0;

    public function __construct(
        private readonly TransportInterface $transport,
    ) {
    }

    public function request(string $method, array $params): void
    {
        $this->nextId++;
        $this->transport->write(new OutgoingRequest('server-' . $this->nextId, $method, $params));
    }
}
