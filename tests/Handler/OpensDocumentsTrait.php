<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Protocol\NotificationMessage;

/**
 * Provides document opening helper for handler tests.
 *
 * @property TextDocumentSyncHandler $syncHandler
 */
trait OpensDocumentsTrait
{
    private function openDocument(string $uri, string $code): void
    {
        $this->syncHandler->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => [
                'textDocument' => [
                    'uri' => $uri,
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $code,
                ],
            ],
        ]));
    }

    private function openFixture(string $fixturePath): string
    {
        $fullPath = dirname(__DIR__) . '/Fixtures/' . $fixturePath;
        $content = file_get_contents($fullPath);
        assert($content !== false, "Fixture not found: $fixturePath");

        $uri = 'file:///fixtures/' . $fixturePath;
        $this->openDocument($uri, $content);

        return $uri;
    }
}
