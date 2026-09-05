<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\CodeResolver;
use Firehed\PhpLsp\Resolution\DefaultTextSymbolExtractor;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Tests\Handler\OpensDocumentsTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use LogicException;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\ParserFactory;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The parse-health grid (build-manifest step-33; RFC 1 §4.11).
 *
 * Every position-taking method on {@see CodeResolver} — derived by reflection so
 * a new method is a new row without an edit to the test — is exercised at one
 * scenario in three parse states:
 *
 *   clean      — the fixture parses without error.
 *   recovered  — the parser reports errors but yields statements.
 *   empty      — the parser yields no statements.
 *
 * The three fixtures are the same scenario — the same class shape, the same
 * `strlen($this->` at the cursor — differing only in how broken the text after
 * the cursor is, and {@see self::testStatePreconditionHolds} pins each state's
 * parse outcome so a fixture edit cannot silently merge two states. Each cell
 * asserts a single observable appropriate to the method — the enclosing
 * namespace, the enclosing class, `$this` in scope, or the receiver type of
 * `$this->`. A method that answers null in a state, or that returns something
 * inconsistent with the state's fixture, fails its cell.
 *
 * Cells that fail today are listed in {@see self::EMPTY_STATE_SKIPS} with the
 * step that clears them; they still run, and a listed cell that passes fails
 * the test so the entry goes with the fix.
 */
#[CoversNothing]
final class ParseHealthGridTest extends TestCase
{
    use OpensDocumentsTrait;

    /**
     * @var array<string, array{
     *   fixture: string,
     *   marker: string,
     *   namespace: string,
     *   class: string,
     *   parserReportsErrors: bool,
     *   yieldsStatements: bool,
     * }>
     */
    private const array STATES = [
        'clean' => [
            'fixture' => 'src/ParseHealth/Clean.php',
            'marker' => 'this_in_if',
            'namespace' => 'Fixtures\\ParseHealth',
            'class' => 'Fixtures\\ParseHealth\\Clean',
            'parserReportsErrors' => false,
            'yieldsStatements' => true,
        ],
        'recovered' => [
            'fixture' => 'src/ParseHealth/Recovered.php',
            'marker' => 'this_in_if',
            'namespace' => 'Fixtures\\ParseHealth',
            'class' => 'Fixtures\\ParseHealth\\Recovered',
            'parserReportsErrors' => true,
            'yieldsStatements' => true,
        ],
        'empty' => [
            'fixture' => 'src/ParseHealth/EmptyParse.php',
            'marker' => 'this_in_if',
            'namespace' => 'Fixtures\\ParseHealth',
            'class' => 'Fixtures\\ParseHealth\\EmptyParse',
            'parserReportsErrors' => true,
            'yieldsStatements' => false,
        ],
    ];

    /**
     * Empty-state cells that fail today, each with the manifest step that clears
     * it. A cell listed here runs and is skipped only when it fails; a listed cell
     * that passes fails the test so the entry is removed with the fix.
     *
     * @var array<string, string>
     */
    private const array EMPTY_STATE_SKIPS = [
        'resolveAtPosition' => 'step-40',
    ];

    private DocumentManager $documents;
    private MemoizingSyntaxSource $parser;
    private CodeResolver $resolver;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $production = ProductionSyntaxSource::create();
        $this->parser = $production->source;
        $this->documents = new DocumentManager();

