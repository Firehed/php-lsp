<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\PositionEncoding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PositionEncoding::class)]
class PositionEncodingTest extends TestCase
{
    public function testUtf16CarriesTheLspEncodingKind(): void
    {
        self::assertSame(
            'utf-16',
            PositionEncoding::Utf16->value,
            'the value is advertised verbatim as the ServerCapabilities positionEncoding',
        );
    }

    #[DataProvider('characterToByteCases')]
    public function testCharacterToByteOffset(string $line, int $character, int $expected): void
    {
        self::assertSame(
            $expected,
            PositionEncoding::Utf16->characterToByteOffset($line, $character),
            'a UTF-16 character column must map to the byte offset the AST uses internally',
        );
    }

    #[DataProvider('byteToCharacterCases')]
    public function testByteToCharacterOffset(string $line, int $byteOffset, int $expected): void
    {
        self::assertSame(
            $expected,
            PositionEncoding::Utf16->byteToCharacterOffset($line, $byteOffset),
            'an internal byte offset must map back to the UTF-16 column the client sent',
        );
    }

    /**
     * @codeCoverageIgnore
     *
     * @return iterable<string, array{string, int, int}>
     */
    public static function characterToByteCases(): iterable
    {
        yield 'ascii is identity' => ['abc', 2, 2];
        yield 'ascii start' => ['abc', 0, 0];
        yield 'column past end clamps to byte length' => ['abc', 5, 3];
        yield 'bmp multibyte counts one unit, two bytes' => ['é', 1, 2];
        yield 'column before a multibyte char' => ['aéb', 1, 1];
        yield 'column after a multibyte char' => ['aéb', 2, 3];
        yield 'column at end after multibyte' => ['aéb', 3, 4];
        yield 'astral char is two units, four bytes' => ['😀', 2, 4];
        yield 'column inside a surrogate pair rounds up' => ['😀', 1, 4];
        yield 'column after an astral char' => ['a😀b', 3, 5];
        yield 'column at end after astral' => ['a😀b', 4, 6];
    }

    /**
     * @codeCoverageIgnore
     *
     * @return iterable<string, array{string, int, int}>
     */
    public static function byteToCharacterCases(): iterable
    {
        yield 'ascii is identity' => ['abc', 2, 2];
        yield 'ascii start' => ['abc', 0, 0];
        yield 'byte before a multibyte char' => ['aéb', 1, 1];
        yield 'byte after a multibyte char' => ['aéb', 3, 2];
        yield 'byte inside a multibyte char rounds up' => ['aéb', 2, 2];
        yield 'byte at end after multibyte' => ['aéb', 4, 3];
        yield 'astral char is two units' => ['😀', 4, 2];
        yield 'byte after an astral char' => ['a😀b', 5, 3];
        yield 'byte at end after astral' => ['a😀b', 6, 4];
    }
}
