<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\SignatureHelpResult;
use PHPUnit\Framework\Assert;

/**
 * The parameter being typed is this one.
 */
final readonly class ActiveParameter implements SignatureHelpExpectationInterface
{
    /**
     * @param int<0, max> $index 0-based, as the protocol counts parameters.
     */
    public function __construct(private int $index)
    {
    }

    public function checkSignatureHelp(SignatureHelpResult $result): void
    {
        $active = $result->active;
        Assert::assertNotNull($active, 'there is a signature');
        Assert::assertSame($this->index, $active->activeParameter, "parameter {$this->index} is active");
    }
}
