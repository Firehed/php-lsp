<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Protocol\RequestMessage;

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

    /**
     * Opens a fixture and returns the cursor position for the named marker.
     *
     * @return array{uri: string, line: int, character: int}
     */
    private function openFixtureAtCursor(string $fixturePath, string $cursorName): array
    {
        $fullPath = dirname(__DIR__) . '/Fixtures/' . $fixturePath;
        $content = file_get_contents($fullPath);
        assert($content !== false, "Fixture not found: $fixturePath");

        $uri = 'file:///fixtures/' . $fixturePath;
        $this->openDocument($uri, $content);

        $marker = "/*|{$cursorName}*/";
        $pos = strpos($content, $marker);
        assert($pos !== false, "Cursor marker not found: $cursorName in $fixturePath");

        $beforeMarker = substr($content, 0, $pos);
        $lines = explode("\n", $beforeMarker);
        $line = count($lines) - 1;
        $character = strlen($lines[$line]);

        return [
            'uri' => $uri,
            'line' => $line,
            'character' => $character,
        ];
    }

    /**
     * @param array{uri: string, line: int, character: int} $cursor
     */
    private function completionRequestAt(array $cursor): RequestMessage
    {
        return RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => $cursor['uri']],
                'position' => ['line' => $cursor['line'], 'character' => $cursor['character']],
            ],
        ]);
    }
}
