<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Protocol\RequestMessage;

/**
 * Shared LSP request builders for handler unit tests. Each handler asks its
 * own resolver interface a question about the same shape of input — a
 * textDocument/position — so the helpers are method-agnostic and each test
 * passes its handler's method in.
 */
trait BuildsHandlerRequestsTrait
{
    /**
     * @param array<string, mixed>|null $params Arbitrary params; null omits them.
     */
    private function request(string $method, ?array $params = null): RequestMessage
    {
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method];
        if ($params !== null) {
            $message['params'] = $params;
        }
        return RequestMessage::fromArray($message);
    }

    private function positionRequest(
        string $method,
        string $uri,
        int $line = 0,
        int $character = 0,
    ): RequestMessage {
        return $this->request($method, [
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $line, 'character' => $character],
        ]);
    }
}
