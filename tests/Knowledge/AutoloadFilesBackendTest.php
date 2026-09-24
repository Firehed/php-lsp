<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\SymbolKind;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Knowledge\AutoloadFilesBackend;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `autoload.files` set is the one place Composer addresses a declaration by no
 * name at all, so the only route to it is to parse the set and derive a name ->
 * declaration map. These prove the derived index reaches all three symbol
 * namespaces, applies PHP's per-kind case rules, and does not overreach into
 * names it never saw (Plan 0002 §3).
 *
 * The index answers all three reads of it: lookup for a known name, `childrenOf`
 * for what a namespace contains, and `search` for a bare short-name prefix.
 * Coverage across the three is identical — a `files`-declared name that resolved
 * on hover while staying invisible to completion is exactly the per-surface
 * split RFC 1 §4.2 forbids.
 */
#[CoversClass(AutoloadFilesBackend::class)]
final class AutoloadFilesBackendTest extends TestCase
{
    use LooksUpBackendSymbolsTrait;

    private const string FIXTURES_ROOT = __DIR__ . '/../Fixtures';

    /**
     * @return iterable<string, array{string, NameKind, bool}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function declaredNames(): iterable
    {
        // Every class-like flavour: Composer's maps address none of these, because
        // the file is reached only through `files`.
        yield 'interface' => ['Fixtures\Helpers\HelperContract', NameKind::ClassLike, true];
        yield 'trait' => ['Fixtures\Helpers\HelperFallback', NameKind::ClassLike, true];
        yield 'enum' => ['Fixtures\Helpers\HelperMode', NameKind::ClassLike, true];
        yield 'class' => ['Fixtures\Helpers\HelperRegistry', NameKind::ClassLike, true];
        yield 'global class' => ['FixtureGlobalRegistry', NameKind::ClassLike, true];

        yield 'namespaced function' => ['Fixtures\Helpers\helperFormat', NameKind::Function_, true];
        yield 'global function' => ['fixtureGlobalHelper', NameKind::Function_, true];
        yield 'conditional function' => ['fixtureConditionalHelper', NameKind::Function_, true];
        yield 'nested function' => ['fixtureNestedHelper', NameKind::Function_, true];

        yield 'namespaced const' => ['Fixtures\Helpers\HELPER_LIMIT', NameKind::Constant, true];
        yield 'global const' => ['FIXTURE_GLOBAL_LIMIT', NameKind::Constant, true];
        yield 'second declarator' => ['FIXTURE_GLOBAL_BETA', NameKind::Constant, true];
        yield 'literal define' => ['FIXTURE_DEFINED_LIMIT', NameKind::Constant, true];
        yield 'define inside a body' => ['FIXTURE_BODY_LIMIT', NameKind::Constant, true];
        yield 'qualified define' => ['Fixtures\Helpers\HELPER_DEFINED_QUALIFIED', NameKind::Constant, true];
        // `define()` takes its whole name from the literal, so this one is global
        // despite being written under a namespace — and must be found as such.
        yield 'define ignores its namespace' => ['FIXTURE_HELPER_DEFINED', NameKind::Constant, true];

        yield 'leading separator' => ['\Fixtures\Helpers\HelperRegistry', NameKind::ClassLike, true];

        // Out of reach, each for its own reason.
        yield 'computed define name' => ['FIXTURE_COMPUTED_LIMIT', NameKind::Constant, false];
        yield 'a define value is not a name' => ['FIXTURE_NOT_A_CONSTANT_NAME', NameKind::Constant, false];
        yield 'undeclared name' => ['Fixtures\Helpers\absentHelper', NameKind::Function_, false];
        // A PSR-4 class is reachable, but not by *this* backend: it addresses only
        // what the `files` set declares, and the autoload maps cover the rest.
        yield 'psr-4 class' => ['Fixtures\Domain\User', NameKind::ClassLike, false];
    }

    #[DataProvider('declaredNames')]
    public function testLookupResolvesWhatTheAutoloadFilesSetDeclares(
        string $fullyQualifiedName,
        NameKind $kind,
        bool $shouldResolve,
    ): void {
        $info = self::symbolOfKindIn(self::backendForRoot(self::FIXTURES_ROOT), $fullyQualifiedName, $kind);

        if ($shouldResolve) {
            self::assertNotNull($info, "a name declared in the files set must resolve: {$fullyQualifiedName}");
            return;
        }

        self::assertNull($info, "a name the files set does not declare has no declaring info: {$fullyQualifiedName}");
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
        yield 'constant under a recased namespace' => ['FIXTURES\HELPERS\HELPER_LIMIT', NameKind::Constant, true];
        yield 'constant with a recased short name' => ['Fixtures\Helpers\helper_limit', NameKind::Constant, false];
    }

    #[DataProvider('caseVariants')]
    public function testLookupAppliesThePerKindCaseRule(
        string $fullyQualifiedName,
        NameKind $kind,
        bool $shouldResolve,
    ): void {
        $info = self::symbolOfKindIn(self::backendForRoot(self::FIXTURES_ROOT), $fullyQualifiedName, $kind);

        if ($shouldResolve) {
            self::assertNotNull($info, 'only a constant short name is matched case-sensitively');
            return;
        }

        self::assertNull($info, 'a constant short name in another case is another constant');
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
     * things. An index that ignored the kind would answer with the wrong info.
     */
    #[DataProvider('mismatchedKinds')]
    public function testLookupResolvesOnlyForTheKindThatDeclaredTheName(
        string $fullyQualifiedName,
        NameKind $kind,
    ): void {
        self::assertNull(
            self::symbolOfKindIn(self::backendForRoot(self::FIXTURES_ROOT), $fullyQualifiedName, $kind),
            'a name declared in one symbol namespace must not resolve in another',
        );
    }

