<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\CompletionList;

/**
 * Something a completion answer must satisfy. A failure is a PHPUnit
 * assertion failure.
 */
interface CompletionExpectationInterface
{
    public function checkCompletion(CompletionList $completions): void;
}
