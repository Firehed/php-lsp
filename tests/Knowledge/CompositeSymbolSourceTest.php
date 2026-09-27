<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\CatalogSymbol;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\Symbol;
use Firehed\PhpLsp\Domain\SymbolKind;
use Firehed\PhpLsp\Knowledge\AutoloadFilesBackend;
use Firehed\PhpLsp\Knowledge\ComposerAutoloadMapReader;
use Firehed\PhpLsp\Knowledge\CompositeSymbolSource;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\OpenDocumentBackend;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\TestCase;

/**
 * The composite is the single place symbol sources are composed (RFC 1 §4.2, §5.3):
 * these prove the fixed precedence — an earlier (more authoritative) backend wins a
 * lookup, a merge, and a name clash. The concrete slots (openDocuments,
 * autoloadFiles) start empty so the fake-backed disk and built-in slots — the two
 * the composite types on the interface — carry every assertion. Parity with the
 * real backends is frozen by the Step P harness.
 */
final class CompositeSymbolSourceTest extends TestCase
{
    use BuildsSymbolInfoTrait;

    /**
     * Pins the constructor's positional contract: an open document is the top of
     * the precedence chain, so a class it declares must win over the same name
     * resolved from any later slot. A regression that swapped the argument order
     * (openDocuments moved past disk, say) would silently pass every disk-only
     * test above; this one would fail.
     */
    public function testOpenDocumentsWinsOverEveryLaterBackend(): void
    {
        $production = ProductionSyntaxSource::create();
        $scanner = new DeclarationScanner();
        $infoFactory = new DeclarationSymbolInfoFactory();
        $openDocuments = new OpenDocumentBackend($production->source, $scanner, $infoFactory);
        $openDocuments->openDocument(new TextDocument(
            'file:///open.php',
            'php',
            1,
            '<?php namespace App; class Widget {}',
        ));

        $source = new CompositeSymbolSource(
            $openDocuments,
            new AutoloadFilesBackend(
                ComposerAutoloadMapReader::fromMap(new ComposerAutoloadMap()),
                $infoFactory,
                $scanner,
                $production->reader,
                $production->source,
            ),
            new FakeSymbolBackend([self::declaredClass('App\Widget', file: 'disk.php')]),
            new FakeSymbolBackend([self::declaredClass('App\Widget', file: 'builtin.php')]),
        );

        $info = $source->lookupClassLike(ClasslikeName::fromFullyQualified('App\Widget'));

        self::assertNotNull($info, 'the open document declares the class, so the lookup must resolve');
        self::assertSame(
            '/open.php',
            $info->file,
            'the open-document slot must win over disk and built-in, pinning constructor arg order',
        );
    }

    public function testLookupClassLikeTakesTheFirstBackendThatAnswers(): void
    {
        $source = self::compose(
            disk: new FakeSymbolBackend([self::declaredClass('App\Widget', file: 'disk.php')]),
            builtin: new FakeSymbolBackend([self::declaredClass('App\Widget', file: 'builtin.php')]),
        );

        $info = $source->lookupClassLike(self::className('App\Widget'));

        self::assertNotNull($info, 'the class is declared, so the lookup must resolve');
        self::assertSame(
            'disk.php',
            $info->file,
            'the earlier backend must win: disk overrides the built-in copy (RFC 1 §5.3)',
        );
    }

    public function testLookupClassLikeFallsThroughToALaterBackend(): void
    {
        $source = self::compose(
            disk: new FakeSymbolBackend(),
            builtin: new FakeSymbolBackend([self::declaredClass('App\Widget', file: 'builtin.php')]),
        );

        $info = $source->lookupClassLike(self::className('App\Widget'));

        self::assertNotNull($info, 'a later backend must answer when an earlier one cannot');
        self::assertSame('builtin.php', $info->file, 'the answer must come from the backend that declares it');
    }

    public function testLookupClassLikeReturnsNullWhenNoBackendAnswers(): void
    {
        $source = self::compose(disk: new FakeSymbolBackend(), builtin: new FakeSymbolBackend());

        self::assertNull(
            $source->lookupClassLike(self::className('App\Absent')),
            'absence across every backend is a bare null, not an error (RFC 1 §5.3)',
        );
    }

    public function testLookupFunctionTakesTheFirstBackendThatAnswers(): void
    {
        $source = self::compose(
            disk: new FakeSymbolBackend([self::declaredFunction('App\format', 'disk.php')]),
            builtin: new FakeSymbolBackend([self::declaredFunction('App\format', 'builtin.php')]),
        );

        $info = $source->lookupFunction(FunctionName::fromFullyQualified('App\format'));

        self::assertNotNull($info, 'the function is declared, so the lookup must resolve');
        self::assertSame(
            'disk.php',
            $info->file,
            'the earlier backend must win: disk overrides the built-in copy (RFC 1 §5.3)',
        );
    }

    public function testLookupFunctionFallsThroughToALaterBackend(): void
    {
        $source = self::compose(
            disk: new FakeSymbolBackend(),
            builtin: new FakeSymbolBackend([self::declaredFunction('App\format', 'builtin.php')]),
        );

        $info = $source->lookupFunction(FunctionName::fromFullyQualified('App\format'));

        self::assertNotNull($info, 'a later backend must answer when an earlier one cannot');
        self::assertSame('builtin.php', $info->file, 'the answer must come from the backend that declares it');
    }

    public function testLookupFunctionReturnsNullWhenNoBackendAnswers(): void
    {
        $source = self::compose(disk: new FakeSymbolBackend(), builtin: new FakeSymbolBackend());

        self::assertNull(
            $source->lookupFunction(FunctionName::fromFullyQualified('App\absent')),
            'absence across every backend is a bare null, not an error (RFC 1 §5.3)',
        );
    }

