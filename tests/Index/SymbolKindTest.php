<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Index\SymbolKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolKind::class)]
final class SymbolKindTest extends TestCase
{
    /**
     * @param list<SymbolKind> $expected
     */
    #[DataProvider('forNameKindProvider')]
    public function testForNameKind(NameKind $kind, array $expected): void
    {
        self::assertEqualsCanonicalizing(
            $expected,
            SymbolKind::forNameKind($kind),
        );
    }

    #[DataProvider('nameKindProvider')]
    public function testNameKind(SymbolKind $kind, ?NameKind $expected): void
    {
        self::assertSame(
            $expected,
            $kind->nameKind(),
            'the inverse mapping must agree with forNameKind, which it is derived from',
        );
    }

    /**
     * @return iterable<string, array{SymbolKind, ?NameKind}>
     */
    public static function nameKindProvider(): iterable
    {
        yield 'Class_' => [SymbolKind::Class_, NameKind::ClassLike];
        yield 'Interface_' => [SymbolKind::Interface_, NameKind::ClassLike];
        yield 'Trait_' => [SymbolKind::Trait_, NameKind::ClassLike];
        yield 'Enum_' => [SymbolKind::Enum_, NameKind::ClassLike];
        yield 'Function_' => [SymbolKind::Function_, NameKind::Function_];
        yield 'Constant' => [SymbolKind::Constant, NameKind::Constant];
        yield 'Method names nothing an FQN addresses' => [SymbolKind::Method, null];
        yield 'Property names nothing an FQN addresses' => [SymbolKind::Property, null];
    }

    /**
     * @return iterable<string, array{NameKind, list<SymbolKind>}>
     */
    public static function forNameKindProvider(): iterable
    {
        yield 'ClassLike includes all four class-like kinds' => [
            NameKind::ClassLike,
            [SymbolKind::Class_, SymbolKind::Interface_, SymbolKind::Trait_, SymbolKind::Enum_],
        ];

        yield 'Function_ maps to Function_' => [
            NameKind::Function_,
            [SymbolKind::Function_],
        ];

        yield 'Constant maps to Constant' => [
            NameKind::Constant,
            [SymbolKind::Constant],
        ];
    }
}
