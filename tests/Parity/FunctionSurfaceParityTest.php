<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Completion\ClassCandidateFilter;
use Firehed\PhpLsp\Completion\SymbolCandidates;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Knowledge\SymbolSink;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\TestCase;

/**
 * Golden parity for the function-completion surface — `SymbolCandidates::find()`
 * with `[NameKind::Function_]`.
 *
 * ## Determinism
 *
 * The built-in function list is version- and extension-fragile, so a broad prefix
 * cannot be frozen: `arr` alone shifts between 8.3, 8.4 and 8.5. Every prefix
 * queried below is instead narrow enough that its built-in matches are a single
 * long-stable core function, in the same way `ChildrenOfParityTest` freezes only
 * namespaces that hold no reflected symbols. The version-fragile *breadth* is
 * covered by {@see testBuiltinFunctionsReachTheSurfaceInBulk} and, name for name,
 * by {@see BuiltinFunctionParityTest}.
 *
 * Order is part of the surface too — document functions precede built-ins, which
 * is what puts a project's own helper above a similarly-named built-in. No golden
 * query can freeze that (the prefixes narrow enough to be stable match one half or
 * the other, never both), so it is asserted separately in
 * {@see testDocumentFunctionsPrecedeBuiltins}.
 *
 * See docs/architecture/0002-execution-plan.md, Step P and Step 3b; RFC 1 §4.2.
 */
final class FunctionSurfaceParityTest extends TestCase
{
    use AssertsGolden;

    /**
     * The fixture whose top-level functions the surface should report: one with a
     * docblock and typed parameters (so `detail` and `documentation` are frozen,
     * not just the label), one without.
     */
    private const string DOCUMENT_WITH_FUNCTIONS = 'src/Completion/FunctionCompletion.php';

    /**
     * A fixture declaring no top-level functions, used to prove the document half
     * of the surface is scoped to the document asked about rather than to
     * everything indexed.
     */
    private const string DOCUMENT_WITHOUT_FUNCTIONS = 'src/Domain/User.php';

    private string $fixturesRoot;
    private SymbolSource $symbolSource;
    private SymbolSink $sink;
    private SymbolResolver $symbolResolver;

    protected function setUp(): void
    {
        $this->fixturesRoot = dirname(__DIR__) . '/Fixtures';

        $production = ProductionSyntaxSource::create();
        $parser = $production->source;
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($this->fixturesRoot),
            $this->fixturesRoot . '/vendor',
            $parser,
            $production->reader,
            new SymbolIndex(),
        );

        $this->symbolSource = $knowledge->source;
        $this->sink = $knowledge->sink;

        $memberResolver = new MemberResolver($knowledge->source);
        $this->symbolResolver = new SymbolResolver(
            $parser,
            $knowledge->source,
            $memberResolver,
        );
    }

    public function testFunctionCompletionMatchesGolden(): void
    {
        $queries = [
            // A document function: label, signature detail, and the description
            // lifted from its docblock.
            'document|calc' => [self::DOCUMENT_WITH_FUNCTIONS, 'calc', false],
            // The same query with snippet support declared, which is the only
            // capability that reshapes an item on this surface (RFC 1 §4.8).
            'document|calc+snippet' => [self::DOCUMENT_WITH_FUNCTIONS, 'calc', true],
            // Prefix matching is case-insensitive; a case-sensitive regression
            // returns nothing here.
            'document|CALCULATE' => [self::DOCUMENT_WITH_FUNCTIONS, 'CALCULATE', false],
            // A document function with no docblock: no `documentation` key, and a
            // return type the signature detail must still carry.
            'document|getConfig' => [self::DOCUMENT_WITH_FUNCTIONS, 'getConfig', false],
            // Built-ins reach the surface, and carry no signature detail today.
            'builtin|str_contains' => [self::DOCUMENT_WITH_FUNCTIONS, 'str_contains', false],
            'builtin|str_contains+snippet' => [self::DOCUMENT_WITH_FUNCTIONS, 'str_contains', true],
            'builtin|array_map' => [self::DOCUMENT_WITH_FUNCTIONS, 'array_map', false],
            // The document half is scoped to the document asked about: this one
            // declares no functions, and no built-in matches the prefix.
            'other-document|calc' => [self::DOCUMENT_WITHOUT_FUNCTIONS, 'calc', false],
            // A prefix nothing matches, so an over-eager source shows up as a diff.
            'no-match|zzzz' => [self::DOCUMENT_WITH_FUNCTIONS, 'zzzz', false],
        ];

        $captured = [];
        foreach ($queries as $label => [$fixture, $prefix, $snippetSupport]) {
            $doc = $this->document($fixture);
            $captured[$label] = $this->candidates($snippetSupport)->find(
                $prefix,
                $doc,
                5,
                strlen($prefix),
                [NameKind::Function_],
                ClassCandidateFilter::Any,
            );
        }

        $this->assertGoldenMatches('function-surface', $captured);
    }

    public function testBuiltinFunctionsReachTheSurfaceInBulk(): void
    {
        // The golden's prefixes are narrow by necessity, so on their own they
        // could stay green while the built-in half collapsed to a handful of
        // names. The exact set is version-fragile and is asserted against
        // reflection in BuiltinFunctionParityTest; here only that the surface
        // passes through the bulk of it.
        $doc = $this->document(self::DOCUMENT_WITHOUT_FUNCTIONS);
        $items = $this->candidates(false)->find(
            'array_',
            $doc,
            5,
            0,
            [NameKind::Function_],
            ClassCandidateFilter::Any,
        );

        $labels = array_column($items, 'label');

        self::assertGreaterThan(
            50,
            count($labels),
            'the built-in array_* family must reach the completion surface, not a token few',
        );
        self::assertContains(
            'array_filter',
            $labels,
            'a built-in matched only by a broad prefix must still be offered',
        );
    }

    public function testDocumentFunctionsPrecedeBuiltins(): void
    {
        // An empty prefix matches everything, so the tail is the whole
        // version-fragile built-in list — but the head is not: the document's own
        // functions are emitted first, in declaration order. A migration that
        // merged the two halves into one ranked list would reorder this.
        $doc = $this->document(self::DOCUMENT_WITH_FUNCTIONS);
        $items = $this->candidates(false)->find(
            '',
            $doc,
            5,
            0,
            [NameKind::Function_],
            ClassCandidateFilter::Any,
        );

        self::assertSame(
            ['calculateSum', 'getConfig'],
            array_slice(array_column($items, 'label'), 0, 2),
            'a document\'s own functions must be offered ahead of the built-ins',
        );
    }

    private function candidates(bool $snippetSupport): SymbolCandidates
    {
        $capabilities = self::createStub(SessionCapabilitiesProvider::class);
        $capabilities->method('getSessionCapabilities')
            ->willReturn(new SessionCapabilities(snippetSupport: $snippetSupport));

        return new SymbolCandidates($this->symbolSource, $this->symbolResolver, $capabilities);
    }

    private function document(string $relativePath): TextDocument
    {
        $path = $this->fixturesRoot . '/' . $relativePath;
        $content = file_get_contents($path);
        self::assertNotFalse($content, "fixture document should be readable: {$relativePath}");

        $doc = new TextDocument('file://' . $path, 'php', 0, $content);
        $this->sink->openDocument($doc);
        return $doc;
    }
}
