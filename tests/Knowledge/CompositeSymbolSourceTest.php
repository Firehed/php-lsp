<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\SymbolKind;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Knowledge\CompositeSymbolSource;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\TestCase;

/**
 * The composite is the single place symbol sources are composed (RFC 1 §4.2, §5.3):
 * these prove the fixed precedence — an earlier (more authoritative) backend wins a
 * lookup, a merge, and a name clash. Backend internals are faked
 * ({@see FakeSymbolBackend}); parity with the real surfaces is frozen by the Step P
 * harness.
 */
final class CompositeSymbolSourceTest extends TestCase
{
    use BuildsSymbolInfoTrait;

    public function testLookupClassLikeTakesTheFirstBackendThatAnswers(): void
    {
        $open = new FakeSymbolBackend([self::declaredClass('App\Widget', file: 'open.php')]);
        $vendor = new FakeSymbolBackend([self::declaredClass('App\Widget', file: 'vendor.php')]);
        $source = new CompositeSymbolSource([$open, $vendor]);

        $info = $source->lookupClassLike(self::className('App\Widget'));

        self::assertNotNull($info, 'the class is declared, so the lookup must resolve');
        self::assertSame(
            'open.php',
            $info->file,
            'the earlier backend must win: an open document overrides the vendored copy (RFC 1 §5.3)',
        );
    }

    public function testLookupClassLikeFallsThroughToALaterBackend(): void
    {
        $open = new FakeSymbolBackend();
        $vendor = new FakeSymbolBackend([self::declaredClass('App\Widget', file: 'vendor.php')]);
        $source = new CompositeSymbolSource([$open, $vendor]);

        $info = $source->lookupClassLike(self::className('App\Widget'));

        self::assertNotNull($info, 'a later backend must answer when an earlier one cannot');
        self::assertSame('vendor.php', $info->file, 'the answer must come from the backend that declares it');
    }

    public function testLookupClassLikeReturnsNullWhenNoBackendAnswers(): void
    {
        $source = new CompositeSymbolSource([new FakeSymbolBackend(), new FakeSymbolBackend()]);

        self::assertNull(
            $source->lookupClassLike(self::className('App\Absent')),
            'absence across every backend is a bare null, not an error (RFC 1 §5.3)',
        );
    }

    public function testLookupFunctionTakesTheFirstBackendThatAnswers(): void
    {
        $open = new FakeSymbolBackend([self::declaredFunction('App\format', 'open.php')]);
        $vendor = new FakeSymbolBackend([self::declaredFunction('App\format', 'vendor.php')]);
        $source = new CompositeSymbolSource([$open, $vendor]);

        $info = $source->lookupFunction(FunctionName::fromFullyQualified('App\format'));

        self::assertNotNull($info, 'the function is declared, so the lookup must resolve');
        self::assertSame(
            'open.php',
            $info->file,
            'the earlier backend must win: an unsaved edit overrides the file it shadows (RFC 1 §5.3)',
        );
    }

    public function testLookupFunctionFallsThroughToALaterBackend(): void
    {
        $open = new FakeSymbolBackend();
        $vendor = new FakeSymbolBackend([self::declaredFunction('App\format', 'vendor.php')]);
        $source = new CompositeSymbolSource([$open, $vendor]);

        $info = $source->lookupFunction(FunctionName::fromFullyQualified('App\format'));

        self::assertNotNull($info, 'a later backend must answer when an earlier one cannot');
        self::assertSame('vendor.php', $info->file, 'the answer must come from the backend that declares it');
    }

    public function testLookupFunctionReturnsNullWhenNoBackendAnswers(): void
    {
        $source = new CompositeSymbolSource([new FakeSymbolBackend(), new FakeSymbolBackend()]);

        self::assertNull(
            $source->lookupFunction(FunctionName::fromFullyQualified('App\absent')),
            'absence across every backend is a bare null, not an error (RFC 1 §5.3)',
        );
    }

    public function testLookupConstantTakesTheFirstBackendThatAnswers(): void
    {
        $open = new FakeSymbolBackend([self::declaredConstant('App\DEBUG', 'open.php')]);
        $vendor = new FakeSymbolBackend([self::declaredConstant('App\DEBUG', 'vendor.php')]);
        $source = new CompositeSymbolSource([$open, $vendor]);

        $info = $source->lookupConstant(ConstantName::fromFullyQualified('App\DEBUG'));

        self::assertNotNull($info, 'the constant is declared, so the lookup must resolve');
        self::assertSame(
            'open.php',
            $info->file,
            'the earlier backend must win: an unsaved edit overrides the file it shadows (RFC 1 §5.3)',
        );
    }

