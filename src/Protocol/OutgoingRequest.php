<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

/**
 * A JSON-RPC request the server sends to the client, e.g.
 * `client/registerCapability` ([LSP] Register Capability). It carries an id so the
 * client's response can be correlated; the server does not correlate responses
 * today, so the id exists only to keep the frame well-formed and the reply
 * droppable (see {@see \Firehed\PhpLsp\Transport\MessageReader}).
 */
final readonly class OutgoingRequest implements OutgoingMessage
{
    /**
     * @param array<array-key, mixed> $params
     */
    public function __construct(
        private int|string $id,
        private string $method,
        private array $params,
    ) {
    }

    /**
     * @return array{jsonrpc: string, id: int|string, method: string, params: array<array-key, mixed>}
     */
    public function jsonSerialize(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $this->id,
            'method' => $this->method,
            'params' => $this->params,
        ];
    }
}
