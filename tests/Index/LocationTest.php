<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Location::class)]
class LocationTest extends TestCase
{
    public function testConstruction(): void
    {
        $location = new Location(
            uri: 'file:///path/to/file.php',
            startLine: 10,
            startCharacter: 5,
            endLine: 10,
            endCharacter: 15,
        );

        self::assertSame('file:///path/to/file.php', $location->uri);
        self::assertSame(10, $location->startLine);
        self::assertSame(5, $location->startCharacter);
        self::assertSame(10, $location->endLine);
        self::assertSame(15, $location->endCharacter);
    }

    public function testToLspLocation(): void
    {
        $location = new Location(
            uri: 'file:///path/to/file.php',
            startLine: 10,
            startCharacter: 5,
            endLine: 10,
            endCharacter: 15,
        );

        $lsp = $location->toLspLocation();

        self::assertSame('file:///path/to/file.php', $lsp['uri']);
        self::assertSame(10, $lsp['range']['start']['line']);
        self::assertSame(5, $lsp['range']['start']['character']);
        self::assertSame(10, $lsp['range']['end']['line']);
        self::assertSame(15, $lsp['range']['end']['character']);
    }

    public function testFromFileLineWithValidPath(): void
    {
        $location = Location::fromFileLine('/path/to/file.php', 10);

        self::assertNotNull($location);
        self::assertSame('file:///path/to/file.php', $location->uri);
        self::assertSame(9, $location->startLine);
        self::assertSame(0, $location->startCharacter);
        self::assertSame(9, $location->endLine);
        self::assertSame(0, $location->endCharacter);
    }

    public function testFromFileLineWithAlreadyUri(): void
    {
        $location = Location::fromFileLine('file:///already/uri.php', 5);

        self::assertNotNull($location);
        self::assertSame('file:///already/uri.php', $location->uri);
        self::assertSame(4, $location->startLine);
    }

    public function testFromFileLineWithNullFile(): void
    {
        $location = Location::fromFileLine(null, 10);

        self::assertNull($location);
    }

    public function testFromFileLineWithNullLine(): void
    {
        $location = Location::fromFileLine('/path/to/file.php', null);

        self::assertNull($location);
    }

    public function testFromFileLineWithBothNull(): void
    {
        $location = Location::fromFileLine(null, null);

        self::assertNull($location);
    }
}
