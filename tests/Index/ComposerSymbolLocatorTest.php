<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\ComposerSymbolLocator;
use Firehed\PhpLsp\Index\DeclarationScanner;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Resolution\NameKind;
use Firehed\PhpLsp\Tests\Fixtures\Autoload\ClassmapFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

#[CoversClass(ComposerSymbolLocator::class)]
final class ComposerSymbolLocatorTest extends TestCase
{
    private const string FIXTURES_ROOT = __DIR__ . '/../Fixtures';
    private const string PROJECT_ROOT = __DIR__ . '/../..';

    private ParserService $parser;

    protected function setUp(): void
    {
        $this->parser = new ParserService();
    }

    /**
     * @return iterable<string, array{string, NameKind, non-empty-string}>
     */
    public static function locatableNames(): iterable
    {
        yield 'namespaced function' => [
            'Fixtures\Helpers\helperFormat',
            NameKind::Function_,
            'AutoloadFiles/helpers.php',
        ];
        yield 'global function' => [
            'fixtureGlobalHelper',
            NameKind::Function_,
            'AutoloadFiles/globals.php',
        ];
        yield 'namespaced const declaration' => [
            'Fixtures\Helpers\HELPER_LIMIT',
            NameKind::Constant,
            'AutoloadFiles/helpers.php',
        ];
        yield 'global const declaration' => [
            'FIXTURE_GLOBAL_LIMIT',
            NameKind::Constant,
            'AutoloadFiles/globals.php',
        ];
        yield 'literal define()' => [
            'FIXTURE_DEFINED_LIMIT',
            NameKind::Constant,
            'AutoloadFiles/globals.php',
        ];
    }

    /**
     * @param non-empty-string $expectedSuffix
     */
    #[DataProvider('locatableNames')]
    public function testFunctionsAndConstantsResolveToTheirDeclaringFile(
        string $fqn,
        NameKind $kind,
        string $expectedSuffix,
    ): void {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);

        $path = $locator->locate(QualifiedName::fromFullyQualified($fqn), $kind);

