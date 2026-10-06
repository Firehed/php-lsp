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
     * @param list<string> $labels In the order the server sent them.
     */
    public function __construct(
        public bool $isIncomplete,
        public array $labels,
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
            return new self(false, self::labelsOf($result));
        }
        Assert::assertInstanceOf(stdClass::class, $result, 'a completion answer is a list, a CompletionList, or null');
        $isIncomplete = $result->isIncomplete ?? null;
        Assert::assertIsBool($isIncomplete, 'a CompletionList says whether it is incomplete');

        return new self($isIncomplete, self::labelsOf($result->items ?? null));
    }

    /**
     * @return list<string>
     */
    private static function labelsOf(mixed $items): array
    {
        Assert::assertIsList($items, 'completion items are a list');

        return array_map(static function (mixed $item): string {
            Assert::assertInstanceOf(stdClass::class, $item, 'a CompletionItem is an object');
            $label = $item->label ?? null;
            Assert::assertIsString($label, 'a CompletionItem has a label');

            return $label;
        }, $items);
    }
}
