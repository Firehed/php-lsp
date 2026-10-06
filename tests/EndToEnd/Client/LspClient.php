<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Client;

use stdClass;

final class LspClient
{
    /**
     * Every message sent and received, in wire order. Document text sent is
     * replaced with `…`: `didOpen` and `didChange` carry whole files.
     *
     * @var list<array{sent: stdClass}|array{received: stdClass}>
     */
    public private(set) array $transcript = [];

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

        // Messages the server sends on its own initiative are passed over.
        while (true) {
            $message = $this->server->readMessage();
            $this->transcript[] = ['received' => $message->body];
            if ($message->method === null && $message->id === $id) {
                return $message;
            }
        }
    }

    /**
     * In what a client sends, `text` holds document content ([LSP]
     * TextDocumentItem, TextDocumentContentChangeEvent, DidSaveTextDocumentParams).
     */
    private static function elideText(mixed $decoded): void
    {
        if ($decoded instanceof stdClass && property_exists($decoded, 'text')) {
            $decoded->text = '…';
        }
        $members = $decoded instanceof stdClass ? get_object_vars($decoded) : $decoded;
        if (is_array($members)) {
            foreach ($members as $member) {
                self::elideText($member);
            }
        }
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
        $this->server->writeFrame($json);

        $sent = json_decode($json, flags: JSON_THROW_ON_ERROR);
        assert($sent instanceof stdClass);
        self::elideText($sent);
        $this->transcript[] = ['sent' => $sent];
    }
}
