<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\NamespaceContents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Merge deduplicates the same symbol reported by several sources, so its key must
 * be the symbol's identity: the kind, plus the name under that kind's case rule
 * (NameKind::normalize). A whole-FQN lowercase key collapses distinct constants
 * and collapses symbols from different namespaces of the language that share a
 * spelling.
 */
#[CoversClass(NamespaceContents::class)]
#[CoversClass(CatalogSymbol::class)]
final class NamespaceContentsTest extends TestCase
{
    public function testMergeKeepsConstantsDifferingOnlyInShortNameCase(): void
    {
        $merged = NamespaceContents::merge([
            new NamespaceContents([], [new CatalogSymbol('Fixtures\Helpers\STATUS', NameKind::Constant)]),
            new NamespaceContents([], [new CatalogSymbol('Fixtures\Helpers\status', NameKind::Constant)]),
        ]);

        self::assertCount(
            2,
            $merged->symbols,
            'constant names are case-sensitive, so these are two distinct symbols',
        );
    }

    public function testMergeKeepsAClassAndAFunctionSharingASpelling(): void
    {
        $merged = NamespaceContents::merge([
            new NamespaceContents([], [new CatalogSymbol('Fixtures\Helpers\Format', NameKind::ClassLike)]),
            new NamespaceContents([], [new CatalogSymbol('Fixtures\Helpers\format', NameKind::Function_)]),
        ]);

        self::assertCount(
            2,
            $merged->symbols,
            'PHP\'s symbol namespaces are independent: a class and a function may share a name',
        );
    }

    public function testMergeDeduplicatesTheSameClassUnderItsCaseRule(): void
    {
        $merged = NamespaceContents::merge([
            new NamespaceContents([], [new CatalogSymbol('Fixtures\Domain\User', NameKind::ClassLike)]),
            new NamespaceContents([], [new CatalogSymbol('FIXTURES\DOMAIN\USER', NameKind::ClassLike)]),
        ]);

        self::assertCount(1, $merged->symbols, 'class names are case-insensitive, so this is one symbol');
        self::assertSame(
            'Fixtures\Domain\User',
            $merged->symbols[0]->fullyQualifiedName,
            'the earlier (more authoritative) source wins the spelling',
        );
    }

    public function testMergeDeduplicatesConstantsDifferingOnlyInNamespaceCase(): void
    {
        $merged = NamespaceContents::merge([
            new NamespaceContents([], [new CatalogSymbol('Fixtures\Helpers\STATUS', NameKind::Constant)]),
            new NamespaceContents([], [new CatalogSymbol('FIXTURES\HELPERS\STATUS', NameKind::Constant)]),
        ]);

        self::assertCount(
            1,
            $merged->symbols,
            'only the short name of a constant is case-sensitive; the namespace path is not',
        );
    }
}
