<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Protocol\RequestMessage;

/**
 * Provides document and fixture helpers for handler tests.
 *
 * Supports two workflows:
 * 1. Inline code: openDocument($uri, $code) for simple one-off tests
 * 2. Fixture files: openFixture() / openFixtureAtCursor() for reusable scenarios
 *
 * @phpstan-type CursorPosition array{uri: string, line: int, character: int}
 *
 * @property TextDocumentSyncHandler $syncHandler
 */
trait OpensDocumentsTrait
{
    /**
     * Opens inline code as a document.
     *
     * Prefer fixture files for new tests; use this for legacy tests or truly
     * one-off scenarios that don't warrant a fixture.
     */
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

    /**
     * Opens a fixture file as a document and returns its URI.
     *
     * Use for tests that need a complete file without cursor positioning,
     * or as the "definition" file in go-to-definition tests.
     *
     * @param string $fixturePath Path relative to tests/Fixtures/
     * @return string The document URI (e.g., 'file:///fixtures/src/Domain/User.php')
     */
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
     * Cursor markers in fixtures use the pattern: SLASH*|marker_name*SLASH
     * (where SLASH is /). The returned position is immediately before the marker.
     *
     * IMPORTANT: Each incomplete statement must be in its own method.
     * Multiple incomplete statements in one method confuse parser error recovery.
     *
     * @param string $fixturePath Path relative to tests/Fixtures/
     * @param string $cursorName The marker name (without delimiters)
     * @return CursorPosition
     * @phpstan-ignore missingType.iterableValue
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
     * Builds a textDocument/completion request for the given cursor position.
     *
     * Typically used with the return value of openFixtureAtCursor():
     *
     *     $cursor = $this->openFixtureAtCursor('Completion/MethodAccess.php', 'this_empty');
     *     $result = $this->handler->handle($this->completionRequestAt($cursor));
     *
     * @param CursorPosition $cursor From openFixtureAtCursor()
     * @phpstan-ignore missingType.iterableValue
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

    /**
     * Builds a textDocument/signatureHelp request for the given cursor position.
     *
     * @param CursorPosition $cursor From openFixtureAtCursor()
     * @phpstan-ignore missingType.iterableValue
     */
    private function signatureHelpRequestAt(array $cursor): RequestMessage
    {
        return RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/signatureHelp',
            'params' => [
                'textDocument' => ['uri' => $cursor['uri']],
                'position' => ['line' => $cursor['line'], 'character' => $cursor['character']],
            ],
        ]);
    }

    /**
     * Builds a textDocument/hover request for the given cursor position.
     *
     * @param CursorPosition $cursor From openFixtureAtCursor()
     * @phpstan-ignore missingType.iterableValue
     */
    private function hoverRequestAt(array $cursor): RequestMessage
    {
        return RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => $cursor['uri']],
                'position' => ['line' => $cursor['line'], 'character' => $cursor['character']],
            ],
        ]);
    }

    /**
     * Opens a fixture and returns cursor position ON a symbol for hover tests.
     *
     * Uses a marker comment at end of line: //hover:marker_name
     * Returns position of the last method/property access or function call on that line.
     *
     * @param string $fixturePath Path relative to tests/Fixtures/
     * @param string $markerName The marker name
     * @return CursorPosition
     * @phpstan-ignore missingType.iterableValue
     */
    private function openFixtureAtHoverMarker(string $fixturePath, string $markerName): array
    {
        $fullPath = dirname(__DIR__) . '/Fixtures/' . $fixturePath;
        $content = file_get_contents($fullPath);
        assert($content !== false, "Fixture not found: $fixturePath");

        $uri = 'file:///fixtures/' . $fixturePath;
        $this->openDocument($uri, $content);

        $marker = "//hover:$markerName";
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if (strpos($line, $marker) === false) {
                continue;
            }

            $symbolMatch = [];
            preg_match_all('/(?:->|\?->|::)\$?([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $symbolMatch, PREG_OFFSET_CAPTURE);

            if (count($symbolMatch[1]) > 0) {
                $lastMatch = end($symbolMatch[1]);
                return [
                    'uri' => $uri,
                    'line' => $lineNum,
                    'character' => $lastMatch[1],
                ];
            }

            $funcMatch = [];
            preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $line, $funcMatch, PREG_OFFSET_CAPTURE);
            assert(count($funcMatch[1]) > 0, "No callable found on line with marker '$markerName' in $fixturePath");

            $lastMatch = end($funcMatch[1]);
            return [
                'uri' => $uri,
                'line' => $lineNum,
                'character' => $lastMatch[1],
            ];
        }

        throw new \RuntimeException("Hover marker '$markerName' not found in $fixturePath");
    }
}
