<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceCatalogFactory;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Golden parity for the namespace-enumeration surface — `NamespaceCatalog::
 * childrenOf()`, which Step 2 migrates onto `SymbolSource::childrenOf`. The
 * golden queries only namespaces with no internal (reflected) symbols, whose
 * output is therefore stable across the 8.3/8.4/8.5 CI matrix; the built-in
 * reflection source is version-fragile and is covered by a subset assertion.
 *
 * See docs/architecture/0002-execution-plan.md, Step P; RFC 1 §4.2, §5.1.
 */
#[CoversClass(NamespaceCatalogFactory::class)]
final class ChildrenOfParityTest extends TestCase
{
    use AssertsGolden;

    /**
     * A fixed set of workspace documents indexed before enumeration, so the
     * workspace source contributes deterministically.
     *
     * @var list<string>
     */
    private const array INDEXED_DOCUMENTS = [
        'src/Domain/Entity.php',
        'src/Domain/User.php',
        'src/Enum/Status.php',
        'src/Model/Env/Sub/Thing.php',
    ];

    /**
     * Namespaces to enumerate. Each is free of internal symbols, so the
     * reflection source contributes nothing and the merged output is stable.
     *
     * @var list<string>
     */
    private const array NAMESPACES = [
        'Fixtures',
        'Fixtures\Domain',
        'Fixtures\Enum',
        'Fixtures\Model',
        'Fixtures\Model\Env',
        'Fixtures\TypeInference',
        'Psr',
        'Psr\Http',
        'Psr\Http\Message',
        'Psr0',
        'Fixtures\ThisNamespaceIsEmpty',
    ];

    private string $fixturesRoot;
    private NamespaceCatalog $catalog;

    protected function setUp(): void
    {
        $this->fixturesRoot = dirname(__DIR__) . '/Fixtures';

        $index = new SymbolIndex();
        $indexer = new DocumentIndexer(new ParserService(), new SymbolExtractor(), $index);
        foreach (self::INDEXED_DOCUMENTS as $relative) {
            $path = $this->fixturesRoot . '/' . $relative;
            $content = file_get_contents($path);
            self::assertNotFalse($content, "fixture document should be readable: {$relative}");
            $indexer->index(new TextDocument('file://' . $path, 'php', 0, $content));
        }

        $this->catalog = NamespaceCatalogFactory::forProject($index, $this->fixturesRoot);
    }

    public function testChildrenOfMatchesGolden(): void
    {
        $captured = [];
        foreach (self::NAMESPACES as $namespace) {
            $captured[$namespace] = self::serialize($this->catalog->childrenOf($namespace));
        }

        $this->assertGoldenMatches('children-of', $captured);
    }

    public function testReflectionSourceEnumeratesBuiltinNamespace(): void
    {
        // Covers the reflection source's match path with a built-in namespace
        // stable since PHP 8.2. The full list is version-fragile, so only a
        // known member is asserted, not frozen into the golden.
        $contents = $this->catalog->childrenOf('Random');

        $fqns = array_map(
            static fn(CatalogSymbol $symbol): string => $symbol->fullyQualifiedName,
            $contents->symbols,
        );
        self::assertContains(
            'Random\Randomizer',
            $fqns,
            'the reflection source must enumerate built-in symbols of a namespace',
        );
    }

    /**
     * @return array{childNamespaces: list<string>, symbols: list<array{fqn: string, kind: string}>}
     */
    private static function serialize(NamespaceContents $contents): array
    {
        $childNamespaces = $contents->childNamespaces;
        sort($childNamespaces);

        $symbols = array_map(
            static fn(CatalogSymbol $symbol): array => [
                'fqn' => $symbol->fullyQualifiedName,
                'kind' => $symbol->kind->name,
            ],
            $contents->symbols,
        );
        usort($symbols, static fn(array $a, array $b): int => strcmp($a['fqn'], $b['fqn']));

        return [
            'childNamespaces' => $childNamespaces,
            'symbols' => $symbols,
        ];
    }
}
