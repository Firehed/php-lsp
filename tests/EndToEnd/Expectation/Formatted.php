<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\HoverContent;
use Firehed\PhpLsp\Tests\EndToEnd\Result\MarkupKind;
use PHPUnit\Framework\Assert;

/**
 * The hover is written in this markup.
 */
final readonly class Formatted implements HoverExpectationInterface
{
    public function __construct(private MarkupKind $kind)
    {
    }

    public function checkHover(?HoverContent $content): void
    {
        Assert::assertNotNull($content, 'there is a hover');
        Assert::assertSame($this->kind, $content->kind, "the hover is {$this->kind->value}");
    }
}
