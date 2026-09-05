<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Completion\SymbolCandidates;
use Firehed\PhpLsp\Completion\VariableCandidates;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\DefaultTextSymbolExtractor;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\TestCase;

/**
 * Golden parity for completion output on files whose current text does not parse.
 *
 * Freezes the recovery paths the manifest calls out as load-bearing: first-open of
 * a broken file (no last-good registration to preserve) still offers members through
 * `TextFallbackHelper::extractMembers` and `MemberAccessDetector::walkChain`, and a
 * good open followed by a broken change offers them through the `DocumentSymbolSink`
 * preserved AST. A step that removes any of these paths must first land a
 * replacement that keeps this golden green.
 */
final class CompletionParityTest extends TestCase
{
    use AssertsGolden;
    use LoadsFixturesTrait;

    /**
     * Every broken-file cursor the handler tests already drive, so the frozen
     * output covers the same ground the unit tests exercise — completion output
     * on incomplete `src/IncompleteCode/` fixtures and top-level fixtures that
     * force the text branch (`TopLevel/*` with no enclosing class or a broken
     * self::). Each row is (fixture, marker); the captured completion is the
     * frozen invariant.
     *
     * @var array<string, array{string, string}>
     */
    private const array FIRST_OPEN_BROKEN = [
        // src/IncompleteCode/
        'aliased_imports_param' => ['src/IncompleteCode/AliasedImports.php', 'aliased_param'],
        'aliased_imports_static' => ['src/IncompleteCode/AliasedImports.php', 'aliased_static_access'],
        'aliased_imports_new' => ['src/IncompleteCode/AliasedImports.php', 'colliding_new'],
        'aliased_imports_colliding_static' => ['src/IncompleteCode/AliasedImports.php', 'colliding_static'],
        'broken_class_instance' => ['src/IncompleteCode/BrokenClassMembers.php', 'broken_instance'],
        'broken_class_static' => ['src/IncompleteCode/BrokenClassMembers.php', 'broken_static'],
        'broken_inheritance_instance' => ['src/IncompleteCode/BrokenInheritance.php', 'broken_inherited'],
        'broken_inheritance_static' => ['src/IncompleteCode/BrokenInheritance.php', 'broken_static_inherited'],
        'chained_in_if' => ['src/IncompleteCode/ChainedAccess.php', 'chained_in_if'],
        'chained_double_arrow' => ['src/IncompleteCode/ChainedAccess.php', 'double_arrow'],
        'chained_nonexistent' => ['src/IncompleteCode/ChainedAccess.php', 'nonexistent_chain'],
        'chained_untyped' => ['src/IncompleteCode/ChainedAccess.php', 'untyped_chain'],
        'group_imports_aliased_static' => ['src/IncompleteCode/GroupImports.php', 'group_aliased_static'],
        'group_imports_static' => ['src/IncompleteCode/GroupImports.php', 'group_static_access'],
        'group_imports_param' => ['src/IncompleteCode/GroupImports.php', 'group_user_param'],
        'in_control_nullable_param' => ['src/IncompleteCode/InControlStructures.php', 'nullable_param'],
        'in_control_primitive_param' => ['src/IncompleteCode/InControlStructures.php', 'primitive_param'],
        'in_control_this_prefix_if' => ['src/IncompleteCode/InControlStructures.php', 'this_prefix_if'],
        'in_control_var_access_while' => ['src/IncompleteCode/InControlStructures.php', 'var_access_while'],
        'parent_incomplete' => ['src/IncompleteCode/ParentAccess.php', 'parent_incomplete'],
        'parent_no_extends' => ['src/IncompleteCode/ParentAccess.php', 'parent_no_extends'],
        'single_incomplete_this_in_if' => ['src/IncompleteCode/SingleIncomplete.php', 'this_in_if'],
        // SingleIncompleteSigHelp's sig_this_call cursor is expression-start inside
        // a call's args; completion enumerates reflected built-in constants (AI_*,
        // E_*, SIGKILL, …) whose set differs by PHP version and enabled extensions,
        // so the row would fail the CI matrix by design. Kept out of the golden.
        'very_broken_this_arrow' => ['src/IncompleteCode/VeryBroken.php', 'this_in_if'],
        // TopLevel/ — force the text branch by design
        'toplevel_broken_self' => ['TopLevel/broken_self.php', 'broken_self_toplevel'],
        'toplevel_global_member_ns' => ['TopLevel/global_scope_completion_ns.php', 'global_member_access_ns'],
        'toplevel_global_member' => ['TopLevel/global_scope_completion.php', 'global_member_access'],
        'toplevel_global_nested' => ['TopLevel/global_scope_nested.php', 'nested_marker'],
        'toplevel_global_var' => ['TopLevel/global_scope_variable.php', 'global_var_prefix'],
        'toplevel_no_ast_static' => ['TopLevel/no_ast.php', 'empty_ast_static'],
        'toplevel_anon_class_static' => ['TopLevel/static_access.php', 'anon_class_static'],
        'toplevel_self' => ['TopLevel/static_access.php', 'toplevel_self'],
        'toplevel_static' => ['TopLevel/static_access.php', 'toplevel_static'],
        'toplevel_this_chained' => ['TopLevel/this_access.php', 'this_chained_toplevel'],
        'toplevel_this' => ['TopLevel/this_access.php', 'this_toplevel'],
    ];

