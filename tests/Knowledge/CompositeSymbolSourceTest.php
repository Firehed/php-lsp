<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\GlobalConstantName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Knowledge\CompositeSymbolSource;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The composite is the single place symbol sources are composed (RFC 1 §4.2, §5.3):
 * these prove the fixed precedence — an earlier (more authoritative) backend wins a
 * lookup, a merge, and a name clash — and that the subtype walk crosses the seam
 * between backends. Backend internals are faked ({@see FakeSymbolBackend}); parity
 * with the real surfaces is frozen by the Step P harness.
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

        $info = $source->lookupConstant(GlobalConstantName::fromFullyQualified('App\DEBUG'));

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

        $info = $source->lookupConstant(GlobalConstantName::fromFullyQualified('App\DEBUG'));

        self::assertNotNull($info, 'a later backend must answer when an earlier one cannot');
        self::assertSame('vendor.php', $info->file, 'the answer must come from the backend that declares it');
    }

    public function testLookupConstantReturnsNullWhenNoBackendAnswers(): void
    {
        $source = new CompositeSymbolSource([new FakeSymbolBackend(), new FakeSymbolBackend()]);

        self::assertNull(
            $source->lookupConstant(GlobalConstantName::fromFullyQualified('App\ABSENT')),
            'absence across every backend is a bare null, not an error (RFC 1 §5.3)',
        );
    }

    public function testLookupConstantIsCaseSensitive(): void
    {
        $backend = new FakeSymbolBackend([self::declaredConstant('App\DEBUG', 'file.php')]);
        $source = new CompositeSymbolSource([$backend]);

        self::assertNotNull(
            $source->lookupConstant(GlobalConstantName::fromFullyQualified('App\DEBUG')),
            'exact case match must resolve',
        );
        self::assertNull(
            $source->lookupConstant(GlobalConstantName::fromFullyQualified('App\debug')),
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

    /**
     * @param non-empty-string $target
     */
    #[DataProvider('subtypeCases')]
    public function testIsSubclassOfWalksTheGraphAcrossBackends(string $target, bool $expected): void
    {
        // Child lives in the open document; its whole ancestry lives in the vendor
        // backend, so every edge the walk follows crosses the seam.
        $source = new CompositeSymbolSource([self::openWithChild(), self::vendorGraph()]);

        self::assertSame(
            $expected,
            $source->isSubclassOf(self::className('App\Child'), self::className($target)),
            "isSubclassOf must follow the type graph across backends to reach {$target}",
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function subtypeCases(): iterable
    {
        yield 'direct parent' => ['App\ParentClass', true];
        yield 'grandparent through the parent chain' => ['App\Grandparent', true];
        yield 'directly implemented interface' => ['App\IfaceA', true];
        yield 'interface reached through an interface' => ['App\IfaceBase', true];
        yield 'unrelated type' => ['App\Unrelated', false];
    }

    public function testIsSubclassOfMatchesEdgesUnderTheClassCaseRule(): void
    {
        // The declared parent spelling differs in case from the queried target.
        $backend = new FakeSymbolBackend([
            self::declaredClass('App\Child', parent: 'APP\PARENTCLASS'),
            self::declaredClass('App\ParentClass'),
        ]);
        $source = new CompositeSymbolSource([$backend]);

        self::assertTrue(
            $source->isSubclassOf(self::className('App\Child'), self::className('App\ParentClass')),
            'class-like names are case-insensitive, so a case-divergent parent spelling must still match',
        );
    }

    public function testIsSubclassOfReturnsFalseForAnUnknownClass(): void
    {
        $source = new CompositeSymbolSource([self::openWithChild(), self::vendorGraph()]);

        self::assertFalse(
            $source->isSubclassOf(self::className('App\Unknown'), self::className('App\ParentClass')),
            'a class no backend declares cannot be a subclass of anything',
        );
    }

    public function testIsSubclassOfSkipsUnresolvableSupertypes(): void
    {
        // Orphan's parent and interface are named but nothing declares them: the walk
        // must skip the unresolved edges rather than crash.
        $backend = new FakeSymbolBackend([
            self::declaredClass(
                'App\Orphan',
                parent: 'App\MissingParent',
                interfaces: ['App\MissingInterface'],
            ),
        ]);
        $source = new CompositeSymbolSource([$backend]);

        self::assertFalse(
            $source->isSubclassOf(self::className('App\Orphan'), self::className('App\ParentClass')),
            'unresolvable supertypes are skipped, so the subtype relationship is simply not found',
        );
    }

    public function testIsSubclassOfTerminatesOnACyclicParentGraph(): void
    {
        // Illegal in PHP but reachable in mid-edit code: A extends B extends A. The
        // visited set must break the cycle rather than recurse forever.
        $backend = new FakeSymbolBackend([
            self::declaredClass('App\CycleA', parent: 'App\CycleB'),
            self::declaredClass('App\CycleB', parent: 'App\CycleA'),
        ]);
        $source = new CompositeSymbolSource([$backend]);

        self::assertFalse(
            $source->isSubclassOf(self::className('App\CycleA'), self::className('App\Unrelated')),
            'a cyclic parent chain must terminate and report no relationship',
        );
    }

    public function testIsSubclassOfTerminatesOnADiamondInterfaceGraph(): void
    {
        // Two interfaces both extend the same base: the base is reached twice and the
        // visited set must skip the second visit rather than re-walk it.
        $backend = new FakeSymbolBackend([
            self::declaredClass('App\Diamond', interfaces: ['App\IfaceA', 'App\IfaceB']),
            self::declaredClass('App\IfaceA', interfaces: ['App\IfaceBase']),
            self::declaredClass('App\IfaceB', interfaces: ['App\IfaceBase']),
            self::declaredClass('App\IfaceBase'),
        ]);
        $source = new CompositeSymbolSource([$backend]);

        self::assertFalse(
            $source->isSubclassOf(self::className('App\Diamond'), self::className('App\Unrelated')),
            'a diamond interface graph must be walked once per type and terminate',
        );
    }

    private static function openWithChild(): FakeSymbolBackend
    {
        return new FakeSymbolBackend([
            self::declaredClass('App\Child', parent: 'App\ParentClass', interfaces: ['App\IfaceA']),
        ]);
    }

    private static function vendorGraph(): FakeSymbolBackend
    {
        return new FakeSymbolBackend([
            self::declaredClass('App\ParentClass', parent: 'App\Grandparent'),
            self::declaredClass('App\Grandparent'),
            self::declaredClass('App\IfaceA', interfaces: ['App\IfaceBase']),
            self::declaredClass('App\IfaceBase'),
        ]);
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
