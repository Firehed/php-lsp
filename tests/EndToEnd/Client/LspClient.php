<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Client;

use stdClass;

final class LspClient
{
    /**
     * Requests and notifications the server sent on its own initiative.
     *
     * @var list<ServerMessage>
     */
    public private(set) array $received = [];

    private int $nextId = 1;

    public function __construct(private readonly ServerProcess $server)
    {
    }

    public function notify(string $method, ?stdClass $params = null): void
    {
        $this->send(['method' => $method], $params);
    }

    public function request(string $method, ?stdClass $params = null): ServerMessage
    {
        $id = $this->nextId++;
        $this->send(['id' => $id, 'method' => $method], $params);

        while (true) {
            $message = $this->server->readMessage();
            if ($message->method === null && $message->id === $id) {
                return $message;
            }
            $this->received[] = $message;
        }
    }

    /**
     * Answers a request the server sent.
     *
     * @param stdClass|list<mixed>|string|int|float|bool|null $result
     */
    public function respond(int|string $id, stdClass|array|string|int|float|bool|null $result): void
    {
        $this->write(['id' => $id, 'result' => $result]);
    }

    /**
     * @param array{id?: int, method: string} $message
     */
    private function send(array $message, ?stdClass $params): void
    {
        // [LSP] Base Protocol: `params` is omitted, not null, when a method takes none.
        if ($params !== null) {
            $message['params'] = $params;
        }
        $this->write($message);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function write(array $message): void
    {
        $json = json_encode(['jsonrpc' => '2.0', ...$message], JSON_THROW_ON_ERROR);
        $this->server->write('Content-Length: ' . strlen($json) . "\r\n\r\n" . $json);
    }
}
