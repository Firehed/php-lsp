<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Parser\DocblockParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocblockParser::class)]
final class DocblockParserTest extends TestCase
{
    public function testExtractDescriptionStopsAtFirstTag(): void
    {
        $docblock = "/**\n * Line one.\n * Line two.\n * @var string\n */";
        self::assertSame("Line one.\nLine two.", DocblockParser::extractDescription($docblock));
    }

    public function testExtractDescriptionReturnsEmptyWhenOnlyTags(): void
    {
        self::assertSame('', DocblockParser::extractDescription('/** @var string */'));
    }
}
