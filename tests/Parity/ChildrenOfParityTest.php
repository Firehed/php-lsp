<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Parser\ParserService;
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
final class ChildrenOfParityTest extends TestCase
{
    use AssertsGolden;

    /**
     * A fixed set of workspace documents indexed before enumeration, so the
     * workspace source contributes deterministically. The `Fixtures\Model`
     * subtree is a small, dedicated namespacing example, chosen so unrelated
     * fixtures added elsewhere cannot churn this golden.
     *
     * @var list<string>
     */
    private const array INDEXED_DOCUMENTS = [
        'src/Catalog/functions.php',
        'src/Model/Env.php',
        'src/Model/Env/Handler.php',
        'src/Model/Env/Sub/Thing.php',
    ];

    /**
     * Namespaces to enumerate. Each is deliberately *stable*: the locked
     * `psr/http-message` dependency, the single PSR-0 and classmap fixtures, and
     * the `Fixtures\Model` subtree — none grows when features add fixtures
     * elsewhere. Each is also free of internal symbols, so the reflection source
     * contributes nothing and the merged output stays deterministic across the
     * PHP matrix. Between them they exercise every composer branch: a PSR-4 leaf
     * with symbols, a namespace above a PSR-4 prefix, PSR-0, and the classmap.
     *
     * `Fixtures\Helpers` is the `autoload.files` route, which no autoload map
     * addresses by name and no directory listing reaches: it exists only in the
     * index derived by parsing that set. Its entry is a dedicated fixture file, so
     * it is as stable as the rest.
     *
     * @var list<string>
     */
    private const array NAMESPACES = [
        'Fixtures\Catalog',
        'Fixtures\Helpers',
        'Fixtures\Model',
        'Fixtures\Model\Env',
        'Fixtures\Model\OpenOnly',
        'Psr',
        'Psr\Http',
        'Psr\Http\Message',
        'Psr0',
        'Firehed\PhpLsp\Tests\Fixtures\Autoload',
        'Fixtures\ThisNamespaceIsEmpty',
    ];

    private string $fixturesRoot;
    private SymbolSource $source;

    protected function setUp(): void
    {
        $this->fixturesRoot = dirname(__DIR__) . '/Fixtures';

        $parser = new ParserService();
        $index = new SymbolIndex();
        $indexer = new DocumentIndexer($parser, new SymbolExtractor(), $index);
        foreach (self::INDEXED_DOCUMENTS as $relative) {
            $path = $this->fixturesRoot . '/' . $relative;
            $content = file_get_contents($path);
            self::assertNotFalse($content, "fixture document should be readable: {$relative}");
            $indexer->index(new TextDocument('file://' . $path, 'php', 0, $content));
        }

        // A class that lives only in an open, unsaved document: its namespace has no
        // on-disk PSR-4 backing, so ComposerNamespaceSource cannot see it and the
        // workspace source is its sole provider. Every *other* workspace symbol and
        // derived child namespace in this corpus is also produced by the composer
        // source (the indexed files exist on disk under a PSR-4 prefix), so without
        // this the workspace enumeration path is fully shadowed and a regression in
        // it would leave the golden green. This pins both the workspace source's
        // symbol enumeration (`Unsaved` under `Fixtures\Model\OpenOnly`) and its
        // child-namespace derivation (`Fixtures\Model\OpenOnly` under
        // `Fixtures\Model`). RFC 1 §4.2 (enumeration is served by the workspace
        // backend too), §5.2 (open-document state must surface).
        $indexer->index(new TextDocument(
            'file:///virtual/OpenOnly/Unsaved.php',
            'php',
            1,
            "<?php\nnamespace Fixtures\\Model\\OpenOnly;\nclass Unsaved {}\n",
        ));

        $this->source = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($this->fixturesRoot),
            $this->fixturesRoot . '/vendor',
            $parser,
            $index,
        )->source;
    }

    public function testChildrenOfMatchesGolden(): void
    {
        $captured = [];
        foreach (self::NAMESPACES as $namespace) {
            $captured[$namespace] = self::serialize($this->source->childrenOf(new NamespaceName($namespace)));
        }

        $this->assertGoldenMatches('children-of', $captured);
    }

    public function testReflectionSourceEnumeratesBuiltinNamespace(): void
    {
        // Covers the reflection source's match path with a built-in namespace
        // stable since PHP 8.2. The full list is version-fragile, so it is not
        // frozen; instead several symbols filed under the namespace and a child
        // namespace derived from a nested built-in are asserted, so a regression
        // in per-namespace filing or child-namespace derivation goes red rather
        // than surviving behind a single canary symbol.
        $contents = $this->source->childrenOf(new NamespaceName('Random'));

        $fqns = array_map(
            static fn(CatalogSymbol $symbol): string => $symbol->fullyQualifiedName,
            $contents->symbols,
        );
        self::assertContains(
            'Random\Randomizer',
            $fqns,
            'the reflection source must enumerate built-in symbols of a namespace',
        );
        self::assertContains(
            'Random\CryptoSafeEngine',
            $fqns,
            'the reflection source must file every built-in of a namespace, not just one',
        );
        self::assertContains(
            'Random\Engine',
            $contents->childNamespaces,
            'the reflection source must derive a child namespace from a nested built-in',
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
