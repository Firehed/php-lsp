<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Result;

use PHPUnit\Framework\Assert;
use stdClass;

/**
 * What a completion request offered.
 */
final readonly class CompletionList
{
    /**
     * @param list<CompletionItem> $items In the order the server sent them.
     */
    public function __construct(
        public bool $isIncomplete,
        public array $items,
    ) {
    }

    /**
     * Decodes [LSP] textDocument/completion: CompletionItem[] | CompletionList
     * | null. A bare list is complete.
     */
    public static function fromWire(mixed $result): self
    {
        if ($result === null) {
            return new self(false, []);
        }
        if (is_array($result)) {
            return new self(false, self::itemsOf($result));
        }
        Assert::assertInstanceOf(stdClass::class, $result, 'a completion answer is a list, a CompletionList, or null');
        $isIncomplete = $result->isIncomplete ?? null;
        Assert::assertIsBool($isIncomplete, 'a CompletionList says whether it is incomplete');

        return new self($isIncomplete, self::itemsOf($result->items ?? null));
    }

    /**
     * @return list<CompletionItem>
     */
    public function itemsLabelled(string $label): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (CompletionItem $item): bool => $item->label === $label,
        ));
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return array_map(static fn (CompletionItem $item): string => $item->label, $this->items);
    }

    /**
     * @return list<CompletionItem>
     */
    private static function itemsOf(mixed $items): array
    {
        Assert::assertIsList($items, 'completion items are a list');

        return array_map(CompletionItem::fromWire(...), $items);
    }
}
