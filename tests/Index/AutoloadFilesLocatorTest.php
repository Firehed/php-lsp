<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Document\FileUri;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Index\AutoloadFilesLocator;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\DeclarationScanner;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Resolution\NameKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `autoload.files` set is the one place Composer addresses a declaration by no
 * name at all, so the only route to it is to parse the set and derive a name -> file
 * map. These prove the derived index reaches all three symbol namespaces, applies
 * PHP's per-kind case rules, and does not overreach into names it never saw
 * (Plan 0002 §3, Step 3b).
 */
#[CoversClass(AutoloadFilesLocator::class)]
final class AutoloadFilesLocatorTest extends TestCase
{
    private const string FIXTURES_ROOT = __DIR__ . '/../Fixtures';

    /**
     * @return iterable<string, array{string, NameKind, ?non-empty-string}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function declaredNames(): iterable
    {
        // Every class-like flavour: Composer's maps address none of these, because
        // the file is reached only through `files`.
        yield 'interface' => ['Fixtures\Helpers\HelperContract', NameKind::ClassLike, 'AutoloadFiles/helpers.php'];
        yield 'trait' => ['Fixtures\Helpers\HelperFallback', NameKind::ClassLike, 'AutoloadFiles/helpers.php'];
        yield 'enum' => ['Fixtures\Helpers\HelperMode', NameKind::ClassLike, 'AutoloadFiles/helpers.php'];
        yield 'class' => ['Fixtures\Helpers\HelperRegistry', NameKind::ClassLike, 'AutoloadFiles/helpers.php'];
        yield 'global class' => ['FixtureGlobalRegistry', NameKind::ClassLike, 'AutoloadFiles/globals.php'];

        yield 'namespaced function' => [
            'Fixtures\Helpers\helperFormat',
            NameKind::Function_,
            'AutoloadFiles/helpers.php',
        ];
        yield 'global function' => ['fixtureGlobalHelper', NameKind::Function_, 'AutoloadFiles/globals.php'];
        yield 'conditional function' => ['fixtureConditionalHelper', NameKind::Function_, 'AutoloadFiles/globals.php'];
        yield 'nested function' => ['fixtureNestedHelper', NameKind::Function_, 'AutoloadFiles/globals.php'];

        yield 'namespaced const' => ['Fixtures\Helpers\HELPER_LIMIT', NameKind::Constant, 'AutoloadFiles/helpers.php'];
        yield 'global const' => ['FIXTURE_GLOBAL_LIMIT', NameKind::Constant, 'AutoloadFiles/globals.php'];
        yield 'second declarator' => ['FIXTURE_GLOBAL_BETA', NameKind::Constant, 'AutoloadFiles/globals.php'];
        yield 'literal define' => ['FIXTURE_DEFINED_LIMIT', NameKind::Constant, 'AutoloadFiles/globals.php'];
        yield 'define inside a body' => ['FIXTURE_BODY_LIMIT', NameKind::Constant, 'AutoloadFiles/globals.php'];
        yield 'qualified define' => [
            'Fixtures\Helpers\HELPER_DEFINED_QUALIFIED',
            NameKind::Constant,
            'AutoloadFiles/helpers.php',
        ];
        // `define()` takes its whole name from the literal, so this one is global
        // despite being written under a namespace — and must be found as such.
        yield 'define ignores its namespace' => [
            'FIXTURE_HELPER_DEFINED',
            NameKind::Constant,
            'AutoloadFiles/helpers.php',
        ];

        yield 'leading separator' => [
            '\Fixtures\Helpers\HelperRegistry',
            NameKind::ClassLike,
            'AutoloadFiles/helpers.php',
        ];

        // Out of reach, each for its own reason.
        yield 'computed define name' => ['FIXTURE_COMPUTED_LIMIT', NameKind::Constant, null];
        yield 'a define value is not a name' => ['FIXTURE_NOT_A_CONSTANT_NAME', NameKind::Constant, null];
        yield 'undeclared name' => ['Fixtures\Helpers\absentHelper', NameKind::Function_, null];
        // A PSR-4 class is reachable, but not by *this* locator: it addresses only
        // what the `files` set declares, and the autoload maps cover the rest.
        yield 'psr-4 class' => ['Fixtures\Domain\User', NameKind::ClassLike, null];
    }

    /**
     * @param ?non-empty-string $expectedPathSuffix
     */
    #[DataProvider('declaredNames')]
    public function testLocateResolvesWhatTheAutoloadFilesSetDeclares(
        string $fullyQualifiedName,
        NameKind $kind,
        ?string $expectedPathSuffix,
    ): void {
        $path = self::locatorForRoot(self::FIXTURES_ROOT)
            ->locate(QualifiedName::fromFullyQualified($fullyQualifiedName), $kind);

        if ($expectedPathSuffix === null) {
            self::assertNull($path, 'a name the files set does not declare has no declaring file');
            return;
        }

        self::assertNotNull($path, "a name declared in the files set must resolve: {$fullyQualifiedName}");
        self::assertStringEndsWith($expectedPathSuffix, $path);
    }

