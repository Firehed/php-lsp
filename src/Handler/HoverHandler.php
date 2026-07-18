<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\TextDocumentPositionParams;
use Firehed\PhpLsp\Resolution\ResolvedSymbol;
use Firehed\PhpLsp\Resolution\CodeResolver;

final class HoverHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly CodeResolver $codeResolver,
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
        $position = TextDocumentPositionParams::tryFromMessage($message);
        if ($position === null) {
            return null;
        }

        $document = $this->documentManager->get($position->uri);
        if ($document === null) {
            return null;
        }

        $symbol = $this->codeResolver->resolveAtPosition($document, $position->line, $position->character);
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
