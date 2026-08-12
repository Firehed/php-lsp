<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\DeclarationScanner;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Cache\Invalidatable;
use Firehed\PhpLsp\Knowledge\DocumentSymbolSink;
use Firehed\PhpLsp\Knowledge\OpenDocumentBackend;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
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
    use LoadsFixturesTrait;
    use LooksUpBackendSymbolsTrait;

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
            new DeclarationScanner(),
        );
    }

    public function testOpenDocumentRegistersClassesAndIndexesSymbols(): void
    {
        // A non-class statement alongside the class exercises the class-like filter.
        $content = "<?php\nnamespace V;\nfunction helper(): void {}\nfinal class Widget {}\n";

        $this->sink->openDocument(new TextDocument('file:///Widget.php', 'php', 1, $content));

        self::assertNotNull(
            self::classLikeIn($this->backend, 'V\Widget'),
            'openDocument must register the class for lookup',
        );
        self::assertNotNull(
            $this->index->findByFqn('V\Widget'),
            'openDocument must also index the document — the second of the double write',
        );
    }

    public function testOpenDocumentRegistersFunctionsUnderTheirQualifiedNames(): void
    {
        $content = "<?php\nnamespace V;\nfunction helper(): void {}\n";

        $this->sink->openDocument(new TextDocument('file:///helpers.php', 'php', 1, $content));

        self::assertNotNull(
            self::functionIn($this->backend, 'V\helper'),
            'openDocument must register the document\'s functions for lookup',
        );
        self::assertNull(
            self::functionIn($this->backend, 'helper'),
            'a namespaced function must not be registered under its short name',
        );
    }

    public function testOpenDocumentRegistersADeclarationBelowTheTopLevel(): void
    {
        // A conditionally declared polyfill is a name the file validly declares, and
        // the on-disk backends resolve one. An open document must agree, or opening
        // a file would make a name that already resolved disappear (RFC 1 §4.2).
        $content = "<?php\nif (!function_exists('polyfill')) {\n    function polyfill(): void {}\n}\n";

        $this->sink->openDocument(new TextDocument('file:///polyfill.php', 'php', 1, $content));

        self::assertNotNull(
            self::functionIn($this->backend, 'polyfill'),
            'a conditionally declared function must be registered like any other declaration',
        );
    }

    public function testOpenDocumentRegistersAClassLikeBelowTheTopLevel(): void
    {
        // The class-like half of the same rule: the on-disk backends resolve a
        // `class_exists`-guarded declaration, so an open document must too.
        $uri = 'file:///MultiClass.php';
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, $this->loadFixture('MultiClass/MultiClass.php')));

        self::assertNotNull(
            self::classLikeIn($this->backend, 'Fixtures\Completion\ConditionalInMultiFile'),
            'a conditionally declared class must be registered like any other declaration',
        );
    }

    public function testTheFirstOfDuplicateClassLikeDeclarationsWins(): void
    {
        // A file may declare one name twice (an unguarded declaration plus a guarded
        // twin). PHP defines the first one executed, and the on-disk backends return
        // the first declaration found — the open document must agree (RFC 1 §4.2).
        $uri = 'file:///DuplicateDeclarations.php';
        $content = $this->loadFixture('MultiClass/DuplicateDeclarations.php');
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, $content));

        $classInfo = self::classLikeIn($this->backend, 'Fixtures\MultiClass\Duplicated');
        self::assertNotNull($classInfo, 'the duplicated class must still resolve');
        self::assertTrue(
            $classInfo->isFinal,
            'the first declaration (final) must win, matching runtime and the on-disk backends',
        );
    }

    public function testTheFirstOfDuplicateFunctionDeclarationsWins(): void
    {
        // The function half of the same rule.
        $uri = 'file:///DuplicateDeclarations.php';
        $content = $this->loadFixture('MultiClass/DuplicateDeclarations.php');
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, $content));

        $functionInfo = self::functionIn($this->backend, 'Fixtures\MultiClass\duplicated');
        self::assertNotNull($functionInfo, 'the duplicated function must still resolve');
        self::assertSame(
            'string',
            $functionInfo->returnType?->format(),
            'the first declaration (returning string) must win, matching runtime and the on-disk backends',
        );
    }

    public function testUpdatingAwayFromAFunctionDropsItsRegistration(): void
    {
        $uri = 'file:///helpers.php';
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, "<?php\nfunction helper(): void {}\n"));

        $this->sink->updateDocument(new TextDocument($uri, 'php', 2, "<?php\n"));

        self::assertNull(
            self::functionIn($this->backend, 'helper'),
            'a document that no longer declares the function must drop its registration',
        );
    }

    public function testCloseDocumentDropsItsFunctions(): void
    {
        $uri = 'file:///helpers.php';
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, "<?php\nfunction helper(): void {}\n"));

        $this->sink->closeDocument($uri);

        self::assertNull(
            self::functionIn($this->backend, 'helper'),
            'close must drop the registered functions from lookup',
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
            self::classLikeIn($this->backend, 'V\Beta'),
            'update must register the new class for lookup',
        );
        self::assertNull(
            self::classLikeIn($this->backend, 'V\Alpha'),
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
            self::classLikeIn($this->backend, 'V\Ephemeral'),
            'close must drop the registered class from lookup',
        );
    }

    public function testInvalidateFansOutToTheOnDiskBackends(): void
    {
        $uri = 'file:///workspace/src/Changed.php';
        $onDisk = $this->createMock(Invalidatable::class);
        $onDisk->expects($this->once())
            ->method('invalidate')
            ->with($uri);

        $this->sinkWithOnDiskBackends($onDisk)->invalidate($uri);
    }

    public function testCloseDocumentInvalidatesTheOnDiskBackendsSoTheyReReadFromDisk(): void
    {
        $uri = 'file:///workspace/src/Widget.php';
        $onDisk = $this->createMock(Invalidatable::class);
        // Closing a file that was edited in the editor must drop the on-disk cache
        // so the next query reflects disk rather than the pre-edit value (RFC 1 §5.3).
        $onDisk->expects($this->once())
            ->method('invalidate')
            ->with($uri);

        $this->sinkWithOnDiskBackends($onDisk)->closeDocument($uri);
    }

    public function testUpdatingAwayFromAllClassesClearsTheBackendNotJustTheIndex(): void
    {
        $uri = 'file:///Doc.php';
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nclass Widget {}\n"));
        self::assertNotNull(
            self::classLikeIn($this->backend, 'V\Widget'),
            'the class is registered while the document declares it',
        );

        // The document is edited until it declares no class at all — the same state a
        // parse failure yields (registerClasses falls back to an empty statement list).
        // Both stores must drop the class in lockstep: the backend cannot keep a stale
        // registration while the index clears (RFC 1 §4.3, the double write moving
        // together). Skipping the write when nothing is found leaves the backend stale.
        $this->sink->updateDocument(new TextDocument($uri, 'php', 2, "<?php\nnamespace V;\n"));

        self::assertNull(
            self::classLikeIn($this->backend, 'V\Widget'),
            'a document that no longer declares the class must drop its registration',
        );
        self::assertSame(
            [],
            $this->indexedFqnsFor($uri),
            'the index must clear too — both stores move together (RFC 1 §4.3)',
        );
    }

    #[DataProvider('classLikeFixtures')]
    public function testEveryRegisteredClassLikeIsAlsoIndexed(string $fixture, string $fqn): void
    {
        // The lookup store and the symbol index are separate structures fed from one
        // parse; the write path keeps them consistent (RFC 1 §4.3). A name registered
        // for lookup must also be indexed — never resolvable through one surface yet
        // invisible to the other — across every class-like kind.
        $uri = 'file:///' . $fixture;
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, $this->loadFixture($fixture)));

        self::assertNotNull(
            self::classLikeIn($this->backend, $fqn),
            "{$fqn} must be registered for lookup",
        );
        self::assertNotNull(
            $this->index->findByFqn($fqn),
            "{$fqn} is registered for lookup so it must also be indexed (RFC 1 §4.3)",
        );
    }

    /**
     * A fixture per class-like kind, each with the FQN it declares.
     *
     * @codeCoverageIgnore
     * @return array<string, array{string, string}>
     */
    public static function classLikeFixtures(): array
    {
        return [
            'class' => ['src/Domain/User.php', 'Fixtures\Domain\User'],
            'interface' => ['src/Domain/Entity.php', 'Fixtures\Domain\Entity'],
            'trait' => ['src/Traits/HasTimestamps.php', 'Fixtures\Traits\HasTimestamps'],
            'enum' => ['src/Enum/Status.php', 'Fixtures\Enum\Status'],
        ];
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

    private function sinkWithOnDiskBackends(Invalidatable ...$onDiskBackends): DocumentSymbolSink
    {
        $parser = new ParserService();

        return new DocumentSymbolSink(
            $this->backend,
            new DocumentIndexer($parser, new SymbolExtractor(), $this->index),
            $this->index,
            new DefaultClassInfoFactory(),
            $parser,
            new DeclarationScanner(),
            array_values($onDiskBackends),
        );
    }
}