    /**
     * Each case names a seed fixture (parses cleanly and declares the class the
     * broken text references), a broken fixture (whose text carries the cursor
     * marker), and the marker. The seed is opened first so the sink registers the
     * class; the broken text arrives via didChange and the sink's preserve rule
     * keeps the registration alive, so completion answers through the preserved
     * AST rather than any text member walker.
     *
     * @var array<string, array{string, string, string}>
     */
    private const array MID_EDIT_BROKEN = [
        'very_broken_this_arrow' => [
            'TopLevel/very_broken_seed.php',
            'src/IncompleteCode/VeryBroken.php',
            'this_in_if',
        ],
    ];

    public function testFirstOpenBrokenCompletionMatchesGolden(): void
    {
        $captured = [];
        foreach (self::FIRST_OPEN_BROKEN as $case => [$fixture, $marker]) {
            $captured[$case] = $this->captureFirstOpen($fixture, $marker);
        }

        $this->assertGoldenMatches('completion-broken-first-open', $captured);
    }

    public function testMidEditBrokenCompletionMatchesGolden(): void
    {
        $captured = [];
        foreach (self::MID_EDIT_BROKEN as $case => [$seed, $broken, $marker]) {
            $captured[$case] = $this->captureMidEdit($seed, $broken, $marker);
        }

        $this->assertGoldenMatches('completion-broken-mid-edit', $captured);
    }

    /**
     * @return list<array{label: string, kind: ?int}>
     */
    private function captureFirstOpen(string $fixture, string $marker): array
    {
        $harness = $this->buildHarness();
        $content = $this->loadFixture($fixture);
        $uri = 'file:///parity/' . $fixture;
        $harness->openDocument($uri, $content);

        $cursor = $this->locateCursor($content, $marker);

        return $this->completionAt($harness, $uri, $cursor['line'], $cursor['character']);
    }

    /**
     * @return list<array{label: string, kind: ?int}>
     */
    private function captureMidEdit(string $seed, string $broken, string $marker): array
    {
        $harness = $this->buildHarness();
        $seedContent = $this->loadFixture($seed);
        $brokenContent = $this->loadFixture($broken);
        $uri = 'file:///parity/' . $broken;

        $harness->openDocument($uri, $seedContent);
        $harness->changeDocument($uri, $brokenContent);

        $cursor = $this->locateCursor($brokenContent, $marker);

        return $this->completionAt($harness, $uri, $cursor['line'], $cursor['character']);
    }

    /**
     * @return list<array{label: string, kind: ?int}>
     */
    private function completionAt(CompletionHarness $harness, string $uri, int $line, int $character): array
    {
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $line, 'character' => $character],
            ],
        ]);
        $result = $harness->handler->handle($request);

        self::assertIsArray($result, 'completion at a broken cursor must return a result');
        self::assertArrayHasKey('items', $result, 'completion result must carry an items array');

        $items = [];
        foreach ($result['items'] as $item) {
            $items[] = ['label' => $item['label'], 'kind' => $item['kind'] ?? null];
        }
        usort(
            $items,
            static fn(array $a, array $b): int => [$a['label'], $a['kind']] <=> [$b['label'], $b['kind']],
        );

        return $items;
    }

    private function buildHarness(): CompletionHarness
    {
        $documents = new DocumentManager();
        $production = ProductionSyntaxSource::create();
        $parser = $production->source;
        $fixturesRoot = dirname(__DIR__) . '/Fixtures';
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($fixturesRoot),
            $fixturesRoot . '/vendor',
            $parser,
            $production->reader,
            textExtractor: ProductionSyntaxSource::defaultTextSymbolExtractor(),
        );
        $memberResolver = new MemberResolver($knowledge->source);
        $resolver = new SymbolResolver($parser, $knowledge->source, $memberResolver);

        $capabilities = self::createStub(SessionCapabilitiesProvider::class);
        $capabilities->method('getSessionCapabilities')
            ->willReturn(new SessionCapabilities(snippetSupport: false));

        $handler = new CompletionHandler(
            $documents,
            $resolver,
            new SymbolCandidates($knowledge->source, $resolver, $capabilities),
            new KeywordCandidates(),
            new VariableCandidates($resolver),
            new MemberCandidates($resolver, $capabilities),
            new NamedArgumentCandidates(),
            new BuiltinTypeCandidates(),
        );
        $sync = new TextDocumentSyncHandler($documents, $knowledge->sink);

        return new CompletionHarness($handler, $sync);
    }
}
