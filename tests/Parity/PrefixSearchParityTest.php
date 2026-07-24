<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Parser\ParserService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Golden parity for the prefix-search surface — `SymbolIndex::findByPrefix()`,
 * which Step 2 migrates onto `SymbolSource::searchClassLikes`. A fixed set of
 * workspace documents is indexed, then a curated set of prefix queries (with and
 * without a kind filter, matching and not) is frozen. All inputs are in-repo, so
 * the golden is deterministic.
 *
 * See docs/architecture/0002-execution-plan.md, Step P; RFC 1 §4.2, §5.1.
 */
#[CoversClass(SymbolIndex::class)]
final class PrefixSearchParityTest extends TestCase
{
    use AssertsGolden;

    /**
     * The documents whose symbols make up the searchable index. The set spans
     * every extracted kind: classes, an interface, a trait, an enum, functions,
     * and the methods those declare.
     *
     * @var list<string>
     */
    private const array INDEXED_DOCUMENTS = [
        'src/Catalog/functions.php',
        'src/Domain/Entity.php',
        'src/Domain/User.php',
        'src/Enum/Status.php',
        'src/Repository/UserRepository.php',
        'src/Traits/HasTimestamps.php',
    ];

    private string $projectRoot;
    private SymbolIndex $index;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->index = new SymbolIndex();
        $indexer = new DocumentIndexer(new ParserService(), new SymbolExtractor(), $this->index);

        foreach (self::INDEXED_DOCUMENTS as $relative) {
            $path = $this->projectRoot . '/tests/Fixtures/' . $relative;
            $content = file_get_contents($path);
            self::assertNotFalse($content, "fixture document should be readable: {$relative}");
            $indexer->index(new TextDocument('file://' . $path, 'php', 0, $content));
        }
    }

    public function testPrefixSearchMatchesGolden(): void
    {
        $queries = [
            'User' => ['User', null],
            'get' => ['get', null],
            'Status' => ['Status', null],
            'noop' => ['noop', null],
            'User|Class' => ['User', [SymbolKind::Class_]],
            'get|Function' => ['get', [SymbolKind::Function_]],
            'Zzz|none' => ['Zzz', null],
        ];

        $captured = [];
        foreach ($queries as $label => [$prefix, $kinds]) {
            $results = $this->index->findByPrefix($prefix, $kinds);
            $captured[$label] = array_map($this->serialize(...), $results);
            usort(
                $captured[$label],
                static fn(array $a, array $b): int => strcmp($a['fqn'], $b['fqn']),
            );
        }

        $this->assertGoldenMatches('prefix-search', $captured);
    }

    /**
     * @return array{
     *   fqn: string,
     *   name: string,
     *   kind: string,
     *   containerName: ?string,
     *   location: array{uri: string, startLine: int, startCharacter: int, endLine: int, endCharacter: int},
     * }
     */
    private function serialize(Symbol $symbol): array
    {
        return [
            'fqn' => $symbol->fullyQualifiedName,
            'name' => $symbol->name,
            'kind' => $symbol->kind->name,
            'containerName' => $symbol->containerName,
            'location' => [
                'uri' => GoldenCodec::relativizePath($symbol->location->uri, $this->projectRoot),
                'startLine' => $symbol->location->startLine,
                'startCharacter' => $symbol->location->startCharacter,
                'endLine' => $symbol->location->endLine,
                'endCharacter' => $symbol->location->endCharacter,
            ],
        ];
    }
}
