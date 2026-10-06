<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\CompletionList;
use Firehed\PhpLsp\Tests\EndToEnd\Result\HoverContent;
use Firehed\PhpLsp\Tests\EndToEnd\Result\SignatureHelpResult;
use PHPUnit\Framework\Assert;

/**
 * The server has nothing to offer at this position.
 */
final readonly class NoAnswer implements
    CompletionExpectationInterface,
    DefinitionExpectationInterface,
    HoverExpectationInterface,
    SignatureHelpExpectationInterface
{
    public function checkCompletion(CompletionList $completions): void
    {
        Assert::assertSame([], $completions->labels(), 'nothing is offered');
    }

    public function checkDefinition(array $locations): void
    {
        Assert::assertSame([], $locations, 'there is no definition');
    }

    public function checkHover(?HoverContent $content): void
    {
        Assert::assertNull($content, 'there is no hover');
    }

    public function checkSignatureHelp(SignatureHelpResult $result): void
    {
        Assert::assertSame(0, $result->count, 'there is no signature');
    }
}
