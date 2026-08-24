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
use PHPUnit\Framework\TestCase;

/**
 * Golden parity for the prefix-search surface — `SymbolIndex::findByPrefix()`,
 * which `SymbolSource::search` serves per kind. A fixed set of
 * workspace documents is indexed, then a curated set of prefix queries (with and
 * without a kind filter, matching and not) is frozen. All inputs are in-repo, so
 * the golden is deterministic.
 *
 * See docs/architecture/0002-execution-plan.md, Step P; RFC 1 §4.2, §5.1.
 */
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
            // A lowercase prefix that matches differently-cased symbol names:
            // prefix matching is case-insensitive, so 'user' must still find
            // `User` and `UserRepository`. A case-sensitive regression would
            // return nothing here.
            'user|lowercase' => ['user', null],
            // A multi-kind filter: matches must be admitted if their kind is any
            // of the listed kinds, not merely the first. With Class_ and
            // Interface_ both requested, the result spans both kinds — a filter
            // that collapsed to a single kind would drop the interfaces.
            'all|Class+Interface' => ['', [SymbolKind::Class_, SymbolKind::Interface_]],
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

    public function testExactLookupsByFqnAndName(): void
    {
        // The index's exact-match queries back go-to-definition and reference
        // resolution; the prefix surface owns the index, so its parity covers them.
        $byFqn = $this->index->findByFqn('Fixtures\Domain\User');
        self::assertNotNull($byFqn, 'an indexed symbol must be found by its FQN');
        self::assertSame('User', $byFqn->name, 'findByFqn must return the matching symbol');
        self::assertNull(
            $this->index->findByFqn('Fixtures\Domain\Absent'),
            'an unindexed FQN must return null',
        );

        $byName = array_map(
            static fn(Symbol $symbol): string => $symbol->fullyQualifiedName,
            $this->index->findByName('User'),
        );
        self::assertSame(
            ['Fixtures\Domain\User'],
            $byName,
            'findByName must return every symbol with that short name',
        );
    }

    /**
     * The `uri` is captured (which file a match lives in), but not the line/column
     * offsets: those shift on any edit above the symbol, which is churn unrelated
     * to what the prefix-search surface returns.
     *
     * @return array{fqn: string, name: string, kind: string, containerName: ?string, uri: string}
     */
    private function serialize(Symbol $symbol): array
    {
        return [
            'fqn' => $symbol->fullyQualifiedName,
            'name' => $symbol->name,
            'kind' => $symbol->kind->name,
            'containerName' => $symbol->containerName,
            'uri' => GoldenCodec::relativizePath($symbol->location->uri, $this->projectRoot),
        ];
    }
}
