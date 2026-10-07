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
        public ?string $sortText,
        public string $inserted,
        public InsertTextFormat $insertTextFormat,
    ) {
    }

    /**
     * Decodes an [LSP] CompletionItem. What the client inserts is the text
     * edit's new text, else insertText, else the label: an edit makes insertText
     * ignored, and an omitted insertText means the label. The format applies to
     * either, and defaults to plain text.
     */
    public static function fromWire(mixed $wire): self
    {
        Assert::assertInstanceOf(stdClass::class, $wire, 'a CompletionItem is an object');
        $label = $wire->label ?? null;
        Assert::assertIsString($label, 'a CompletionItem has a label');
        $detail = $wire->detail ?? null;
        Assert::assertTrue($detail === null || is_string($detail), 'a CompletionItem detail is a string');
        $sortText = $wire->sortText ?? null;
        Assert::assertTrue($sortText === null || is_string($sortText), 'a CompletionItem sortText is a string');
        $edit = $wire->textEdit ?? null;
        Assert::assertTrue($edit === null || $edit instanceof stdClass, 'a CompletionItem textEdit is an object');
        $inserted = $edit === null ? ($wire->insertText ?? $label) : ($edit->newText ?? null);
        Assert::assertIsString($inserted, 'a CompletionItem inserts a string');
        $formatValue = $wire->insertTextFormat ?? InsertTextFormat::PlainText->value;
        Assert::assertIsInt($formatValue, 'a CompletionItem insertTextFormat is an integer');
        $format = InsertTextFormat::tryFrom($formatValue);
        Assert::assertNotNull($format, 'a CompletionItem insertTextFormat is an InsertTextFormat');

        return new self(
            $label,
            $detail,
            self::documentationOf($wire->documentation ?? null),
            $sortText,
            $inserted,
            $format,
        );
    }

    /**
     * [LSP] CompletionItem.sortText: when omitted, the label is used.
     */
    public function sortKey(): string
    {
        return $this->sortText ?? $this->label;
    }
}
