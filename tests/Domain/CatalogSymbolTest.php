<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\CatalogSymbol;
use Firehed\PhpLsp\Domain\NameKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CatalogSymbol::class)]
final class CatalogSymbolTest extends TestCase
{
    public function testClassLikesSpelledInDifferentCaseShareAKey(): void
    {
        self::assertSame(
            (new CatalogSymbol('App\Widget', NameKind::ClassLike))->key(),
            (new CatalogSymbol('APP\WIDGET', NameKind::ClassLike))->key(),
            'class-like names are case-insensitive',
        );
    }

    public function testTheSameSpellingInDifferentKindsHasDifferentKeys(): void
    {
        self::assertNotSame(
            (new CatalogSymbol('App\widget', NameKind::ClassLike))->key(),
            (new CatalogSymbol('App\widget', NameKind::Function_))->key(),
            'a class and a function may share a spelling without being the same symbol',
        );
    }

    public function testShortNameIsTheLastSegment(): void
    {
        self::assertSame(
            'Widget',
            (new CatalogSymbol('App\Ui\Widget', NameKind::ClassLike))->shortName(),
            'the short name drops the namespace',
        );
    }
}
