<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Resolution\ResolvedSymbol;
use Firehed\PhpLsp\Resolution\SymbolResolver;

final class HoverHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly SymbolResolver $symbolResolver,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/hover';
    }

    /**
     * @return array{contents: string}|null
     */
    public function handle(Message $message): ?array
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

        $document = $this->documentManager->get($uri);
        if ($document === null) {
            return null;
        }

        $symbol = $this->symbolResolver->resolveAtPosition($document, $line, $character);
        if ($symbol === null) {
            return null;
        }

        return ['contents' => $this->formatHover($symbol)];
    }

    private function formatHover(ResolvedSymbol $symbol): string
    {
        $parts = [];

        $doc = $symbol->getDocumentation();
        if ($doc !== null) {
            $parts[] = $doc;
        }

        $parts[] = '```php' . "\n" . $symbol->format() . "\n```";

        return implode("\n\n", $parts);
    }
}
