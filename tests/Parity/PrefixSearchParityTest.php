<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\DocumentSymbolSink;
use Firehed\PhpLsp\Knowledge\OpenDocumentBackend;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\TestCase;

/**
 * Golden parity for the prefix-search surface — {@see OpenDocumentBackend::search()},
 * per kind. A fixed set of workspace documents is registered, then a curated set
 * of per-kind prefix queries is frozen. All inputs are in-repo, so the golden is
 * deterministic.
 *
 * See RFC 1 §4.2, §5.1.
 */
final class PrefixSearchParityTest extends TestCase
{
    use AssertsGolden;

    /**
     * The documents whose symbols make up the searchable index. The set spans
     * every extracted kind: classes, an interface, a trait, an enum, and functions.
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
    private OpenDocumentBackend $backend;
    private DocumentSymbolSink $sink;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $parser = ProductionSyntaxSource::create()->source;
        $this->backend = new OpenDocumentBackend();
        $this->sink = new DocumentSymbolSink(
            $this->backend,
            new DeclarationSymbolInfoFactory(new DefaultClassInfoFactory()),
            $parser,
            new DeclarationScanner(),
        );

        foreach (self::INDEXED_DOCUMENTS as $relative) {
            $path = $this->projectRoot . '/tests/Fixtures/' . $relative;
            $content = file_get_contents($path);
            self::assertNotFalse($content, "fixture document should be readable: {$relative}");
            $this->sink->openDocument(new TextDocument('file://' . $path, 'php', 0, $content));
        }
    }

    public function testPrefixSearchMatchesGolden(): void
    {
        $queries = [
            'User|ClassLike' => ['User', NameKind::ClassLike],
            'get|Function' => ['get', NameKind::Function_],
            'Status|ClassLike' => ['Status', NameKind::ClassLike],
            'noop|Function' => ['noop', NameKind::Function_],
            // A prefix nothing matches.
            'Zzz|none' => ['Zzz', NameKind::ClassLike],
            // A lowercase prefix that matches differently-cased symbol names:
            // prefix matching is case-insensitive, so 'user' must still find
            // `User` and `UserRepository`. A case-sensitive regression would
            // return nothing here.
            'user|lowercase' => ['user', NameKind::ClassLike],
            // An empty prefix pulls every class-like: classes, interfaces,
            // traits, and enums. A regression that split those apart would drop
            // interfaces (and everything but classes) here.
            'all|ClassLike' => ['', NameKind::ClassLike],
        ];

        $captured = [];
        foreach ($queries as $label => [$prefix, $kind]) {
            $results = $this->backend->search($prefix, $kind);
            $captured[$label] = array_map($this->serialize(...), $results);
            usort(
                $captured[$label],
                static fn(array $a, array $b): int => strcmp($a['fqn'], $b['fqn']),
            );
        }

        $this->assertGoldenMatches('prefix-search', $captured);
    }

    /**
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
