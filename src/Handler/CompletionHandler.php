<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Completion\CompletionSourceInterface;
use Firehed\PhpLsp\Document\DocumentManagerInterface;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\TextDocumentPositionParams;

/**
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class CompletionHandler implements DocumentFeatureHandlerInterface
{
    use SupportsOwnMethodTrait;

    // The widest position is a bare `\`: every root namespace plus every global
    // class-like. Cap the response and report isIncomplete so the client re-queries
    // as the prefix narrows, rather than shipping thousands of items.
    private const RESULT_LIMIT = 100;

    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
        private readonly CompletionSourceInterface $completionSource,
    ) {
    }

    public static function method(): string
    {
        return 'textDocument/completion';
    }

    /**
     * @return array{
     *   isIncomplete: bool,
     *   items: list<CompletionItem>,
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

        $items = $this->completionSource->find(new CompletionRequest($document, $position->line, $position->character))
            ?? [];

        return $this->capped($items);
    }

    /**
     * Cap the response, ranking before truncating so the cap keeps the best
     * candidates rather than whichever the sources happened to emit first. When
     * truncated, isIncomplete tells the client to re-query as the prefix narrows.
     *
     * The ranking invariant: only items that ask to be ordered carry a sortText —
     * namespace navigation, where a symbol must beat a node. Everything else
     * (members, variables, functions, flat classes) falls back to its label, i.e.
     * an alphabetical cap. That is deliberate: it is deterministic, and by ASCII a
     * PascalCase project symbol (`MyClass`) sorts ahead of a lowercase builtin
     * (`mysqli_connect`), so a user's own types survive the cap over the standard
     * library when a broad prefix matches both. The trade is that a >limit member
     * list is alphabetised rather than kept in resolution order — benign, and rare.
     *
     * @param list<CompletionItem> $items
     * @return array{isIncomplete: bool, items: list<CompletionItem>}
     */
    private function capped(array $items): array
    {
        if (count($items) <= self::RESULT_LIMIT) {
            return ['isIncomplete' => false, 'items' => $items];
        }

        usort(
            $items,
            static fn(array $a, array $b): int
                => ($a['sortText'] ?? $a['label']) <=> ($b['sortText'] ?? $b['label']),
        );

        return [
            'isIncomplete' => true,
            'items' => array_slice($items, 0, self::RESULT_LIMIT),
        ];
    }
}
