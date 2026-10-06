<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\CompletionList;
use PHPUnit\Framework\Assert;

/**
 * The item with this label is offered, and its documentation is exactly this.
 */
final readonly class Documents implements CompletionExpectationInterface
{
    public function __construct(
        private string $label,
        private string $documentation,
    ) {
    }

    public function checkCompletion(CompletionList $completions): void
    {
        Assert::assertSame(
            [$this->documentation],
            array_column($completions->itemsLabelled($this->label), 'documentation'),
            "{$this->label} is offered once, documented as expected",
        );
    }
}