    /**
     * @return iterable<string, array{string, NameKind, bool}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function caseVariants(): iterable
    {
        yield 'class-like' => ['fixtures\helpers\helperregistry', NameKind::ClassLike, true];
        yield 'function' => ['Fixtures\Helpers\HELPERFORMAT', NameKind::Function_, true];
        // The one kind PHP matches exactly: a differently-cased constant is a
        // different constant, so resolving it would be a false positive.
        yield 'constant' => ['fixture_global_limit', NameKind::Constant, false];
        // The exact match a constant requires is of its *short name* only. A
        // namespace path is case-insensitive for every kind, so applying the
        // constant rule to the whole name would miss a name PHP resolves.
        yield 'constant under a recased namespace' => [
            'FIXTURES\HELPERS\HELPER_LIMIT',
            NameKind::Constant,
            true,
        ];
        yield 'constant with a recased short name' => [
            'Fixtures\Helpers\helper_limit',
            NameKind::Constant,
            false,
        ];
    }

    #[DataProvider('caseVariants')]
    public function testLocateAppliesThePerKindCaseRule(
        string $fullyQualifiedName,
        NameKind $kind,
        bool $shouldResolve,
    ): void {
        $path = self::locatorForRoot(self::FIXTURES_ROOT)
            ->locate(QualifiedName::fromFullyQualified($fullyQualifiedName), $kind);

        if ($shouldResolve) {
            self::assertNotNull($path, 'only a constant short name is matched case-sensitively');
            return;
        }

        self::assertNull($path, 'a constant short name in another case is another constant');
    }

    /**
     * @return iterable<string, array{string, NameKind}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function mismatchedKinds(): iterable
    {
        yield 'a function asked for as a constant' => ['Fixtures\Helpers\helperFormat', NameKind::Constant];
        yield 'a function asked for as a class' => ['Fixtures\Helpers\helperFormat', NameKind::ClassLike];
        yield 'a class asked for as a function' => ['Fixtures\Helpers\HelperContract', NameKind::Function_];
        yield 'a constant asked for as a class' => ['FIXTURE_GLOBAL_LIMIT', NameKind::ClassLike];
    }

    /**
     * PHP has three symbol namespaces, so one spelling can name three different
     * things. An index that ignored the kind would answer with the wrong file.
     */
    #[DataProvider('mismatchedKinds')]
    public function testLocateResolvesOnlyForTheKindThatDeclaredTheName(
        string $fullyQualifiedName,
        NameKind $kind,
    ): void {
        $path = self::locatorForRoot(self::FIXTURES_ROOT)
            ->locate(QualifiedName::fromFullyQualified($fullyQualifiedName), $kind);

        self::assertNull($path, 'a name declared in one symbol namespace must not resolve in another');
    }

    public function testAProjectWithNoAutoloadFilesResolvesNothing(): void
    {
        $path = self::locatorForRoot('/nonexistent/path')
            ->locate(QualifiedName::fromFullyQualified('FIXTURE_GLOBAL_LIMIT'), NameKind::Constant);

        self::assertNull($path, 'a project with no autoload.files map indexes nothing, and is not an error');
    }

