<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\CompletionList;
use PHPUnit\Framework\Assert;

/**
 * Every one of these labels is offered, in any order.
 */
final readonly class Offers implements CompletionExpectationInterface
{
    /** @var list<string> */
    private array $labels;

    public function __construct(string ...$labels)
    {
        $this->labels = array_values($labels);
    }

    public function checkCompletion(CompletionList $completions): void
    {
        $offered = $completions->labels();
        foreach ($this->labels as $label) {
            Assert::assertContains($label, $offered, "{$label} is offered");
        }
    }
}
