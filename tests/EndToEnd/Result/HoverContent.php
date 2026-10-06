<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Result;

use PHPUnit\Framework\Assert;
use stdClass;

/**
 * What a hover shows.
 */
final readonly class HoverContent
{
    public function __construct(
        public MarkupKind $kind,
        public string $value,
    ) {
    }

    /**
     * Decodes [LSP] textDocument/hover: Hover | null. Of the forms `contents`
     * may take, only MarkupContent is read. The others are MarkedString,
     * which the specification deprecates, so an answer in that form fails.
     */
    public static function fromWire(mixed $result): ?self
    {
        if ($result === null) {
            return null;
        }
        Assert::assertInstanceOf(stdClass::class, $result, 'a hover answer is a Hover or null');
        $contents = $result->contents ?? null;
        Assert::assertInstanceOf(stdClass::class, $contents, 'Hover contents are MarkupContent');
        $kind = $contents->kind ?? null;
        Assert::assertIsString($kind, 'MarkupContent has a kind');
        $value = $contents->value ?? null;
        Assert::assertIsString($value, 'MarkupContent has a value');
        $markupKind = MarkupKind::tryFrom($kind);
        Assert::assertNotNull($markupKind, "{$kind} is a MarkupKind");

        return new self($markupKind, $value);
    }
}