    public function testAnEntryThatCannotBeReadIsSkipped(): void
    {
        // The malformed fixture's only string entry points at a path that does not
        // exist. Indexing must skip it rather than fail, so one bad entry does not
        // cost the project every other declaration in the set.
        $locator = self::locatorForRoot(self::FIXTURES_ROOT . '/MalformedProject');

        $path = $locator->locate(QualifiedName::fromFullyQualified('Whatever'), NameKind::ClassLike);

        self::assertNull($path, 'an unreadable files entry contributes nothing rather than throwing');
    }

    public function testTheFirstDeclarationOfANameWins(): void
    {
        // The shape that puts one name in two files without breaking the project:
        // competing polyfills, each declaring only if nothing else already has.
        // Two *unconditional* declarations of one name is a project that does not
        // run, so it is not what this rule is for. Composer requires the entries in
        // order, so the first file's guard is the one that passes.
        $polyfill = '<?php if (!function_exists("dupeTarget")) { function dupeTarget(): int { return %d; } }';
        $first = self::tempFile(sprintf($polyfill, 1));
        $second = self::tempFile(sprintf($polyfill, 2));

        try {
            $locator = self::locatorForMap(new ComposerAutoloadMap([], [], [], [$first, $second]));

            self::assertSame(
                $first,
                $locator->locate(QualifiedName::fromFullyQualified('dupeTarget'), NameKind::Function_),
                'the earlier files entry declares the name that takes effect',
            );
        } finally {
            unlink($first);
            unlink($second);
        }
    }

    public function testInvalidateRebuildsTheIndexFromDisk(): void
    {
        $path = self::tempFile('<?php const BEFORE_CHANGE = 1;');

        try {
            $locator = self::locatorForMap(new ComposerAutoloadMap([], [], [], [$path]));
            self::assertNotNull(
                $locator->locate(QualifiedName::fromFullyQualified('BEFORE_CHANGE'), NameKind::Constant),
                'the eagerly built index must reflect the file as it was read',
            );

            self::assertNotFalse(file_put_contents($path, '<?php const AFTER_CHANGE = 2;'), 'rewrite must succeed');
            $locator->invalidate(FileUri::fromPath($path));

            self::assertNotNull(
                $locator->locate(QualifiedName::fromFullyQualified('AFTER_CHANGE'), NameKind::Constant),
                'a name added by an external edit must resolve after invalidation (RFC 1 §5.2)',
            );
            self::assertNull(
                $locator->locate(QualifiedName::fromFullyQualified('BEFORE_CHANGE'), NameKind::Constant),
                'a name the edit removed must stop resolving, not be served from the stale index',
            );
        } finally {
            unlink($path);
        }
    }

    public function testInvalidatingAFileOutsideTheSetLeavesTheIndexAlone(): void
    {
        $path = self::tempFile('<?php const UNTOUCHED = 1;');

        try {
            $locator = self::locatorForMap(new ComposerAutoloadMap([], [], [], [$path]));

            // Deleting the indexed file proves the index was not rebuilt: a rebuild
            // would drop the name, since the file can no longer be read.
            $locator->invalidate('file:///some/other/file.php');
            unlink($path);

            self::assertNotNull(
                $locator->locate(QualifiedName::fromFullyQualified('UNTOUCHED'), NameKind::Constant),
                'a change outside the files set must not cost a rebuild of the whole index',
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private static function locatorForRoot(string $projectRoot): AutoloadFilesLocator
    {
        return self::locatorForMap(ComposerAutoloadMap::fromProjectRoot($projectRoot));
    }

    private static function locatorForMap(ComposerAutoloadMap $map): AutoloadFilesLocator
    {
        return new AutoloadFilesLocator($map, new ParserService(), new DeclarationScanner());
    }

    /**
     * @return non-empty-string
     */
    private static function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'php-lsp-files-');
        self::assertNotFalse($path, 'a temp file must be creatable');
        self::assertNotFalse(file_put_contents($path, $contents), 'the temp file must be writable');

        return $path;
    }
}
