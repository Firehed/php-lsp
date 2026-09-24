<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;
use Firehed\PhpLsp\Domain\SymbolKind;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Knowledge\CachingSymbolSource;
use Firehed\PhpLsp\Knowledge\CompositeSymbolSource;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CachingSymbolSource::class)]
final class CachingSymbolSourceTest extends TestCase
{
    use BuildsSymbolInfoTrait;

    private CountingSymbolSource $inner;
    private CachingSymbolSource $source;

    protected function setUp(): void
    {
        $this->inner = new CountingSymbolSource(new CompositeSymbolSource([
            new FakeSymbolBackend(
                symbols: [
                    self::declaredClass('App\Alpha', file: '/ws/Alpha.php'),
                    self::declaredClass('App\Beta', file: '/ws/Beta.php'),
                    self::declaredFunction('App\helper', file: '/ws/helpers.php'),
                    self::declaredConstant('App\LIMIT', file: '/ws/helpers.php'),
                    self::declaredClass('ArrayObject'),
                ],
                namespaces: [
                    'App' => new NamespaceContents(['App\Sub'], [new CatalogSymbol('App\Alpha', NameKind::ClassLike)]),
                ],
                searchResults: [
                    new Symbol(
                        'Alpha',
                        'App\Alpha',
                        SymbolKind::Class_,
                        new Location('file:///ws/Alpha.php', 0, 0, 0, 0),
                    ),
                ],
            ),
        ]));
        $this->source = new CachingSymbolSource($this->inner, CacheFactory::inMemory());
    }

    /**
     * @return iterable<string, array{callable(SymbolSourceInterface): ?SymbolInfoInterface, string}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function lookups(): iterable
    {
        yield 'class-like' => [
            static fn(SymbolSourceInterface $source): ?SymbolInfoInterface
                => $source->lookupClassLike(ClasslikeName::fromFullyQualified('App\Alpha')),
            'lookupClassLike',
        ];
        yield 'function' => [
            static fn(SymbolSourceInterface $source): ?SymbolInfoInterface
                => $source->lookupFunction(FunctionName::fromFullyQualified('App\helper')),
            'lookupFunction',
        ];
        yield 'constant' => [
            static fn(SymbolSourceInterface $source): ?SymbolInfoInterface
                => $source->lookupConstant(ConstantName::fromFullyQualified('App\LIMIT')),
            'lookupConstant',
        ];
    }

    /**
     * @param callable(SymbolSourceInterface): ?SymbolInfoInterface $lookup
     */
    #[DataProvider('lookups')]
    public function testARepeatedLookupAsksTheSourceOnce(callable $lookup, string $method): void
    {
        $first = $lookup($this->source);
        $second = $lookup($this->source);

        self::assertNotNull($first, 'the symbol must resolve so there is something to remember');
        self::assertSame(1, $this->inner->callsTo($method), 'a hit is remembered, not re-resolved');
        self::assertSame($first, $second, 'the remembered answer is the same instance');
    }

    public function testAMissIsRememberedUntilInvalidated(): void
    {
        $name = ClasslikeName::fromFullyQualified('App\Missing');

        self::assertNull($this->source->lookupClassLike($name));
        self::assertNull($this->source->lookupClassLike($name));
        self::assertSame(1, $this->inner->callsTo('lookupClassLike'), 'a miss is remembered like a hit');

        $this->source->invalidate('file:///ws/Anything.php');
        $this->source->lookupClassLike($name);

        self::assertSame(
            2,
            $this->inner->callsTo('lookupClassLike'),
            'a miss names no file, so any change on disk may have created it',
        );
    }

    public function testInvalidatingAFileDropsOnlyTheSymbolsItDeclares(): void
    {
        $alpha = ClasslikeName::fromFullyQualified('App\Alpha');
        $beta = ClasslikeName::fromFullyQualified('App\Beta');
        $this->source->lookupClassLike($alpha);
        $this->source->lookupClassLike($beta);

        $this->source->invalidate('file:///ws/Alpha.php');
        $this->source->lookupClassLike($alpha);
        $this->source->lookupClassLike($beta);

        self::assertSame(
            3,
            $this->inner->callsTo('lookupClassLike'),
            'the changed file is re-read; a symbol another file declares stays remembered',
        );
    }

    public function testASymbolWithNoFileSurvivesInvalidation(): void
    {
        $name = ClasslikeName::fromFullyQualified('ArrayObject');
        $this->source->lookupClassLike($name);

        $this->source->invalidate('file:///ws/Alpha.php');
        $this->source->lookupClassLike($name);

        self::assertSame(
            1,
            $this->inner->callsTo('lookupClassLike'),
            'a built-in declares no file, so no file change can affect it',
        );
    }

    public function testARepeatedListingAsksTheSourceOnce(): void
    {
        $first = $this->source->childrenOf(new NamespaceName('App'));
        $second = $this->source->childrenOf(new NamespaceName('app'));

        self::assertSame(
            1,
            $this->inner->callsTo('childrenOf'),
            'namespaces are case-insensitive, so this is one listing',
        );
        self::assertSame($first, $second, 'the remembered listing is the same instance');
    }

    public function testInvalidationDropsEveryListing(): void
    {
        $this->source->childrenOf(new NamespaceName('App'));

        $this->source->invalidate('file:///elsewhere/Unrelated.php');
        $this->source->childrenOf(new NamespaceName('App'));

        self::assertSame(
            2,
            $this->inner->callsTo('childrenOf'),
            'a listing is keyed by namespace, not by file, so any change on disk re-reads it',
        );
    }

    public function testSearchIsNotCached(): void
    {
        $first = $this->source->search('Al', NameKind::ClassLike);
        $this->source->search('Al', NameKind::ClassLike);

        self::assertCount(1, $first, 'the search reaches the source');
        self::assertSame(
            2,
            $this->inner->callsTo('search'),
            'a prefix changes on every keystroke, so search is passed through',
        );
    }
}
