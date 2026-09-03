<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Domain\ResolvedSymbol;
use Firehed\PhpLsp\Protocol\MarkupContent;
use Firehed\PhpLsp\Protocol\MarkupKind;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\TextDocumentPositionParams;
use Firehed\PhpLsp\Resolution\CodeResolver;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;

/**
 * @phpstan-import-type LspMarkupContent from MarkupContent
 */
final class HoverHandler implements DocumentFeatureHandler
{
    use SupportsOwnMethod;

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly CodeResolver $codeResolver,
        private readonly SessionCapabilitiesProvider $capabilities,
    ) {
    }

    public static function method(): string
    {
        return 'textDocument/hover';
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
        $presented = ResolvedSymbolPresenter::present($symbol);
        $parts = [];

        if ($presented->documentation !== null) {
            $parts[] = $presented->documentation;
        }

        // A markdown client renders the signature as a fenced PHP block; a
        // plaintext client would show the fences literally, so give it the bare
        // signature instead.
        $parts[] = $kind === MarkupKind::Markdown
            ? '```php' . "\n" . $presented->signature . "\n```"
            : $presented->signature;

        return implode("\n\n", $parts);
    }
}
