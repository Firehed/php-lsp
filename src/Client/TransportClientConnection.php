<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Client;

use Firehed\PhpLsp\Protocol\OutgoingRequest;
use Firehed\PhpLsp\Transport\TransportInterface;

/**
 * Sends server-initiated requests over the same transport the response path uses.
 * Ids are namespaced (`server-N`) so they never collide with the client's own; the
 * reply is not correlated, so the id only has to be unique.
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
