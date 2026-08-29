<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\DocblockParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocblockParser::class)]
final class DocblockParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function arrayElementProvider(): iterable
    {
        yield 'T[] short syntax' => ['/** @var User[] */', 'User'];
        yield 'nullable T[]' => ['/** @var ?User[] */', 'User'];
        yield 'namespaced T[]' => ['/** @var App\\Domain\\User[] */', 'App\\Domain\\User'];
        yield 'array<T>' => ['/** @var array<User> */', 'User'];
        yield 'array<K,V> picks V' => ['/** @var array<int,User> */', 'User'];
        yield 'list<T>' => ['/** @return list<Item> */', 'Item'];
        yield 'iterable<T>' => ['/** @return iterable<Item> */', 'Item'];
        yield 'iterable<K,V> picks V' => ['/** @return iterable<string,Item> */', 'Item'];
        yield 'psalm-return' => ['/** @psalm-return list<Item> */', 'Item'];
        yield 'phpstan-return' => ['/** @phpstan-return list<Item> */', 'Item'];
        yield 'nested generic inside V returns null' => ['/** @return array<int,array<string,Item>> */', null];
        yield 'no array shape' => ['/** @var string */', null];
        yield 'no tag at all' => ['/** just a description */', null];
        yield 'empty docblock' => ['', null];
    }

    #[DataProvider('arrayElementProvider')]
    public function testArrayElementType(string $docblock, ?string $expected): void
    {
        self::assertSame($expected, DocblockParser::arrayElementType($docblock));
    }

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
