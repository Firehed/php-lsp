<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\ComposerSymbolLocator;
use Firehed\PhpLsp\Resolution\NameKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComposerSymbolLocator::class)]
final class ComposerSymbolLocatorTest extends TestCase
{
    private const string PROJECT_ROOT = __DIR__ . '/../..';
    private const string FIXTURES_ROOT = __DIR__ . '/../Fixtures';

    /**
     * @return iterable<string, array{string, string, ?string}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function classLikeNames(): iterable
    {
        yield 'classmap' => [
            self::FIXTURES_ROOT,
            'Firehed\PhpLsp\Tests\Fixtures\Autoload\ClassmapFixture',
            'Fixtures/Autoload/Classmap/ClassmapFixture.php',
        ];
        yield 'psr-0' => [
            self::FIXTURES_ROOT,
            'Psr0\Psr0Fixture',
            'Fixtures/Autoload/Psr0/Psr0Fixture.php',
        ];
        yield 'psr-4' => [
            self::PROJECT_ROOT,
            ComposerSymbolLocator::class,
            'src/Index/ComposerSymbolLocator.php',
        ];
        yield 'vendor classmap' => [
            self::PROJECT_ROOT,
            TestCase::class,
            'phpunit/phpunit/src/Framework/TestCase.php',
        ];
        // A leading separator is spelling rather than identity, so QualifiedName
        // drops it; Composer's own maps are keyed without one and would not match.
        yield 'leading separator' => [
            self::PROJECT_ROOT,
            '\\' . ComposerSymbolLocator::class,
            'src/Index/ComposerSymbolLocator.php',
        ];
        yield 'no such class' => [self::PROJECT_ROOT, 'NonExistent\Absent', null];
        yield 'no composer directory' => ['/nonexistent/path', TestCase::class, null];
    }

    #[DataProvider('classLikeNames')]
    public function testLocateResolvesAClassLikeThroughTheAutoloadMap(
        string $projectRoot,
        string $fullyQualifiedName,
        ?string $expectedPathSuffix,
    ): void {
        $path = self::locatorForRoot($projectRoot)
            ->locate(QualifiedName::fromFullyQualified($fullyQualifiedName), NameKind::ClassLike);

        if ($expectedPathSuffix === null) {
            self::assertNull($path, 'a name the autoload map cannot address has no declaring file');
            return;
        }

        self::assertNotNull($path, 'a class-like the autoload map addresses must resolve to its file');
        self::assertStringEndsWith($expectedPathSuffix, $path);
    }

    /**
     * @return iterable<string, array{NameKind}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function unmappedKinds(): iterable
    {
        yield 'function' => [NameKind::Function_];
        yield 'constant' => [NameKind::Constant];
    }

    /**
     * Nothing about the name `Foo\bar` says which file declares it: Composer maps
     * only class-likes. The declarations an `autoload.files` entry makes are reached
     * through a derived index, which this locator does not yet build (Plan 0002 §3b).
     */
    #[DataProvider('unmappedKinds')]
    public function testLocateHasNoReachForFunctionsOrConstants(NameKind $kind): void
    {
        $path = self::locatorForRoot(self::FIXTURES_ROOT)
            ->locate(QualifiedName::fromFullyQualified('Fixtures\helperFunction'), $kind);

        self::assertNull($path, 'a kind Composer addresses by no name at all cannot be located');
    }

    public function testDoesNotRegisterAutoloader(): void
    {
        $autoloadersBefore = spl_autoload_functions();

        self::locatorForRoot(self::PROJECT_ROOT);

        $autoloadersAfter = spl_autoload_functions();

        self::assertSame(
            count($autoloadersBefore),
            count($autoloadersAfter),
            'ComposerSymbolLocator should not register additional autoloaders',
        );
    }

    private static function locatorForRoot(string $projectRoot): ComposerSymbolLocator
    {
        return new ComposerSymbolLocator(ComposerAutoloadMap::fromProjectRoot($projectRoot));
    }
}
