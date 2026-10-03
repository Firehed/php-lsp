<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use RuntimeException;
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

        while (true) {
            $message = $this->server->readMessage();
            $this->transcript[] = ['received' => $message->body];
            if ($message->method === null && $message->id === $id) {
                return $message;
            }
            if ($message->method !== null && $message->id !== null) {
                $this->answer($message->id, $message->method);
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
     * Answers a request from the server as an editor that accepts it would.
     */
    private function answer(int|string $id, string $method): void
    {
        if ($method !== 'client/registerCapability') {
            throw new RuntimeException("No answer for the server's {$method} request.");
        }

        // [LSP] client/registerCapability: the result is null.
        $this->write(['id' => $id, 'result' => null]);
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

        $sent = json_decode($json, flags: JSON_THROW_ON_ERROR);
        assert($sent instanceof stdClass);
        self::elideText($sent);
        $this->transcript[] = ['sent' => $sent];
    }
}
