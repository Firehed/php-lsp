<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
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
 * RFC 1 §8.1's mechanism for §5.1 (uniform coverage across kinds): a **backend ×
 * kind × query** grid over the stack that actually ships.
 *
 * Both axes are **derived, not listed** — rows from
 * {@see CompositeSymbolSource::$backends}, columns from {@see NameKind::cases()}
 * crossed with {@see GridQuery::cases()} — so a new kind or a new backend adds cells
 * that did not exist when this file was written. Every cell either answers over the
 * fixtures or is registered in {@see NOT_APPLICABLE} against a named blocker, and an
 * **unregistered cell fails**. A hand-listed grid would enforce nothing a
 * hand-written prose rule did not.
 *
 * The registration is checked in both directions: a cell that answers while still
 * registered fails too, so a blocker cannot outlive the gap it describes. Step Z
 * requires every survivor to still name a live deferral.
 *
 * A backend appears once per class. The workspace and vendor
 * {@see FilesystemBackend}s differ only in which autoload-map subset they hold, so
 * their coverage cannot diverge; what differs is reach, which the parity goldens
 * cover.
 */
final class SymbolCoverageGridTest extends TestCase
{
    /**
     * Cells the shipped stack cannot answer, each naming a slice id or an RFC
     * section. Keyed `<backend>|<kind>|<query>`.
     *
     * @var array<string, string>
     */
    private const array NOT_APPLICABLE = [
        // Global-constant lookup has no info type yet; the kind reaches the
        // backends, and S3.8b lands the type and the Domain\ConstantName naming
        // decision it forces.
        'OpenDocumentBackend|Constant|lookup' => 'S3.8b',
        'FilesystemBackend|Constant|lookup' => 'S3.8b',
        'BuiltinBackend|Constant|lookup' => 'S3.8b',

        // `searchClassLikes` has no kind parameter: S3.9a widens it, S3.9b makes the
        // backends answer function search.
        'OpenDocumentBackend|Function_|search' => 'S3.9a, S3.9b',
        'OpenDocumentBackend|Constant|search' => 'S3.9a, S3.8b',
        'FilesystemBackend|Function_|search' => 'S3.9a, S3.9b',
        'FilesystemBackend|Constant|search' => 'S3.9a, S3.8b',
        'BuiltinBackend|Function_|search' => 'S3.9a, S3.9b',
        'BuiltinBackend|Constant|search' => 'S3.9a, S3.8b',

        // A prefix has no name -> file map, so project-wide search over disk needs
        // the workspace walk RFC 1 §3 defers. Built-in search is deliberately empty:
        // offering a name that does not resolve unqualified is auto-import.
        'FilesystemBackend|ClassLike|search' => 'RFC 1 §3',
        'BuiltinBackend|ClassLike|search' => 'RFC 1 §3',

        // `SymbolExtractor` emits no `SymbolKind::Constant`, so an open document's
        // global constants never reach the index this enumeration reads — while both
        // on-disk and built-in enumeration report constants. Found by this grid.
        'OpenDocumentBackend|Constant|childrenOf' => 'SC.16',
    ];

    /**
     * The name each backend should resolve for each kind, and the namespace it
     * should enumerate it under. A missing entry fails rather than skipping: that
     * is how a newly added kind or backend is forced to declare its coverage.
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
     * Declares one name of each kind, so the open-document row has something to
     * answer for. Written as a document rather than a fixture file because the
     * point is what the *editor* holds, which no file on disk can stand in for.
     */
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
        // The mechanism itself: with nothing registered, every cell the stack cannot
        // answer must surface. A grid that reported none would pass whatever the
        // stack did.
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
        // The other direction: a cell that does answer must not keep a blocker, or a
        // closed gap stays recorded as open and Step Z cannot tell the two apart.
        $answering = 'BuiltinBackend|ClassLike|lookup';
        ['stale' => $stale] = $this->evaluate([$answering => 'a blocker that no longer applies']);

        self::assertContains(
            $answering . ' (registered against a blocker that no longer applies)',
            $stale,
            'a registration on a cell that answers must be reported as stale',
        );
    }

    public function testEveryRegistrationNamesABlocker(): void
    {
        foreach (self::NOT_APPLICABLE as $cell => $blocker) {
            self::assertNotSame('', $blocker, "the not-applicable cell {$cell} must name its blocker");
        }
    }

    /**
     * Walk every cell against a registry, reporting the two ways a cell and its
     * registration can disagree. Taking the registry as an argument is what lets the
     * mechanism be tested rather than only used.
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
     * The grid's rows: one per backend class in the shipped composite, keyed by
     * short name. Derived from the composition itself, so adding a backend adds a
     * row whose cells are unregistered until they are declared.
     *
     * @return array<string, SymbolBackend>
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
            GridQuery::Lookup => $backend->lookup(QualifiedName::fromFullyQualified($fqn), $kind) !== null,
            GridQuery::Search => $this->searchFinds($backend, $fqn),
            GridQuery::ChildrenOf => $this->enumerates($backend, $probe['namespace'], $kind, $fqn),
        };
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
