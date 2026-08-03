<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Tests\WritesTemporaryFilesTrait;
use PHPUnit\Framework\TestCase;

/**
 * The knowledge tier's composition root: these prove the assembled stack resolves a
 * workspace class through the backends, that a document opened through the sink is
 * visible to the read source (they share one open-document backend), and that a
 * pre-populated index is honored.
 */
final class KnowledgeStackTest extends TestCase
{
    use WritesTemporaryFilesTrait;

    private string $fixturesRoot;
    private ParserService $parser;

    protected function setUp(): void
    {
        $this->fixturesRoot = dirname(__DIR__, 2) . '/tests/Fixtures';
        $this->parser = new ParserService();
    }

    public function testSourceResolvesAWorkspaceClassThroughTheBackends(): void
    {
        $stack = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($this->fixturesRoot),
            $this->fixturesRoot . '/vendor',
            $this->parser,
        );

        self::assertNotNull(
            $stack->source->lookupClassLike(self::className('Fixtures\Domain\User')),
            'a fixture class must resolve through the assembled filesystem backend',
        );
    }

    public function testADocumentOpenedThroughTheSinkIsVisibleToTheSource(): void
    {
        $stack = KnowledgeStack::forProject(
            new ComposerAutoloadMap(),
            $this->fixturesRoot . '/vendor',
            $this->parser,
        );

        $stack->sink->openDocument(new TextDocument(
            'file:///virtual/Widget.php',
            'php',
            1,
            "<?php\nnamespace V;\nclass Widget {}\n",
        ));

        self::assertNotNull(
            $stack->source->lookupClassLike(self::className('V\Widget')),
            'the read source and the write sink share one open-document backend',
        );
    }

    public function testAPrePopulatedIndexIsHonored(): void
    {
        $index = new SymbolIndex();
        $index->add(new Symbol('Seeded', 'Seed\Seeded', SymbolKind::Class_, new Location('file:///s.php', 0, 0, 0, 0)));

        $stack = KnowledgeStack::forProject(
            new ComposerAutoloadMap(),
            $this->fixturesRoot . '/vendor',
            $this->parser,
            $index,
        );

        $symbolFqns = array_map(
            static fn($symbol): string => $symbol->fullyQualifiedName,
            $stack->source->childrenOf(new NamespaceName('Seed'))->symbols,
        );
        self::assertContains(
            'Seed\Seeded',
            $symbolFqns,
            'a supplied index pre-populates the open-document backend the source reads',
        );
    }

    public function testAWorkspaceClassOverridesAVendoredOneOfTheSameName(): void
    {
        // Each half gets its own locator, and nothing else distinguishes them: a
        // name only one half declares resolves either way, because the composite
        // falls through. A name *both* halves declare is what pins the fixed
        // precedence (RFC 1 §5.3) — and, with it, that each backend was paired
        // with the locator built from its own half of the autoload map.
        $workspace = $this->createTemporaryDirectory('php-lsp-precedence-');

        try {
            $this->writePhpFile(
                $workspace . '/src/Widget.php',
                "namespace Dup;\nclass Widget { public function fromWorkspace(): void {} }",
            );
            $this->writePhpFile(
                $workspace . '/vendor/pkg/Widget.php',
                "namespace Dup;\nclass Widget { public function fromVendor(): void {} }",
            );

            $stack = KnowledgeStack::forProject(
                new ComposerAutoloadMap(psr4: ['Dup\\' => [$workspace . '/src', $workspace . '/vendor/pkg']]),
                $workspace . '/vendor',
                $this->parser,
            );

            $widget = $stack->source->lookupClassLike(self::className('Dup\Widget'));

            self::assertNotNull($widget, 'the duplicated name must resolve through one of the halves');
            self::assertArrayHasKey(
                'fromWorkspace',
                $widget->methods,
                'the project\'s own copy must win over the vendored one (RFC 1 §5.3)',
            );
        } finally {
            $this->removeTemporaryDirectory($workspace);
        }
    }

    public function testWarmingDerivesTheLocatorsAutoloadFilesIndex(): void
    {
        $stack = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($this->fixturesRoot),
            $this->fixturesRoot . '/vendor',
            $this->parser,
        );
        $before = $this->parser->getMetrics()->getParseCount();

        $stack->warm();

        self::assertGreaterThan(
            $before,
            $this->parser->getMetrics()->getParseCount(),
            'warming must reach the assembled locators and parse the autoload.files set',
        );
    }

    public function testWarmingReachesTheVendorHalfLocatorToo(): void
    {
        // The fixture project's autoload.files all sit outside vendor/, so the test
        // above is satisfied by the workspace locator alone and cannot see whether
        // the vendor one was assembled or warmed. Partitioning at AutoloadFiles/
        // puts the only entry in the vendor half, so any parse observed here must
        // have come from the vendor locator.
        $map = new ComposerAutoloadMap(files: [$this->fixturesRoot . '/AutoloadFiles/globals.php']);
        $stack = KnowledgeStack::forProject($map, $this->fixturesRoot . '/AutoloadFiles', $this->parser);
        $before = $this->parser->getMetrics()->getParseCount();

        $stack->warm();

        self::assertGreaterThan(
            $before,
            $this->parser->getMetrics()->getParseCount(),
            'warming must reach every assembled locator, not only the workspace half',
        );
    }

    public function testWarmingIsNotRequiredForTheSourceToAnswer(): void
    {
        // Warming is latency, not correctness: an unwarmed stack derives what it
        // needs on demand (Plan 0002 §1, lazy-first).
        $stack = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($this->fixturesRoot),
            $this->fixturesRoot . '/vendor',
            $this->parser,
        );

        self::assertNotNull(
            $stack->source->lookupClassLike(self::className('Fixtures\Domain\User')),
            'a stack that was never warmed must still resolve',
        );
    }

    private static function className(string $fqn): ClassName
    {
        /** @phpstan-ignore argument.type (fixture and virtual names are not analyzed) */
        return new ClassName($fqn);
    }
}
