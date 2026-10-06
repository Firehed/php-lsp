<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\CompletionItem;
use Firehed\PhpLsp\Tests\EndToEnd\Result\CompletionList;
use PHPUnit\Framework\Assert;

/**
 * Both labels are offered once, and the client sorts the first above the second.
 */
final readonly class RanksAbove implements CompletionExpectationInterface
{
    public function __construct(
        private string $higher,
        private string $lower,
    ) {
    }

    public function checkCompletion(CompletionList $completions): void
    {
        $higher = $this->onlyItem($completions, $this->higher)->sortKey();
        $lower = $this->onlyItem($completions, $this->lower)->sortKey();
        Assert::assertLessThan(0, strcmp($higher, $lower), "{$this->higher} sorts above {$this->lower}");
    }

    private function onlyItem(CompletionList $completions, string $label): CompletionItem
    {
        $items = $completions->itemsLabelled($label);
        Assert::assertCount(1, $items, "{$label} is offered once");

        return $items[0];
    }
}
