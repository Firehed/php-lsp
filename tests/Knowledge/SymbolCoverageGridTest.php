<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\CompositeSymbolSource;
use Firehed\PhpLsp\Knowledge\FilesystemBackend;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Knowledge\SymbolBackend;
use Firehed\PhpLsp\Parser\ParserService;
use PHPUnit\Framework\TestCase;

/**
 * RFC 1 §8.1's mechanism for §5.1: a backend × kind × query grid whose backend and
 * kind axes are derived rather than listed, so a new kind or backend adds cells this
 * file never anticipated. The query axis is listed ({@see GridQuery}). Every cell
 * answers over the fixtures or names a blocker in
 * {@see NOT_APPLICABLE}; an unregistered cell fails, so does a registration on a cell
 * that answers, and so does a blocker naming no row of the slice registry.
 *
 * One row per backend class: the workspace and vendor {@see FilesystemBackend}s
 * differ only in autoload-map subset, so their coverage cannot diverge.
 */
final class SymbolCoverageGridTest extends TestCase
{
    /** The forms a blocker may take when no slice owns the gap: an issue, or a section. */
    private const string UNOWNED_BLOCKER = '/^(#\d+|(RFC 1|Plan 0002) §\d+(\.\d+)*)$/u';

    /**
     * Cells the shipped stack cannot answer, each naming a slice id or an RFC
     * section. Keyed `<backend>|<kind>|<query>`.
     *
     * @var array<string, string>
     */
    private const array NOT_APPLICABLE = [
        // The kind reaches the backends; the info type does not exist yet.
        'OpenDocumentBackend|Constant|lookup' => 'S3.8b',
        'FilesystemBackend|Constant|lookup' => 'S3.8b',
        'BuiltinBackend|Constant|lookup' => 'S3.8b',

        // `searchClassLikes` has no kind parameter until S3.9a.
        'OpenDocumentBackend|Function_|search' => 'S3.9a, S3.9b',
        'OpenDocumentBackend|Constant|search' => 'S3.9a, S3.8b',
        'FilesystemBackend|Function_|search' => 'S3.9a, S3.9b',
        'FilesystemBackend|Constant|search' => 'S3.9a, S3.8b',
        'BuiltinBackend|Function_|search' => 'S3.9a, S3.9b',
        'BuiltinBackend|Constant|search' => 'S3.9a, S3.8b',

        // A prefix has no name -> file map on disk. The built-in row is blocked on
        // something else entirely: the name it would offer does not resolve
        // unqualified, so the item is only useful once completion can insert the
        // import with it.
        'FilesystemBackend|ClassLike|search' => 'RFC 1 §3',
        'BuiltinBackend|ClassLike|search' => '#23',
    ];

    /**
     * The name each backend should resolve per kind, and the namespace it sits in. A
     * missing entry fails rather than skipping, which is what forces a new kind or
     * backend to declare its coverage.
     *
     * @var array<string, array<string, array{name: string, namespace: string}>>
     */
    private const array PROBES = [
        'OpenDocumentBackend' => [
            'ClassLike' => ['name' => 'Grid\GridWidget', 'namespace' => 'Grid'],
            'Function_' => ['name' => 'Grid\gridHelper', 'namespace' => 'Grid'],
            'Constant' => ['name' => 'Grid\GRID_LIMIT', 'namespace' => 'Grid'],
        ],
        'FilesystemBackend' => [
            'ClassLike' => ['name' => 'Fixtures\Domain\User', 'namespace' => 'Fixtures\Domain'],
            'Function_' => ['name' => 'Fixtures\Helpers\helperFormat', 'namespace' => 'Fixtures\Helpers'],
            'Constant' => ['name' => 'Fixtures\Helpers\HELPER_LIMIT', 'namespace' => 'Fixtures\Helpers'],
        ],
        'BuiltinBackend' => [
            'ClassLike' => ['name' => 'ArrayObject', 'namespace' => ''],
            'Function_' => ['name' => 'str_contains', 'namespace' => ''],
            'Constant' => ['name' => 'PHP_INT_MAX', 'namespace' => ''],
        ],
    ];

