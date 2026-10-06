<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Result;

use PHPUnit\Framework\Assert;
use stdClass;

/**
 * One offered completion.
 */
final readonly class CompletionItem
{
    use DecodesDocumentationTrait;

    public function __construct(
        public string $label,
        public ?string $detail,
        public ?string $documentation,
    ) {
    }

    /**
     * Decodes an [LSP] CompletionItem.
     */
    public static function fromWire(mixed $wire): self
    {
        Assert::assertInstanceOf(stdClass::class, $wire, 'a CompletionItem is an object');
        $label = $wire->label ?? null;
        Assert::assertIsString($label, 'a CompletionItem has a label');
        $detail = $wire->detail ?? null;
        Assert::assertTrue($detail === null || is_string($detail), 'a CompletionItem detail is a string');

        return new self($label, $detail, self::documentationOf($wire->documentation ?? null));
    }
}
