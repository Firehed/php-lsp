<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\CompletionList;
use PHPUnit\Framework\Assert;

/**
 * None of these labels is offered.
 */
final readonly class Withholds implements CompletionExpectationInterface
{
    /** @var list<string> */
    private array $labels;

    public function __construct(string ...$labels)
    {
        $this->labels = array_values($labels);
    }

    public function checkCompletion(CompletionList $completions): void
    {
        // A label left out of a capped list is not evidence it was withheld.
        Assert::assertFalse($completions->isIncomplete, 'the list is complete, so an absent label was withheld');
        foreach ($this->labels as $label) {
            Assert::assertNotContains($label, $completions->labels, "{$label} is withheld");
        }
    }
}
