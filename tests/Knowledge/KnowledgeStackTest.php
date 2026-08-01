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
use PHPUnit\Framework\TestCase;

/**
 * The knowledge tier's composition root: these prove the assembled stack resolves a
 * workspace class through the backends, that a document opened through the sink is
 * visible to the read source (they share one open-document backend), and that a
 * pre-populated index is honored.
 */
final class KnowledgeStackTest extends TestCase
{
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
