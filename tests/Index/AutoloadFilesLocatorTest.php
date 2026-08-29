<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Index\AutoloadFilesLocator;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Parser\ParserService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `autoload.files` set is the one place Composer addresses a declaration by no
 * name at all, so the only route to it is to parse the set and derive a name -> file
 * map. These prove the derived index reaches all three symbol namespaces, applies
 * PHP's per-kind case rules, and does not overreach into names it never saw
 * (Plan 0002 §3, Step 3b).
 *
 * The index answers both reads of it: `locate` for a known name, and `childrenOf`
 * for what a namespace contains. Enumerating it is what keeps a `files`-declared
 * name from resolving on hover while staying invisible to completion (RFC 1 §4.2).
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
        // The exact match applies to the short name only; the namespace is not.
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

    public function testAnEntryThatCannotBeReadIsSkippedWithoutAbandoningTheRest(): void
    {
        // One bad entry must not cost the project the entries after it.
        $readable = self::tempFile('<?php const SURVIVES_A_BAD_ENTRY = 1;');

        try {
            $locator = self::locatorForMap(
                new ComposerAutoloadMap([], [], [], [self::FIXTURES_ROOT . '/no-such-entry.php', $readable]),
            );

            self::assertNull(
                $locator->locate(QualifiedName::fromFullyQualified('Whatever'), NameKind::ClassLike),
                'an unreadable files entry contributes nothing rather than throwing',
            );
            self::assertNotNull(
                $locator->locate(QualifiedName::fromFullyQualified('SURVIVES_A_BAD_ENTRY'), NameKind::Constant),
                'indexing must resume at the next entry rather than stop at the first unreadable one',
            );
        } finally {
            unlink($readable);
        }
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

            // Delete first: from here a rebuild can only drop the name.
            unlink($path);
            $locator->invalidate('file:///some/other/file.php');

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

    /**
     * The exact contents of each namespace the fixture `files` entries reach. Exact
     * rather than containment, so a name the scan must *not* reach — a computed
     * `define()`, a `define()` value mistaken for its name, an anonymous class —
     * fails here as loudly as a missing one.
     *
     * @return iterable<string, array{string, list<string>, list<array{string, string}>}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function enumeratedNamespaces(): iterable
    {
        yield 'namespaced entry' => [
            'Fixtures\Helpers',
            [],
            [
                ['Fixtures\Helpers\HELPER_LIMIT', 'Constant'],
                ['Fixtures\Helpers\HELPER_DEFINED_QUALIFIED', 'Constant'],
                ['Fixtures\Helpers\HelperContract', 'ClassLike'],
                ['Fixtures\Helpers\HelperFallback', 'ClassLike'],
                ['Fixtures\Helpers\HelperMode', 'ClassLike'],
                ['Fixtures\Helpers\HelperRegistry', 'ClassLike'],
                ['Fixtures\Helpers\helperFormat', 'Function_'],
                ['Fixtures\Helpers\helperNormalize', 'Function_'],
            ],
        ];

        // A namespace the set declares nothing directly in is still reachable,
        // because the namespace on the way to a declaration is a child of its parent.
        yield 'an intermediate namespace' => ['Fixtures', ['Fixtures\Helpers'], []];

        yield 'the global namespace' => [
            '',
            ['Fixtures'],
            [
                ['FIXTURE_GLOBAL_LIMIT', 'Constant'],
                ['FIXTURE_GLOBAL_ALPHA', 'Constant'],
                ['FIXTURE_GLOBAL_BETA', 'Constant'],
                ['FIXTURE_DEFINED_LIMIT', 'Constant'],
                ['FIXTURE_UPPERCASE_DEFINED_LIMIT', 'Constant'],
                ['FIXTURE_NAMED_LIMIT', 'Constant'],
                ['FIXTURE_REORDERED_LIMIT', 'Constant'],
                ['FIXTURE_BODY_LIMIT', 'Constant'],
                ['FixtureGlobalRegistry', 'ClassLike'],
                ['fixtureGlobalHelper', 'Function_'],
                ['fixtureConditionalHelper', 'Function_'],
                ['fixtureBootstrap', 'Function_'],
                ['fixtureNestedHelper', 'Function_'],
                // `define()` takes its whole name from the literal, so this one is
                // global despite being written under a namespace.
                ['FIXTURE_HELPER_DEFINED', 'Constant'],
            ],
        ];

        yield 'a namespace the set does not reach' => ['Fixtures\Domain', [], []];
    }

    /**
     * Enumeration must cover exactly what lookup covers: a name the `files` set
     * declares resolves by hover and definition, so it must also appear in the
     * namespace it is declared in (RFC 1 §4.2 — lookup and enumeration draw on the
     * same backends so their coverage is identical).
     *
     * @param list<string> $expectedChildNamespaces
     * @param list<array{string, string}> $expectedSymbols
     */
    #[DataProvider('enumeratedNamespaces')]
    public function testChildrenOfEnumeratesWhatTheAutoloadFilesSetDeclares(
        string $namespace,
        array $expectedChildNamespaces,
        array $expectedSymbols,
    ): void {
        $contents = self::locatorForRoot(self::FIXTURES_ROOT)->childrenOf($namespace);

        self::assertSame(
            self::sorted($expectedChildNamespaces),
            self::sorted($contents->childNamespaces),
            'the namespaces on the way to a declaration must be enumerated as children',
        );
        self::assertSame(
            self::sortedSymbols($expectedSymbols),
            self::sortedSymbols(self::asPairs($contents)),
            'every name the set declares in the namespace must be enumerated, under its own kind',
        );
    }

    /**
     * The names are keyed for lookup under PHP's per-kind case rules, but reported
     * for enumeration as the declaration spells them — a completion item inserts a
     * name, and `helperregistry` is not the name the file declares.
     */
    public function testChildrenOfReportsNamesAsDeclaredRatherThanNormalized(): void
    {
        $contents = self::locatorForRoot(self::FIXTURES_ROOT)->childrenOf('Fixtures\Helpers');

        $fqns = array_map(
            static fn(CatalogSymbol $symbol): string => $symbol->fullyQualifiedName,
            $contents->symbols,
        );
        self::assertContains('Fixtures\Helpers\HelperRegistry', $fqns, 'a class-like keeps its declared casing');
        self::assertContains('Fixtures\Helpers\helperFormat', $fqns, 'a function keeps its declared casing');
    }

    public function testChildrenOfMatchesANamespaceInAnyCase(): void
    {
        $locator = self::locatorForRoot(self::FIXTURES_ROOT);

        self::assertEquals(
            $locator->childrenOf('Fixtures\Helpers'),
            $locator->childrenOf('FIXTURES\helpers'),
            'PHP namespaces are case-insensitive, so one namespace is not two listings',
        );
    }

    public function testInvalidateRebuildsWhatIsEnumeratedAsWellAsWhatIsLocated(): void
    {
        $path = self::tempFile('<?php namespace Rebuilt; class Before {}');

        try {
            $locator = self::locatorForMap(new ComposerAutoloadMap([], [], [], [$path]));
            self::assertSame(
                [['Rebuilt\Before', 'ClassLike']],
                self::asPairs($locator->childrenOf('Rebuilt')),
                'the eagerly built index must enumerate the file as it was read',
            );

            self::assertNotFalse(
                file_put_contents($path, '<?php namespace Rebuilt; class After {}'),
                'rewrite must succeed',
            );
            $locator->invalidate(FileUri::fromPath($path));

            self::assertSame(
                [['Rebuilt\After', 'ClassLike']],
                self::asPairs($locator->childrenOf('Rebuilt')),
                'enumeration must reflect the rebuilt index, not a memo of the pre-change one',
            );
        } finally {
            unlink($path);
        }
    }

    public function testSearchByPrefixFindsFunctionsByShortNamePrefix(): void
    {
        $results = self::locatorForRoot(self::FIXTURES_ROOT)
            ->searchByPrefix('helperF', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'Fixtures\Helpers\helperFormat',
            $fqns,
            'a function whose short name starts with the prefix must be found',
        );
    }

    public function testSearchByPrefixFindsConstantsByShortNamePrefix(): void
    {
        $results = self::locatorForRoot(self::FIXTURES_ROOT)
            ->searchByPrefix('HELPER_L', NameKind::Constant);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'Fixtures\Helpers\HELPER_LIMIT',
            $fqns,
            'a constant whose short name starts with the prefix must be found',
        );
    }

    public function testSearchByPrefixIsCaseInsensitive(): void
    {
        $results = self::locatorForRoot(self::FIXTURES_ROOT)
            ->searchByPrefix('HELPERF', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'Fixtures\Helpers\helperFormat',
            $fqns,
            'prefix matching is case-insensitive because the user has not finished typing',
        );
    }

    public function testSearchByPrefixReturnsEmptyForNoMatch(): void
    {
        self::assertSame(
            [],
            self::locatorForRoot(self::FIXTURES_ROOT)->searchByPrefix('zzNoMatch', NameKind::Function_),
            'a prefix that matches nothing must return an empty list',
        );
    }

    public function testSearchByPrefixReturnsSymbolsWithCorrectKind(): void
    {
        $results = self::locatorForRoot(self::FIXTURES_ROOT)
            ->searchByPrefix('helperF', NameKind::Function_);

        self::assertNotEmpty($results, 'the prefix must match at least one function');
        foreach ($results as $symbol) {
            self::assertSame(
                SymbolKind::Function_,
                $symbol->kind,
                'every symbol returned for a Function_ search must carry SymbolKind::Function_',
            );
        }
    }

    public function testSearchByPrefixReturnsSymbolsWithFileLocation(): void
    {
        $results = self::locatorForRoot(self::FIXTURES_ROOT)
            ->searchByPrefix('helperF', NameKind::Function_);

        self::assertNotEmpty($results, 'the prefix must match at least one function');
        foreach ($results as $symbol) {
            self::assertNotSame(
                '',
                $symbol->location->uri,
                'a symbol from an autoload.files entry must carry its declaring file',
            );
        }
    }

    public function testSearchByPrefixDoesNotCrossKindBoundaries(): void
    {
        $results = self::locatorForRoot(self::FIXTURES_ROOT)
            ->searchByPrefix('Helper', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertNotContains(
            'Fixtures\Helpers\HelperRegistry',
            $fqns,
            'a class-like must not appear in a function search even if its name matches the prefix',
        );
    }

    /**
     * @return list<array{string, string}>
     */
    private static function asPairs(NamespaceContents $contents): array
    {
        return array_map(
            static fn(CatalogSymbol $symbol): array => [$symbol->fullyQualifiedName, $symbol->kind->name],
            $contents->symbols,
        );
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @param list<array{string, string}> $symbols
     * @return list<array{string, string}>
     */
    private static function sortedSymbols(array $symbols): array
    {
        usort($symbols, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));

        return $symbols;
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