    /**
     * The concrete type a lookup of each kind must answer with, so a cell counts as
     * covered only when the backend answered for the kind it was asked about — §5.1
     * requires a concrete return type, and the composite's narrowing `assert()` is
     * gone in production. Null while the kind has no info type yet.
     *
     * @var array<string, ?class-string>
     */
    private const array INFO_TYPES = [
        'ClassLike' => ClassInfo::class,
        'Function_' => FunctionInfo::class,
        'Constant' => null,
    ];

    /** One name of each kind for the open-document row, which no on-disk file can stand in for. */
    private const string OPEN_DOCUMENT = <<<'PHP'
        <?php

        namespace Grid;

        const GRID_LIMIT = 1;

        function gridHelper(): void
        {
        }

        final class GridWidget
        {
        }
        PHP;

    private CompositeSymbolSource $source;

    protected function setUp(): void
    {
        $fixturesRoot = dirname(__DIR__) . '/Fixtures';
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($fixturesRoot),
            $fixturesRoot . '/vendor',
            new ParserService(),
        );
        $knowledge->sink->openDocument(
            new TextDocument('file:///virtual/Grid.php', 'php', 1, self::OPEN_DOCUMENT),
        );

        self::assertInstanceOf(
            CompositeSymbolSource::class,
            $knowledge->source,
            'the grid derives its rows from the composite, so the stack must build one',
        );
        $this->source = $knowledge->source;
    }

    public function testEveryCellAnswersOrNamesItsBlocker(): void
    {
        ['unregistered' => $unregistered, 'stale' => $stale] = $this->evaluate(self::NOT_APPLICABLE);

        self::assertSame(
            [],
            $unregistered,
            'every backend x kind x query cell must answer or be registered not-applicable '
                . 'against a named blocker (RFC 1 §5.1, §8.1)',
        );
        self::assertSame(
            [],
            $stale,
            'a cell that now answers must lose its not-applicable registration, '
                . 'or the blocker outlives the gap (Step Z)',
        );
    }

    public function testAnUnregisteredCellIsReported(): void
    {
        // A grid that reported none would pass whatever the stack did.
        ['unregistered' => $unregistered] = $this->evaluate([]);

        $registered = array_keys(self::NOT_APPLICABLE);
        sort($registered);
        sort($unregistered);

        self::assertSame(
            $registered,
            $unregistered,
            'the cells that cannot be answered must be exactly the ones registered: '
                . 'an unregistered gap fails, and a registration for a cell that answers is stale',
        );
    }

    public function testARegistrationThatNoLongerBlocksIsReported(): void
    {
        // A closed gap that keeps its blocker reads as open, which Step Z cannot see.
        $answering = 'BuiltinBackend|ClassLike|lookup';
        ['stale' => $stale] = $this->evaluate([$answering => 'a blocker that no longer applies']);

        self::assertContains(
            $answering . ' (registered against a blocker that no longer applies)',
            $stale,
            'a registration on a cell that answers must be reported as stale',
        );
    }

    public function testEveryRegistrationNamesALiveBlocker(): void
    {
        self::assertSame(
            [],
            self::danglingBlockers(self::NOT_APPLICABLE),
            'a not-applicable cell must name a slice still in the registry, an issue, or a section: '
                . 'a blocker nobody owns is the permanent exemption Step Z exists to prevent',
        );
    }

    public function testABlockerNamingNoSliceIsReported(): void
    {
        // A registry that accepted any non-empty string would outlive the slice it names.
        self::assertSame(
            ['BuiltinBackend|Constant|lookup names S9.99'],
            self::danglingBlockers(['BuiltinBackend|Constant|lookup' => 'S9.99']),
            'a blocker matching no registry row, issue, or section must be reported',
        );
    }

    /**
     * @param array<string, string> $notApplicable
     * @return list<string> The `<cell> names <blocker>` pairs that resolve to nothing
     */
    private static function danglingBlockers(array $notApplicable): array
    {
        $slices = self::sliceIds();
        $dangling = [];

        foreach ($notApplicable as $cell => $blocker) {
            foreach (explode(', ', $blocker) as $named) {
                if (in_array($named, $slices, true) || preg_match(self::UNOWNED_BLOCKER, $named) === 1) {
                    continue;
                }
                $dangling[] = "{$cell} names {$named}";
            }
        }

        return $dangling;
    }

    /**
     * The registry is the manifest itself, so a blocker cannot outlive the row it
     * names by the row being renamed or dropped.
     *
     * @return list<string>
     */
    private static function sliceIds(): array
    {
        $manifest = file_get_contents(dirname(__DIR__, 2) . '/docs/architecture/build-manifest.md');
        self::assertNotFalse($manifest, 'the slice registry must be readable');

        preg_match_all('/^ {4}([A-Z][A-Z0-9]\.\d+[a-z]?) /m', $manifest, $matches);
        self::assertNotEmpty($matches[1], 'the slice table must be parseable, or every blocker reads as dangling');

        return $matches[1];
    }

    /**
     * The registry is an argument so the mechanism can be tested, not only used.
     *
     * @param array<string, string> $notApplicable
     * @return array{unregistered: list<string>, stale: list<string>}
     */
    private function evaluate(array $notApplicable): array
    {
        $unregistered = [];
        $stale = [];

        foreach ($this->rows() as $row => $backend) {
            foreach (NameKind::cases() as $kind) {
                foreach (GridQuery::cases() as $query) {
                    $cell = "{$row}|{$kind->name}|{$query->value}";
                    $answered = $this->answers($backend, $row, $kind, $query);

                    if (!$answered && !array_key_exists($cell, $notApplicable)) {
                        $unregistered[] = $cell;
                    }
                    if ($answered && array_key_exists($cell, $notApplicable)) {
                        $stale[] = $cell . ' (registered against ' . $notApplicable[$cell] . ')';
                    }
                }
            }
        }

        return ['unregistered' => $unregistered, 'stale' => $stale];
    }

    /**
     * @return array<string, SymbolBackend> Backend short name -> the first of its class
     */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->source->backends as $backend) {
            $parts = explode('\\', $backend::class);
            $rows[end($parts)] ??= $backend;
        }

        return $rows;
    }

    private function answers(SymbolBackend $backend, string $row, NameKind $kind, GridQuery $query): bool
    {
        $probe = self::PROBES[$row][$kind->name] ?? null;
        self::assertNotNull($probe, "no probe is defined for the {$row} x {$kind->name} cells");

        $fqn = $probe['name'];

        return match ($query) {
            GridQuery::Lookup => $this->looksUp($backend, $fqn, $kind),
            GridQuery::Search => $this->searchFinds($backend, $fqn),
            GridQuery::ChildrenOf => $this->enumerates($backend, $probe['namespace'], $kind, $fqn),
        };
    }

    private function looksUp(SymbolBackend $backend, string $fqn, NameKind $kind): bool
    {
        $info = $backend->lookup(QualifiedName::fromFullyQualified($fqn), $kind);
        if ($info === null) {
            return false;
        }

        $expected = self::INFO_TYPES[$kind->name] ?? null;
        self::assertNotNull(
            $expected,
            "{$kind->name} has no info type declared, so no backend may answer a lookup of it",
        );
        self::assertInstanceOf(
            $expected,
            $info,
            "a {$kind->name} lookup must answer with that kind's own metadata type (RFC 1 §5.1)",
        );

        return true;
    }

    private function searchFinds(SymbolBackend $backend, string $fqn): bool
    {
        $prefix = QualifiedName::fromFullyQualified($fqn)->shortName;

        foreach ($backend->searchClassLikes($prefix) as $symbol) {
            if ($symbol->fullyQualifiedName === $fqn) {
                return true;
            }
        }

        return false;
    }

    private function enumerates(SymbolBackend $backend, string $namespace, NameKind $kind, string $fqn): bool
    {
        foreach ($backend->childrenOf(new NamespaceName($namespace))->symbols as $symbol) {
            if ($symbol->kind === $kind && $symbol->fullyQualifiedName === $fqn) {
                return true;
            }
        }

        return false;
    }
}
