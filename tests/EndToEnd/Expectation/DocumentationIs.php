<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\SignatureHelpResult;
use PHPUnit\Framework\Assert;

/**
 * The active signature's documentation is exactly this.
 */
final readonly class DocumentationIs implements SignatureHelpExpectationInterface
{
    public function __construct(
        private string $documentation,
    ) {
    }

    public function checkSignatureHelp(SignatureHelpResult $result): void
    {
        $active = $result->active;
        Assert::assertNotNull($active, 'there is a signature');
        Assert::assertSame($this->documentation, $active->documentation, 'the documentation is as expected');
    }
}
