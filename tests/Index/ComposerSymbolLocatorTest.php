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

    public function testFunctionNamesAreMatchedCaseInsensitively(): void
    {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);

        $path = $locator->locate(
            QualifiedName::fromFullyQualified('FIXTUREGLOBALHELPER'),
            NameKind::Function_,
        );

        self::assertNotNull($path, 'PHP function names are case-insensitive');
    }

    public function testConstantNamesAreMatchedCaseSensitively(): void
    {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);

        $path = $locator->locate(
            QualifiedName::fromFullyQualified('fixture_global_limit'),
            NameKind::Constant,
        );

        self::assertNull($path, 'PHP constant names are case-sensitive');
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

    public function testTheIndexIsBuiltOnceAcrossLookups(): void
    {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);

        $locator->locate(QualifiedName::fromFullyQualified('fixtureGlobalHelper'), NameKind::Function_);
        $afterFirst = $this->parser->getMetrics()->getParseCount();
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
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);

        $locator->warm();
        $afterWarm = $this->parser->getMetrics()->getParseCount();

        $this->parser->discardScopedParses();
        $locator->warm();

        self::assertSame(
            $afterWarm,
            $this->parser->getMetrics()->getParseCount(),
            'warming is idempotent; a second call must not reparse',
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
        self::assertGreaterThan(
            $afterWarm,
            $this->parser->getMetrics()->getParseCount(),
            'an externally changed file must be reparsed on the next query (RFC 1 §5.2, §5.3)',
        );
    }

    public function testInvalidatingAnUnrelatedFileKeepsTheCachedScans(): void
    {
        $locator = $this->locatorForRoot(self::FIXTURES_ROOT);
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

        self::assertNotNull(
            $locator->locate($name, NameKind::ClassLike),
            'the class-like path accepts a ClassName converted to the kind-neutral type',
        );
    }

    private function locatorForRoot(string $projectRoot): ComposerSymbolLocator
    {
        return $this->locatorFor(ComposerAutoloadMap::fromProjectRoot($projectRoot));
    }

    private function locatorFor(ComposerAutoloadMap $map): ComposerSymbolLocator
    {
        return new ComposerSymbolLocator(
            $map,
            $this->parser,
            new DeclarationScanner(),
            CacheFactory::inMemory(),
        );
    }
}