    public function testLookupConstantTakesTheFirstBackendThatAnswers(): void
    {
        $source = self::compose(
            disk: new FakeSymbolBackend([self::declaredConstant('App\DEBUG', 'disk.php')]),
            builtin: new FakeSymbolBackend([self::declaredConstant('App\DEBUG', 'builtin.php')]),
        );

        $info = $source->lookupConstant(ConstantName::fromFullyQualified('App\DEBUG'));

        self::assertNotNull($info, 'the constant is declared, so the lookup must resolve');
        self::assertSame(
            'disk.php',
            $info->file,
            'the earlier backend must win: disk overrides the built-in copy (RFC 1 §5.3)',
        );
    }

    public function testLookupConstantFallsThroughToALaterBackend(): void
    {
        $source = self::compose(
            disk: new FakeSymbolBackend(),
            builtin: new FakeSymbolBackend([self::declaredConstant('App\DEBUG', 'builtin.php')]),
        );

        $info = $source->lookupConstant(ConstantName::fromFullyQualified('App\DEBUG'));

        self::assertNotNull($info, 'a later backend must answer when an earlier one cannot');
        self::assertSame('builtin.php', $info->file, 'the answer must come from the backend that declares it');
    }

    public function testLookupConstantReturnsNullWhenNoBackendAnswers(): void
    {
        $source = self::compose(disk: new FakeSymbolBackend(), builtin: new FakeSymbolBackend());

        self::assertNull(
            $source->lookupConstant(ConstantName::fromFullyQualified('App\ABSENT')),
            'absence across every backend is a bare null, not an error (RFC 1 §5.3)',
        );
    }

    public function testLookupConstantIsCaseSensitive(): void
    {
        $source = self::compose(
            disk: new FakeSymbolBackend([self::declaredConstant('App\DEBUG', 'file.php')]),
            builtin: new FakeSymbolBackend(),
        );

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
        $disk = new FakeSymbolBackend(namespaces: [
            'App' => new NamespaceContents(
                ['App\Sub'],
                [new CatalogSymbol('App\Shared', NameKind::ClassLike)],
            ),
        ]);
        $builtin = new FakeSymbolBackend(namespaces: [
            'App' => new NamespaceContents(
                ['App\Other'],
                // The same class-like under its case rule, spelled differently:
                // the earlier backend's spelling must win the merge.
                [new CatalogSymbol('APP\SHARED', NameKind::ClassLike)],
            ),
        ]);
        $source = self::compose(disk: $disk, builtin: $builtin);

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
            'the earlier backend must win the clash: the disk spelling overrides the built-in listing',
        );
    }

    public function testSearchMergesAndDeduplicatesByFqnEarlierWinning(): void
    {
        $source = self::compose(
            disk: new FakeSymbolBackend(searchResults: [self::symbol('App\Log', 'disk.php')]),
            builtin: new FakeSymbolBackend(searchResults: [
                self::symbol('APP\LOG', 'builtin.php'),
                self::symbol('App\Logger', 'builtin.php'),
            ]),
        );

        $results = $source->search('Log', NameKind::ClassLike);

        $byFqn = [];
        foreach ($results as $symbol) {
            $byFqn[$symbol->fullyQualifiedName] = $symbol->location->uri;
        }
        self::assertSame(
            ['App\Log' => 'file://disk.php', 'App\Logger' => 'file://builtin.php'],
            $byFqn,
            'results merge across backends, deduplicated by FQN with the earlier backend winning the clash',
        );
    }

    public function testSearchReturnsEveryDeclaredConstantEvenWhenAnotherDiffersOnlyByCase(): void
    {
        $upper = self::symbol('App\DEBUG', 'disk.php', SymbolKind::Constant);
        $lower = self::symbol('App\debug', 'builtin.php', SymbolKind::Constant);
        $source = self::compose(
            disk: new FakeSymbolBackend(searchResults: [$upper]),
            builtin: new FakeSymbolBackend(searchResults: [$lower]),
        );

        $results = $source->search('D', NameKind::Constant);

        self::assertContains($upper, $results, 'the App\\DEBUG constant must be returned to the caller');
        self::assertContains(
            $lower,
            $results,
            'App\\debug is a separate constant and must be returned alongside App\\DEBUG, not merged with it',
        );
    }

    /**
     * The concrete slots are populated with a real backend in empty state, so the
     * fake-backed disk and built-in slots — the ones the composite types on the
     * interface — carry the precedence assertions.
     */
    private static function compose(SymbolSourceInterface $disk, SymbolSourceInterface $builtin): CompositeSymbolSource
    {
        $production = ProductionSyntaxSource::create();
        $scanner = new DeclarationScanner();
        $infoFactory = new DeclarationSymbolInfoFactory();
        $emptyReader = ComposerAutoloadMapReader::fromMap(new ComposerAutoloadMap());

        return new CompositeSymbolSource(
            new OpenDocumentBackend($production->source, $scanner, $infoFactory),
            new AutoloadFilesBackend($emptyReader, $infoFactory, $scanner, $production->reader, $production->source),
            $disk,
            $builtin,
        );
    }

    private static function symbol(string $fqn, string $file, SymbolKind $kind = SymbolKind::Class_): Symbol
    {
        $shortName = strrchr($fqn, '\\');
        $shortName = $shortName === false ? $fqn : substr($shortName, 1);

        return new Symbol(
            $shortName,
            $fqn,
            $kind,
            new Location('file://' . $file, 0, 0, 0, 0),
        );
    }
}
