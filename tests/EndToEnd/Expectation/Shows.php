<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\HoverContent;
use PHPUnit\Framework\Assert;

/**
 * The hover shows every one of these fragments.
 */
final readonly class Shows implements HoverExpectationInterface
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
            Assert::assertStringContainsString($fragment, $content->value, "the hover shows {$fragment}");
        }
    }
}
