<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\SignatureHelpResult;
use PHPUnit\Framework\Assert;

/**
 * The answer offers exactly this many signatures.
 */
final readonly class SignatureCount implements SignatureHelpExpectationInterface
{
    /**
     * @param positive-int $count
     */
    public function __construct(private int $count)
    {
    }

    public function checkSignatureHelp(SignatureHelpResult $result): void
    {
        Assert::assertSame($this->count, $result->count, "there are {$this->count} signatures");
    }
}
