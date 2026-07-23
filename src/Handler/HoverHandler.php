<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Protocol\MarkupContent;
use Firehed\PhpLsp\Protocol\MarkupKind;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\TextDocumentPositionParams;
use Firehed\PhpLsp\Resolution\ResolvedSymbol;
use Firehed\PhpLsp\Resolution\CodeResolver;

/**
 * @phpstan-import-type LspMarkupContent from MarkupContent
 */
final class HoverHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly CodeResolver $codeResolver,
        private readonly SessionCapabilitiesProvider $capabilities,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/hover';
    }

    /**
     * @return array{contents: LspMarkupContent}|null
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

        $kind = $this->capabilities->getSessionCapabilities()->hoverMarkupKind;

        return ['contents' => (new MarkupContent($kind, $this->formatHover($symbol, $kind)))->toArray()];
    }

    private function formatHover(ResolvedSymbol $symbol, MarkupKind $kind): string
    {
        $parts = [];

        $doc = $symbol->getDocumentation();
        if ($doc !== null) {
            $parts[] = $doc;
        }

        // A markdown client renders the signature as a fenced PHP block; a
        // plaintext client would show the fences literally, so give it the bare
        // signature instead.
        $signature = $symbol->format();
        $parts[] = $kind === MarkupKind::Markdown
            ? '```php' . "\n" . $signature . "\n```"
            : $signature;

        return implode("\n\n", $parts);
    }
}
