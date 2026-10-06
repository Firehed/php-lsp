<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\HoverContent;
use PHPUnit\Framework\Assert;

/**
 * There is a hover, and it shows none of these fragments.
 */
final readonly class Hides implements HoverExpectationInterface
{
    /** @var list<string> */
    private array $fragments;

    public function __construct(string ...$fragments)
    {
        $this->fragments = array_values($fragments);
    }

    public function checkHover(?HoverContent $content): void
    {
        Assert::assertNotNull($content, 'there is a hover');
        foreach ($this->fragments as $fragment) {
            Assert::assertStringNotContainsString($fragment, $content->value, "the hover does not show {$fragment}");
        }
    }
}
