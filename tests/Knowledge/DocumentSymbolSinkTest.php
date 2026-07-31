<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Knowledge\DocumentSymbolSink;
use Firehed\PhpLsp\Knowledge\OpenDocumentBackend;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use PHPUnit\Framework\TestCase;

/**
 * The single write path (RFC 1 §4.3, §5.2): a document event must register class
 * metadata with the open-document backend for lookup and index its symbols for
 * enumeration and search — the double write, driven from one document. These prove
 * both stores move together on open, update, and close, and that a malformed
 * document contributes nothing rather than crashing (RFC 1 §9).
 */
final class DocumentSymbolSinkTest extends TestCase
{
    private SymbolIndex $index;
    private OpenDocumentBackend $backend;
    private DocumentSymbolSink $sink;

    protected function setUp(): void
    {
        $parser = new ParserService();
        $this->index = new SymbolIndex();
        $this->backend = new OpenDocumentBackend($this->index);
        $this->sink = new DocumentSymbolSink(
            $this->backend,
            new DocumentIndexer($parser, new SymbolExtractor(), $this->index),
            $this->index,
            new DefaultClassInfoFactory(),
            $parser,
        );
    }

    public function testOpenDocumentRegistersClassesAndIndexesSymbols(): void
    {
        // A non-class statement alongside the class exercises the class-like filter.
        $content = "<?php\nnamespace V;\nfunction helper(): void {}\nfinal class Widget {}\n";

        $this->sink->openDocument(new TextDocument('file:///Widget.php', 'php', 1, $content));

        self::assertNotNull(
            $this->backend->lookupClassLike(self::className('V\Widget')),
            'openDocument must register the class for lookup',
        );
        self::assertNotNull(
            $this->index->findByFqn('V\Widget'),
            'openDocument must also index the document — the second of the double write',
        );
    }

    public function testUpdateDocumentReplacesThePriorSymbolsInBothStores(): void
    {
        $uri = 'file:///Doc.php';
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nclass Alpha {}\n"));
        $this->sink->updateDocument(new TextDocument($uri, 'php', 2, "<?php\nnamespace V;\nclass Beta {}\n"));

        self::assertNull($this->index->findByFqn('V\Alpha'), 'update must clear the prior symbols from the index');
        self::assertNotNull($this->index->findByFqn('V\Beta'), 'update must index the new symbols');
        self::assertNotNull(
            $this->backend->lookupClassLike(self::className('V\Beta')),
            'update must register the new class for lookup',
        );
        self::assertNull(
            $this->backend->lookupClassLike(self::className('V\Alpha')),
            'update must drop the prior class from lookup',
        );
    }

    public function testCloseDocumentClearsBothStores(): void
    {
        $uri = 'file:///Ephemeral.php';
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nclass Ephemeral {}\n"));

        $this->sink->closeDocument($uri);

        self::assertNull($this->index->findByFqn('V\Ephemeral'), 'close must clear the indexed symbols');
        self::assertNull(
            $this->backend->lookupClassLike(self::className('V\Ephemeral')),
            'close must drop the registered class from lookup',
        );
    }

    public function testUpdatingAwayFromAllClassesClearsTheBackendNotJustTheIndex(): void
    {
        $uri = 'file:///Doc.php';
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nclass Widget {}\n"));
        self::assertNotNull(
            $this->backend->lookupClassLike(self::className('V\Widget')),
            'the class is registered while the document declares it',
        );

        // The document is edited until it declares no class at all — the same state a
        // parse failure yields (registerClasses falls back to an empty statement list).
        // Both stores must drop the class in lockstep: the backend cannot keep a stale
        // registration while the index clears (RFC 1 §4.3, the double write moving
        // together). Skipping the write when nothing is found leaves the backend stale.
        $this->sink->updateDocument(new TextDocument($uri, 'php', 2, "<?php\nnamespace V;\n"));

        self::assertNull(
            $this->backend->lookupClassLike(self::className('V\Widget')),
            'a document that no longer declares the class must drop its registration',
        );
        self::assertSame(
            [],
            $this->indexedFqnsFor($uri),
            'the index must clear too — both stores move together (RFC 1 §4.3)',
        );
    }

    public function testEveryRegisteredClassLikeIsAlsoIndexed(): void
    {
        // The lookup store and the symbol index are separate structures fed from one
        // parse; the write path keeps them consistent (RFC 1 §4.3). Across every
        // class-like kind, a name registered for lookup must also be indexed, so it is
        // never resolvable through one surface yet invisible to the other.
        $content = "<?php\nnamespace V;\n"
            . "class TheClass {}\n"
            . "interface TheInterface {}\n"
            . "trait TheTrait {}\n"
            . "enum TheEnum {}\n";

        $uri = 'file:///AllKinds.php';
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, $content));

        foreach (['V\TheClass', 'V\TheInterface', 'V\TheTrait', 'V\TheEnum'] as $fqn) {
            self::assertNotNull(
                $this->backend->lookupClassLike(self::className($fqn)),
                "{$fqn} must be registered for lookup",
            );
            self::assertNotNull(
                $this->index->findByFqn($fqn),
                "{$fqn} is registered for lookup so it must also be indexed (RFC 1 §4.3)",
            );
        }
    }

    public function testWriteSurvivesAMalformedDocument(): void
    {
        $uri = 'file:///Broken.php';
        $broken = file_get_contents(dirname(__DIR__) . '/Fixtures/src/IncompleteCode/VeryBroken.php');
        self::assertNotFalse($broken, 'the broken fixture should be readable');

        $this->sink->openDocument(new TextDocument($uri, 'php', 1, $broken));

        self::assertSame(
            [],
            $this->indexedFqnsFor($uri),
            'a malformed document must contribute no indexed symbols and must not crash',
        );
    }

    /**
     * @return list<string>
     */
    private function indexedFqnsFor(string $uri): array
    {
        $fqns = [];
        foreach ($this->index->findByPrefix('') as $symbol) {
            if ($symbol->location->uri === $uri) {
                $fqns[] = $symbol->fullyQualifiedName;
            }
        }
        sort($fqns);

        return $fqns;
    }

    private static function className(string $fqn): ClassName
    {
        /** @phpstan-ignore argument.type (virtual names are not analyzed) */
        return new ClassName($fqn);
    }
}
