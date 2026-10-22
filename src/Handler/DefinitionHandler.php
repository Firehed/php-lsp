<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentSourceInterface;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\TextDocumentPositionParams;
use Firehed\PhpLsp\Resolution\Position\SymbolAtPositionInterface;

final class DefinitionHandler implements DocumentFeatureHandlerInterface
{
    use SupportsOwnMethodTrait;

    public function __construct(
        private readonly DocumentSourceInterface $documents,
        private readonly SymbolAtPositionInterface $symbols,
    ) {
    }

    public static function method(): string
    {
        return 'textDocument/definition';
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

        $document = $this->documents->read($position->uri);
        if ($document === null) {
            return null;
        }

        $symbol = $this->symbols->symbolAt($document, $document->offsetAt($position->line, $position->character));

        return $symbol?->getDefinitionLocation()?->toLspLocation();
    }
}
