<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\CompletionList;
use PHPUnit\Framework\Assert;

/**
 * The list says more items match than it holds, so the client asks again as
 * the prefix narrows.
 */
final readonly class ReportsIncomplete implements CompletionExpectationInterface
{
    public function checkCompletion(CompletionList $completions): void
    {
        Assert::assertTrue($completions->isIncomplete, 'the list is reported incomplete');
    }
}