    public function testAProjectWithNoAutoloadFilesResolvesNothing(): void
    {
        self::assertNull(
            self::backendForRoot('/nonexistent/path')
                ->lookupConstant(ConstantName::fromFullyQualified('FIXTURE_GLOBAL_LIMIT')),
            'a project with no autoload.files map indexes nothing, and is not an error',
        );
    }

    public function testAnEntryThatCannotBeReadIsSkippedWithoutAbandoningTheRest(): void
    {
        // One bad entry must not cost the project the entries after it.
        $readable = self::tempFile('<?php const SURVIVES_A_BAD_ENTRY = 1;');

        try {
            $backend = self::backendForMap(
                new ComposerAutoloadMap([], [], [], [self::FIXTURES_ROOT . '/no-such-entry.php', $readable]),
            );

            self::assertNull(
                self::classLikeIn($backend, 'Whatever'),
                'an unreadable files entry contributes nothing rather than throwing',
            );
            self::assertNotNull(
                $backend->lookupConstant(
                    ConstantName::fromFullyQualified('SURVIVES_A_BAD_ENTRY'),
                ),
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
        // Composer requires the entries in order, so the first file's guard is
        // the one that passes.
        $polyfill = '<?php if (!function_exists("dupeTarget")) { function dupeTarget(): int { return %d; } }';
        $first = self::tempFile(sprintf($polyfill, 1));
        $second = self::tempFile(sprintf($polyfill, 2));

        try {
            $info = self::functionIn(
                self::backendForMap(new ComposerAutoloadMap([], [], [], [$first, $second])),
                'dupeTarget',
            );

            self::assertNotNull($info, 'the guarded polyfill must resolve');
            self::assertSame(
                $first,
                $info->file,
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
            $backend = self::backendForMap(new ComposerAutoloadMap([], [], [], [$path]));
            self::assertNotNull(
                $backend->lookupConstant(ConstantName::fromFullyQualified('BEFORE_CHANGE')),
                'the eagerly built index must reflect the file as it was read',
            );

            self::assertNotFalse(file_put_contents($path, '<?php const AFTER_CHANGE = 2;'), 'rewrite must succeed');
            $backend->invalidate(FileUri::fromPath($path));

            self::assertNotNull(
                $backend->lookupConstant(ConstantName::fromFullyQualified('AFTER_CHANGE')),
                'a name added by an external edit must resolve after invalidation (RFC 1 §5.2)',
            );
            self::assertNull(
                $backend->lookupConstant(ConstantName::fromFullyQualified('BEFORE_CHANGE')),
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
            $backend = self::backendForMap(new ComposerAutoloadMap([], [], [], [$path]));

            // Delete first: from here a rebuild can only drop the name.
            unlink($path);
            $backend->invalidate('file:///some/other/file.php');

            self::assertNotNull(
                $backend->lookupConstant(ConstantName::fromFullyQualified('UNTOUCHED')),
                'a change outside the files set must not cost a rebuild of the whole index',
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * The exact contents of each namespace the fixture `files` entries reach.
     * Exact rather than containment, so a name the scan must *not* reach — a
     * computed `define()`, a `define()` value mistaken for its name, an
     * anonymous class — fails here as loudly as a missing one.
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
                ['FIXTURE_HELPER_DEFINED', 'Constant'],
            ],
        ];

        yield 'a namespace the set does not reach' => ['Fixtures\Domain', [], []];
    }

    /**
     * @param list<string> $expectedChildNamespaces
     * @param list<array{string, string}> $expectedSymbols
     */
    #[DataProvider('enumeratedNamespaces')]
    public function testChildrenOfEnumeratesWhatTheAutoloadFilesSetDeclares(
        string $namespace,
        array $expectedChildNamespaces,
        array $expectedSymbols,
    ): void {
        $contents = self::backendForRoot(self::FIXTURES_ROOT)->childrenOf(new NamespaceName($namespace));

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
        $contents = self::backendForRoot(self::FIXTURES_ROOT)->childrenOf(new NamespaceName('Fixtures\Helpers'));

        $fqns = array_map(
            static fn(CatalogSymbol $symbol): string => $symbol->fullyQualifiedName,
            $contents->symbols,
        );
        self::assertContains('Fixtures\Helpers\HelperRegistry', $fqns, 'a class-like keeps its declared casing');
        self::assertContains('Fixtures\Helpers\helperFormat', $fqns, 'a function keeps its declared casing');
    }

    public function testChildrenOfMatchesANamespaceInAnyCase(): void
    {
        $backend = self::backendForRoot(self::FIXTURES_ROOT);

        self::assertEquals(
            $backend->childrenOf(new NamespaceName('Fixtures\Helpers')),
            $backend->childrenOf(new NamespaceName('FIXTURES\helpers')),
            'PHP namespaces are case-insensitive, so one namespace is not two listings',
        );
    }

    public function testInvalidateRebuildsWhatIsEnumeratedAsWellAsWhatIsLocated(): void
    {
        $path = self::tempFile('<?php namespace Rebuilt; class Before {}');

        try {
            $backend = self::backendForMap(new ComposerAutoloadMap([], [], [], [$path]));
            self::assertSame(
                [['Rebuilt\Before', 'ClassLike']],
                self::asPairs($backend->childrenOf(new NamespaceName('Rebuilt'))),
                'the eagerly built index must enumerate the file as it was read',
            );

            self::assertNotFalse(
                file_put_contents($path, '<?php namespace Rebuilt; class After {}'),
                'rewrite must succeed',
            );
            $backend->invalidate(FileUri::fromPath($path));

            self::assertSame(
                [['Rebuilt\After', 'ClassLike']],
                self::asPairs($backend->childrenOf(new NamespaceName('Rebuilt'))),
                'enumeration must reflect the rebuilt index, not a memo of the pre-change one',
            );
        } finally {
            unlink($path);
        }
    }

    public function testSearchFindsFunctionsByShortNamePrefix(): void
    {
        $results = self::backendForRoot(self::FIXTURES_ROOT)->search('helperF', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'Fixtures\Helpers\helperFormat',
            $fqns,
            'a function whose short name starts with the prefix must be found',
        );
    }

    public function testSearchFindsConstantsByShortNamePrefix(): void
    {
        $results = self::backendForRoot(self::FIXTURES_ROOT)->search('HELPER_L', NameKind::Constant);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'Fixtures\Helpers\HELPER_LIMIT',
            $fqns,
            'a constant whose short name starts with the prefix must be found',
        );
    }

    /**
     * Class-likes declared in `files` entries are indexed alongside functions and
     * constants, so a bare-prefix search over the same store returns them too —
     * the reach of every read is the same set.
     */
    public function testSearchFindsClassLikesFromAutoloadFiles(): void
    {
        $results = self::backendForRoot(self::FIXTURES_ROOT)->search('HelperR', NameKind::ClassLike);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'Fixtures\Helpers\HelperRegistry',
            $fqns,
            'a class-like declared in an autoload.files entry must be found by prefix search',
        );
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $results = self::backendForRoot(self::FIXTURES_ROOT)->search('HELPERF', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains(
            'Fixtures\Helpers\helperFormat',
            $fqns,
            'prefix matching is case-insensitive because the user has not finished typing',
        );
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        self::assertSame(
            [],
            self::backendForRoot(self::FIXTURES_ROOT)->search('zzNoMatch', NameKind::Function_),
            'a prefix that matches nothing must return an empty list',
        );
    }

    public function testSearchReturnsSymbolsWithCorrectKind(): void
    {
        $results = self::backendForRoot(self::FIXTURES_ROOT)->search('helperF', NameKind::Function_);

        self::assertNotEmpty($results, 'the prefix must match at least one function');
        foreach ($results as $symbol) {
            self::assertSame(
                SymbolKind::Function_,
                $symbol->kind,
                'every symbol returned for a Function_ search must carry SymbolKind::Function_',
            );
        }
    }

    public function testSearchReturnsSymbolsWithFileLocation(): void
    {
        $results = self::backendForRoot(self::FIXTURES_ROOT)->search('helperF', NameKind::Function_);

        self::assertNotEmpty($results, 'the prefix must match at least one function');
        foreach ($results as $symbol) {
            self::assertNotSame(
                '',
                $symbol->location->uri,
                'a symbol from an autoload.files entry must carry its declaring file',
            );
        }
    }

    public function testSearchDoesNotCrossKindBoundaries(): void
    {
        $results = self::backendForRoot(self::FIXTURES_ROOT)->search('Helper', NameKind::Function_);

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

    private static function backendForRoot(string $projectRoot): AutoloadFilesBackend
    {
        return self::backendForMap(ComposerAutoloadMap::fromProjectRoot($projectRoot));
    }

    private static function backendForMap(ComposerAutoloadMap $map): AutoloadFilesBackend
    {
        $production = ProductionSyntaxSource::create();

        return new AutoloadFilesBackend(
            $map,
            new DeclarationSymbolInfoFactory(),
            new DeclarationScanner(),
            $production->reader,
            $production->source,
        );
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
