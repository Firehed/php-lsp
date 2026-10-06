<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\CompletionList;
use PHPUnit\Framework\Assert;

/**
 * The item with this label is offered, and its detail is exactly this.
 */
final readonly class Details implements CompletionExpectationInterface
{
    public function __construct(
        private string $label,
        private string $detail,
    ) {
    }

    public function checkCompletion(CompletionList $completions): void
    {
        Assert::assertSame(
            [$this->detail],
            array_column($completions->itemsLabelled($this->label), 'detail'),
            "{$this->label} is offered once, with the expected detail",
        );
    }
}
