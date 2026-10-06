<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\SignatureHelpResult;
use PHPUnit\Framework\Assert;

/**
 * The active signature's documentation shows every one of these fragments.
 */
final readonly class DocumentationShows implements SignatureHelpExpectationInterface
{
    /** @var list<string> */
    private array $fragments;

    public function __construct(string ...$fragments)
    {
        $this->fragments = array_values($fragments);
    }

    public function checkSignatureHelp(SignatureHelpResult $result): void
    {
        $active = $result->active;
        Assert::assertNotNull($active, 'there is a signature');
        Assert::assertNotNull($active->documentation, 'the signature is documented');
        foreach ($this->fragments as $fragment) {
            Assert::assertStringContainsString(
                $fragment,
                $active->documentation,
                "the documentation shows {$fragment}",
            );
        }
    }
}
