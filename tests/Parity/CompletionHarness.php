<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Protocol\NotificationMessage;

final readonly class CompletionHarness
{
    public function __construct(
        public CompletionHandler $handler,
        public TextDocumentSyncHandler $sync,
    ) {
    }

    public function openDocument(string $uri, string $content): void
    {
        $this->sync->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => [
                'textDocument' => [
                    'uri' => $uri,
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $content,
                ],
            ],
        ]));
    }

    public function changeDocument(string $uri, string $content): void
    {
        $this->sync->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didChange',
            'params' => [
                'textDocument' => ['uri' => $uri, 'version' => 2],
                'contentChanges' => [['text' => $content]],
            ],
        ]));
    }
}