        self::assertNotNull($path, "{$fqn} is declared in the project's autoload.files set");
        self::assertStringEndsWith($expectedSuffix, $path);
    }

    public function testClassLikesResolveThroughTheAutoloadMap(): void
    {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);

        // @phpstan-ignore class.notFound
        $path = $locator->locate(QualifiedName::fromFullyQualified(ClassmapFixture::class), NameKind::ClassLike);

        self::assertNotNull($path, 'a class-like has a name -> file map and needs no scan');
        self::assertStringEndsWith('Fixtures/Autoload/Classmap/ClassmapFixture.php', $path);
    }

    public function testClassLikesResolveThroughPsr4(): void
    {
        $locator = $this->locatorForRoot(self::PROJECT_ROOT);

        $path = $locator->locate(
            QualifiedName::fromFullyQualified(ComposerSymbolLocator::class),
            NameKind::ClassLike,
        );

        self::assertNotNull($path, 'the project\'s own code is reached through its PSR-4 prefix');
        self::assertStringEndsWith('src/Index/ComposerSymbolLocator.php', $path);
    }

    public function testClassLikesResolveThroughPsr0(): void
    {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);

        // @phpstan-ignore class.notFound
        $path = $locator->locate(QualifiedName::fromFullyQualified(\Psr0\Psr0Fixture::class), NameKind::ClassLike);

        self::assertNotNull($path, 'PSR-0 is still a name -> file map and must resolve like PSR-4');
        self::assertStringEndsWith('Fixtures/Autoload/Psr0/Psr0Fixture.php', $path);
    }

    public function testClassLikesResolveThroughTheVendorClassmap(): void
    {
        $locator = $this->locatorForRoot(self::PROJECT_ROOT);

        $path = $locator->locate(QualifiedName::fromFullyQualified(TestCase::class), NameKind::ClassLike);

        self::assertNotNull($path, 'a vendored class resolves through the same map');
        self::assertStringContainsString('phpunit/phpunit', $path);
    }

    public function testAnUndeclaredClassLikeIsNotLocatable(): void
    {
        $locator = $this->locatorForRoot(self::PROJECT_ROOT);

        self::assertNull(
            $locator->locate(QualifiedName::fromFullyQualified('NonExistent\Class'), NameKind::ClassLike),
            'absence is a bare null rather than an error (RFC 1 §5.3)',
        );
    }

    public function testAProjectWithoutComposerLocatesNothing(): void
    {
        $locator = $this->locatorForRoot('/nonexistent/path');

        self::assertNull(
            $locator->locate(QualifiedName::fromFullyQualified(TestCase::class), NameKind::ClassLike),
            'a project with no vendor/ yields empty maps rather than an error',
        );
    }

    public function testConstructionRegistersNoAutoloader(): void
    {
        $before = spl_autoload_functions();

        $this->locatorForRoot(self::PROJECT_ROOT);

        self::assertCount(
            count($before),
            spl_autoload_functions(),
            'the locator reads Composer\'s maps as data and must not register an autoloader',
        );
    }

    public function testClassLikeLookupDoesNotParseTheAutoloadFilesSet(): void
    {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);
        $before = $this->parser->getMetrics()->getParseCount();

        // @phpstan-ignore class.notFound
        $locator->locate(QualifiedName::fromFullyQualified(ClassmapFixture::class), NameKind::ClassLike);

        self::assertSame(
            $before,
            $this->parser->getMetrics()->getParseCount(),
            'the class-like path is a map lookup; it must not trigger the function/constant index build',
        );
    }

    /**
     * The seam-level statement of the limitation. It cannot fail on its own — no
     * static parse could produce a concatenated name — so the assertion that
     * actually enforces it is the constant count in
     * {@see DeclarationScannerTest::testComputedDefineNameIsNotReported()}.
     */
    public function testAComputedDefineNameIsNotLocatable(): void
    {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);

        $path = $locator->locate(
            QualifiedName::fromFullyQualified('FIXTURE_COMPUTED_LIMIT'),
            NameKind::Constant,
        );

        self::assertNull($path, 'a runtime-computed constant name is invisible to a static parse');
    }

    public function testAnUndeclaredNameIsNotLocatable(): void
    {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);

        self::assertNull(
            $locator->locate(QualifiedName::fromFullyQualified('noSuchFunction'), NameKind::Function_),
            'absence is a bare null rather than an error (RFC 1 §5.3)',
        );
    }

    /**
     * Matching follows PHP's own rules, which differ per kind *and* between a name's
     * namespace path and its final segment: namespace names are case-insensitive for
     * every kind, function names are case-insensitive throughout, and only a
     * constant's final segment is case-sensitive.
     *
     * @return iterable<string, array{string, NameKind, bool}>
     */
    public static function caseVariants(): iterable
    {
        yield 'function, short name recased' => ['FIXTUREGLOBALHELPER', NameKind::Function_, true];
        yield 'function, namespace recased' => ['FIXTURES\HELPERS\helperFormat', NameKind::Function_, true];
        yield 'function, both recased' => ['fixtures\helpers\HELPERFORMAT', NameKind::Function_, true];
        yield 'constant, short name recased' => ['fixture_global_limit', NameKind::Constant, false];
        yield 'constant, namespace recased' => ['FIXTURES\HELPERS\HELPER_LIMIT', NameKind::Constant, true];
        yield 'constant, namespace and short name recased' => [
            'FIXTURES\HELPERS\helper_limit',
            NameKind::Constant,
            false,
        ];
    }

    #[DataProvider('caseVariants')]
    public function testNamesAreMatchedWithPhpsOwnCaseRules(string $fqn, NameKind $kind, bool $expectFound): void
    {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);

        $path = $locator->locate(QualifiedName::fromFullyQualified($fqn), $kind);

        if ($expectFound) {
            self::assertNotNull($path, "{$fqn} resolves to the same symbol PHP would resolve it to");
        } else {
            self::assertNull($path, "{$fqn} names a different symbol than the one declared");
        }
    }

    public function testAProjectWithNoAutoloadFilesLocatesNoFunctions(): void
    {
        $locator = $this->locatorFor(new ComposerAutoloadMap());

        self::assertNull(
            $locator->locate(QualifiedName::fromFullyQualified('anything'), NameKind::Function_),
            'a project declaring no files autoload has no function reach, which is not an error',
        );
    }

    public function testAnUnreadableAutoloadFileIsSkipped(): void
    {
        $locator = $this->locatorFor(new ComposerAutoloadMap(
            files: [
                self::FIXTURES_ROOT . '/AutoloadFiles/does-not-exist.php',
                self::FIXTURES_ROOT . '/AutoloadFiles/globals.php',
            ],
        ));

        self::assertNotNull(
            $locator->locate(QualifiedName::fromFullyQualified('fixtureGlobalHelper'), NameKind::Function_),
            'a stale autoload.files entry must not prevent the remaining files being scanned',
        );
    }

    /**
     * @return iterable<string, array{string, NameKind}>
     */
    public static function shadowedNames(): iterable
    {
        yield 'function' => ['fixtureGlobalHelper', NameKind::Function_];
        yield 'constant' => ['FIXTURE_GLOBAL_LIMIT', NameKind::Constant];
    }

    #[DataProvider('shadowedNames')]
    public function testTheFirstDeclarationOfANameWins(string $fqn, NameKind $kind): void
    {
        // PHP cannot load two files declaring the same name, so a name appearing in
        // two autoload.files entries means a stale map, not an override: the entry
        // Composer loads first is the declaration the runtime actually has. Sending a
        // reader to the later one points it at a body that never executes.
        $locator = $this->locatorFor(new ComposerAutoloadMap(
            files: [
                self::FIXTURES_ROOT . '/AutoloadFiles/globals.php',
                self::FIXTURES_ROOT . '/AutoloadFiles/shadowed-globals.php',
            ],
        ));

        $path = $locator->locate(QualifiedName::fromFullyQualified($fqn), $kind);

        self::assertNotNull($path, "{$fqn} is declared in both entries");
        self::assertStringEndsWith(
            'AutoloadFiles/globals.php',
            $path,
            'the first declaring entry wins; a later one is a stale map',
        );
    }

    public function testTheIndexIsBuiltOnceAcrossLookups(): void
    {
        $reads = 0;
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT, $this->countingCache($reads));

        $locator->locate(QualifiedName::fromFullyQualified('fixtureGlobalHelper'), NameKind::Function_);
        $afterFirst = $this->parser->getMetrics()->getParseCount();
        $readsAfterFirst = $reads;
        self::assertGreaterThan(0, $afterFirst, 'the first lookup builds the index by parsing autoload.files');

        // The parser memoizes by content for the duration of one message; discard it
        // so a reparse would be visible rather than hidden behind that memo.
        $this->parser->discardScopedParses();
        $locator->locate(QualifiedName::fromFullyQualified('Fixtures\Helpers\helperFormat'), NameKind::Function_);

        self::assertSame(
            $afterFirst,
            $this->parser->getMetrics()->getParseCount(),
            'a second lookup must reuse the built index rather than reparse autoload.files',
        );
        self::assertSame(
            $readsAfterFirst,
            $reads,
            'a second lookup must reuse the derived index rather than rebuild it from the cached scans',
        );
    }

    public function testWarmingBuildsTheIndexAheadOfTheFirstLookup(): void
    {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);

        $locator->warm();
        $afterWarm = $this->parser->getMetrics()->getParseCount();
        self::assertGreaterThan(0, $afterWarm, 'warming parses the autoload.files set up front');

        $this->parser->discardScopedParses();
        $path = $locator->locate(QualifiedName::fromFullyQualified('fixtureGlobalHelper'), NameKind::Function_);

        self::assertNotNull($path, 'a warmed locator answers from the index');
        self::assertSame(
            $afterWarm,
            $this->parser->getMetrics()->getParseCount(),
            'a lookup after warming must not parse again',
        );
    }

    public function testWarmingTwiceDoesNotRebuildTheIndex(): void
    {
        $reads = 0;
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT, $this->countingCache($reads));

        $locator->warm();
        $afterWarm = $this->parser->getMetrics()->getParseCount();
        $readsAfterWarm = $reads;

        $this->parser->discardScopedParses();
        $locator->warm();

        self::assertSame(
            $afterWarm,
            $this->parser->getMetrics()->getParseCount(),
            'warming is idempotent; a second call must not reparse',
        );
        self::assertSame(
            $readsAfterWarm,
            $reads,
            'warming is idempotent; a second call must not rebuild the index either',
        );
    }

    public function testInvalidatingAFileRescansItOnTheNextLookup(): void
    {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);
        $locator->warm();
        $afterWarm = $this->parser->getMetrics()->getParseCount();

        $this->parser->discardScopedParses();
        $locator->invalidate('file://' . realpath(self::FIXTURES_ROOT . '/AutoloadFiles/globals.php'));
        $path = $locator->locate(QualifiedName::fromFullyQualified('fixtureGlobalHelper'), NameKind::Function_);

        self::assertNotNull($path, 'the invalidated file is re-read rather than dropped');
        // Exactly one, not merely "more": the rebuild must re-read the file that
        // changed and reuse the cached scan of every file that did not, which an
        // "at least one more parse" bound cannot tell from rescanning the whole set.
        self::assertSame(
            $afterWarm + 1,
            $this->parser->getMetrics()->getParseCount(),
            'only the externally changed file is reparsed on the next query (RFC 1 §5.2, §5.3)',
        );
    }

    public function testInvalidatingAnUnrelatedFileKeepsTheCachedScans(): void
    {
        // A spy rather than a parse count: a rebuild from wholly cached scans costs
        // no parse either, so the parse counter cannot tell the guard being present
        // from it being absent. The cache eviction it skips is what distinguishes them.
        $backing = CacheFactory::inMemory();
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback($backing->get(...));
        $cache->method('set')->willReturnCallback($backing->set(...));
        $cache->expects($this->never())
            ->method('delete');

        $locator = new ComposerSymbolLocator(
            ComposerAutoloadMap::fromProjectRoot(self::FIXTURES_ROOT),
            $this->parser,
            new DeclarationScanner(),
            $cache,
        );
        $locator->warm();
        $afterWarm = $this->parser->getMetrics()->getParseCount();

        $this->parser->discardScopedParses();
        $locator->invalidate('file:///somewhere/else/Unrelated.php');
        $locator->locate(QualifiedName::fromFullyQualified('fixtureGlobalHelper'), NameKind::Function_);

        self::assertSame(
            $afterWarm,
            $this->parser->getMetrics()->getParseCount(),
            'a change to a file outside the autoload.files set costs nothing to rebuild',
        );
    }

    public function testAClassNameStillResolvesThroughTheQualifiedNameConversion(): void
    {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);

        // @phpstan-ignore class.notFound
        $name = QualifiedName::fromClassName(new ClassName(ClassmapFixture::class));

        $path = $locator->locate($name, NameKind::ClassLike);

        self::assertNotNull(
            $path,
            'the class-like path accepts a ClassName converted to the kind-neutral type',
        );
        // The resolved path, not merely "something resolved": a conversion that
        // mangled the namespace could still land on some file in the classmap.
        self::assertStringEndsWith('Fixtures/Autoload/Classmap/ClassmapFixture.php', $path);
    }

    /**
     * A cache whose reads are counted, backed by a real one.
     *
     * Rebuilding the derived index reads every `autoload.files` entry's cached scan,
     * so the read count is what tells a reused index from a rebuilt one. The parse
     * counter cannot: the scans stay cached either way, so a rebuild on every lookup
     * costs no parse at all.
     */
    private function countingCache(int &$reads): CacheInterface
    {
        $backing = CacheFactory::inMemory();

        $cache = self::createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(function (string $key) use ($backing, &$reads): mixed {
            $reads++;

            return $backing->get($key);
        });
        $cache->method('set')->willReturnCallback($backing->set(...));

        return $cache;
    }

    private function locatorForRoot(string $projectRoot, ?CacheInterface $cache = null): ComposerSymbolLocator
    {
        return $this->locatorFor(ComposerAutoloadMap::fromProjectRoot($projectRoot), $cache);
    }

    private function locatorFor(ComposerAutoloadMap $map, ?CacheInterface $cache = null): ComposerSymbolLocator
    {
        return new ComposerSymbolLocator(
            $map,
            $this->parser,
            new DeclarationScanner(),
            $cache ?? CacheFactory::inMemory(),
        );
    }
}