        $fixturesRoot = dirname(__DIR__) . '/Fixtures';
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($fixturesRoot),
            $fixturesRoot . '/vendor',
            $this->parser,
            $production->reader,
            textExtractor: new DefaultTextSymbolExtractor(),
        );
        $this->resolver = new SymbolResolver(
            parser: $this->parser,
            symbolSource: $knowledge->source,
            memberResolver: new MemberResolver($knowledge->source),
        );
        $this->syncHandler = new TextDocumentSyncHandler($this->documents, $knowledge->sink);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function states(): iterable
    {
        foreach (array_keys(self::STATES) as $state) {
            yield $state => [$state];
        }
    }

    /**
     * Each state is defined by its parse outcome, not by its file name. A fixture
     * that drifts into another state's outcome makes two columns of the grid the
     * same column, so the outcome is pinned here.
     */
    #[DataProvider('states')]
    public function testStatePreconditionHolds(string $state): void
    {
        $config = self::STATES[$state];
        $content = file_get_contents(dirname(__DIR__) . '/Fixtures/' . $config['fixture']);
        self::assertIsString($content, "unable to read {$config['fixture']}");

        $errors = new Collecting();
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($content, $errors) ?? [];

        self::assertSame(
            $config['parserReportsErrors'],
            $errors->hasErrors(),
            "{$config['fixture']} must "
                . ($config['parserReportsErrors'] ? 'make the parser report an error' : 'parse without error'),
        );
        self::assertSame(
            $config['yieldsStatements'],
            $statements !== [],
            "{$config['fixture']} must " . ($config['yieldsStatements']
                ? 'yield statements'
                : 'yield zero statements; if it now parses, the empty column no longer exercises the '
                    . 'empty-parse path — break the fixture further or the grid loses coverage'),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function grid(): iterable
    {
        foreach (self::positionTakingMethodNames() as $method) {
            foreach (array_keys(self::STATES) as $state) {
                yield "{$method}/{$state}" => [$method, $state];
            }
        }
    }

    #[DataProvider('grid')]
    public function testCellAnswersConsistentlyWithFixture(string $method, string $state): void
    {
        $config = self::STATES[$state];
        $cursor = $this->openFixtureAtCursor($config['fixture'], $config['marker']);
        $document = $this->documents->get($cursor['uri']);
        self::assertNotNull($document, 'fixture must be open');

        $step = $state === 'empty' ? self::EMPTY_STATE_SKIPS[$method] ?? null : null;

        try {
            match ($method) {
                'resolveAtPosition' => $this->assertResolveAtPosition($cursor, $config),
                'getMemberAccessContext' => $this->assertMemberAccessContext($cursor, $config),
                'getVariablesInScope' => $this->assertVariablesInScope($cursor, $config),
                'getCallContext' => $this->assertCallContext($cursor, $config),
                'getNameContext' => $this->assertNameContext($cursor, $config),
                default => throw new LogicException(
                    "no dispatch for {$method} — add a case when introducing a new position-taking CodeResolver method",
                ),
            };
        } catch (AssertionFailedError $failure) {
            if ($step === null) {
                throw $failure;
            }
            // A recorded gap: the cell fails today and the named step clears it.
            self::markTestSkipped("empty-state {$method} cell waits on {$step}: {$failure->getMessage()}");
        }

        self::assertNull(
            $step,
            "empty-state {$method} cell passes; remove its entry from EMPTY_STATE_SKIPS",
        );
    }

    /**
     * @return list<string>
     */
    private static function positionTakingMethodNames(): array
    {
        $reflection = new ReflectionClass(CodeResolver::class);
        $names = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getName() === 'line') {
                    $names[] = $method->getName();
                    break;
                }
            }
        }
        return $names;
    }

    /**
     * @param array{uri: string, line: int, character: int} $cursor
     * @param array{fixture: string, marker: string, namespace: string, class: string} $config
     */
    private function assertResolveAtPosition(array $cursor, array $config): void
    {
        $document = $this->documents->get($cursor['uri']);
        self::assertNotNull($document);

        $line = $document->getLine($cursor['line']);
        $thisStart = strpos($line, '$this');
        self::assertNotFalse($thisStart, "fixture line must contain \$this: {$line}");

        $symbol = $this->resolver->resolveAtPosition(
            $document,
            $cursor['line'],
            $thisStart + 1,
        );

        self::assertNotNull(
            $symbol,
            "resolveAtPosition on \$this must answer in {$config['fixture']}",
        );
        $type = $symbol->getType();
        self::assertNotNull(
            $type,
            "resolveAtPosition on \$this must carry a type in {$config['fixture']}",
        );
        self::assertSame(
            $config['class'],
            $type->format(),
            "resolveAtPosition on \$this must report the enclosing class as its type in {$config['fixture']}",
        );
    }

    /**
     * @param array{uri: string, line: int, character: int} $cursor
     * @param array{fixture: string, marker: string, namespace: string, class: string} $config
     */
    private function assertMemberAccessContext(array $cursor, array $config): void
    {
        $document = $this->documents->get($cursor['uri']);
        self::assertNotNull($document);

        $context = $this->resolver->getMemberAccessContext(
            $document,
            $cursor['line'],
            $cursor['character'],
        );

        self::assertInstanceOf(
            MemberAccessContext::class,
            $context,
            "getMemberAccessContext at \$this-> must answer in {$config['fixture']}",
        );
        self::assertSame(
            $config['class'],
            $context->type->format(),
            "getMemberAccessContext must report the enclosing class as receiver type in {$config['fixture']}",
        );
    }

    /**
     * @param array{uri: string, line: int, character: int} $cursor
     * @param array{fixture: string, marker: string, namespace: string, class: string} $config
     */
    private function assertVariablesInScope(array $cursor, array $config): void
    {
        $document = $this->documents->get($cursor['uri']);
        self::assertNotNull($document);

        $variables = $this->resolver->getVariablesInScope(
            $document,
            $cursor['line'],
            $cursor['character'],
        );

        $names = array_map(static fn ($variable) => $variable->getName(), $variables);
        self::assertContains(
            'this',
            $names,
            "\$this must be in scope inside a method body in {$config['fixture']}",
        );
    }

    /**
     * @param array{uri: string, line: int, character: int} $cursor
     * @param array{fixture: string, marker: string, namespace: string, class: string} $config
     */
    private function assertCallContext(array $cursor, array $config): void
    {
        $document = $this->documents->get($cursor['uri']);
        self::assertNotNull($document);

        $context = $this->resolver->getCallContext(
            $document,
            $cursor['line'],
            $cursor['character'],
        );

        self::assertInstanceOf(
            CallContext::class,
            $context,
            "getCallContext must answer inside a call in {$config['fixture']}",
        );
    }

    /**
     * @param array{uri: string, line: int, character: int} $cursor
     * @param array{fixture: string, marker: string, namespace: string, class: string} $config
     */
    private function assertNameContext(array $cursor, array $config): void
    {
        $document = $this->documents->get($cursor['uri']);
        self::assertNotNull($document);

        $context = $this->resolver->getNameContext($document, $cursor['line']);

        self::assertSame(
            $config['namespace'],
            $context->namespace,
            "getNameContext must report the enclosing namespace in {$config['fixture']}",
        );
    }
}
