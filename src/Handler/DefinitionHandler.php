<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\TextDocumentPositionParams;
use Firehed\PhpLsp\Resolution\CodeResolver;

final class DefinitionHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly CodeResolver $codeResolver,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/definition';
    }

    /**
     * @return array{
     *   uri: string,
     *   range: array{
     *     start: array{line: int, character: int},
     *     end: array{line: int, character: int},
     *   },
     * }|null
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

        return $symbol?->getDefinitionLocation()?->toLspLocation();
    }
}
