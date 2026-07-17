<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

/**
 * Shared `textDocument/position` request parameters used by point-query
 * handlers (definition, hover, completion, signature help).
 */
final readonly class TextDocumentPositionParams
{
    public function __construct(
        public string $uri,
        public int $line,
        public int $character,
    ) {
    }

    public static function tryFromMessage(Message $message): ?self
    {
        $params = $message->params ?? [];

        $textDocument = $params['textDocument'] ?? [];
        if (!is_array($textDocument)) {
            return null;
        }
        $uri = $textDocument['uri'] ?? '';
        if (!is_string($uri)) {
            return null;
        }

        $position = $params['position'] ?? [];
        if (!is_array($position)) {
            return null;
        }
        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;
        if (!is_int($line) || !is_int($character)) {
            return null;
        }

        return new self($uri, $line, $character);
    }
}
