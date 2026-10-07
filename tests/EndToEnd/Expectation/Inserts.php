<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\CompletionItem;
use Firehed\PhpLsp\Tests\EndToEnd\Result\CompletionList;
use Firehed\PhpLsp\Tests\EndToEnd\Result\InsertTextFormat;
use PHPUnit\Framework\Assert;

/**
 * The item with this label is offered, and accepting it inserts exactly this
 * text in this format.
 */
final readonly class Inserts implements CompletionExpectationInterface
{
    public function __construct(
        private string $label,
        private string $text,
        private InsertTextFormat $format = InsertTextFormat::PlainText,
    ) {
    }

    public function checkCompletion(CompletionList $completions): void
    {
        Assert::assertSame(
            [[$this->text, $this->format]],
            array_map(
                static fn (CompletionItem $item): array => [$item->inserted, $item->insertTextFormat],
                $completions->itemsLabelled($this->label),
            ),
            "{$this->label} is offered once, inserting the expected text",
        );
    }
}