    public function testLookupConstantFallsThroughToALaterBackend(): void
    {
        $open = new FakeSymbolBackend();
        $vendor = new FakeSymbolBackend([self::declaredConstant('App\DEBUG', 'vendor.php')]);
        $source = new CompositeSymbolSource([$open, $vendor]);

        $info = $source->lookupConstant(ConstantName::fromFullyQualified('App\DEBUG'));

        self::assertNotNull($info, 'a later backend must answer when an earlier one cannot');
        self::assertSame('vendor.php', $info->file, 'the answer must come from the backend that declares it');
    }

    public function testLookupConstantReturnsNullWhenNoBackendAnswers(): void
    {
        $source = new CompositeSymbolSource([new FakeSymbolBackend(), new FakeSymbolBackend()]);

        self::assertNull(
            $source->lookupConstant(ConstantName::fromFullyQualified('App\ABSENT')),
            'absence across every backend is a bare null, not an error (RFC 1 §5.3)',
        );
    }

    public function testLookupConstantIsCaseSensitive(): void
    {
        $backend = new FakeSymbolBackend([self::declaredConstant('App\DEBUG', 'file.php')]);
        $source = new CompositeSymbolSource([$backend]);

        self::assertNotNull(
            $source->lookupConstant(ConstantName::fromFullyQualified('App\DEBUG')),
            'exact case match must resolve',
        );
        self::assertNull(
            $source->lookupConstant(ConstantName::fromFullyQualified('App\debug')),
            'constant names are case-sensitive, so a case mismatch must not resolve',
        );
    }

    public function testChildrenOfMergesEveryBackendWithTheEarlierWinningAClash(): void
    {
        $open = new FakeSymbolBackend(namespaces: [
            'App' => new NamespaceContents(
                ['App\Sub'],
                [new CatalogSymbol('App\Shared', NameKind::ClassLike)],
            ),
        ]);
        $vendor = new FakeSymbolBackend(namespaces: [
            'App' => new NamespaceContents(
                ['App\Other'],
                // The same class-like under its case rule, spelled differently:
                // the open document's spelling must win the merge.
                [new CatalogSymbol('APP\SHARED', NameKind::ClassLike)],
            ),
        ]);
        $source = new CompositeSymbolSource([$open, $vendor]);

        $contents = $source->childrenOf(new NamespaceName('App'));

        self::assertEqualsCanonicalizing(
            ['App\Sub', 'App\Other'],
            $contents->childNamespaces,
            'child namespaces from every backend must be merged',
        );
        self::assertCount(1, $contents->symbols, 'the clashing symbol must be deduplicated to one');
        self::assertSame(
            'App\Shared',
            $contents->symbols[0]->fullyQualifiedName,
            'the earlier backend must win the clash: the open document overrides the vendored listing',
        );
    }

    public function testSearchMergesAndDeduplicatesByFqnEarlierWinning(): void
    {
        $open = new FakeSymbolBackend(searchResults: [self::symbol('App\Log', 'open.php')]);
        $vendor = new FakeSymbolBackend(searchResults: [
            self::symbol('APP\LOG', 'vendor.php'),
            self::symbol('App\Logger', 'vendor.php'),
        ]);
        $source = new CompositeSymbolSource([$open, $vendor]);

        $results = $source->search('Log', NameKind::ClassLike);

        $byFqn = [];
        foreach ($results as $symbol) {
            $byFqn[$symbol->fullyQualifiedName] = $symbol->location->uri;
        }
        self::assertSame(
            ['App\Log' => 'file://open.php', 'App\Logger' => 'file://vendor.php'],
            $byFqn,
            'results merge across backends, deduplicated by FQN with the earlier backend winning the clash',
        );
    }

    private static function symbol(string $fqn, string $file): Symbol
    {
        $shortName = strrchr($fqn, '\\');
        $shortName = $shortName === false ? $fqn : substr($shortName, 1);

        return new Symbol(
            $shortName,
            $fqn,
            SymbolKind::Class_,
            new Location('file://' . $file, 0, 0, 0, 0),
        );
    }
}
