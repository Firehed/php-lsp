<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Cache\InvalidatableInterface;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\DocumentSymbolSink;
use Firehed\PhpLsp\Knowledge\OpenDocumentBackend;
use Firehed\PhpLsp\Parser\ParseMetrics;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The single write path (RFC 1 §4.3, §5.2): a document event registers the
 * document's declared symbols with the one open-document store (build-manifest
 * step-46). These prove the store moves with the document on open, update, and
 * close, and that a malformed document contributes nothing rather than crashing
 * (RFC 1 §9).
 */
final class DocumentSymbolSinkTest extends TestCase
{
    use LoadsFixturesTrait;
    use LooksUpBackendSymbolsTrait;

    private OpenDocumentBackend $backend;
    private DocumentSymbolSink $sink;

    protected function setUp(): void
    {
        $parser = ProductionSyntaxSource::create()->source;
        $this->backend = new OpenDocumentBackend();
        $this->sink = new DocumentSymbolSink(
            $this->backend,
            new DeclarationSymbolInfoFactory(),
            $parser,
            new DeclarationScanner(),
        );
    }

    public function testOpenDocumentRegistersDocumentSymbols(): void
    {
        // A non-class statement alongside the class exercises the class-like filter.
        $content = "<?php\nnamespace V;\nfunction helper(): void {}\nfinal class Widget {}\n";

        $this->sink->openDocument(new TextDocument('file:///Widget.php', 'php', 1, $content));

        self::assertNotNull(
            self::classLikeIn($this->backend, 'V\Widget'),
            'openDocument must register the class for lookup',
        );
        self::assertNotNull(
            self::functionIn($this->backend, 'V\helper'),
            'openDocument must register the function too',
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

    public function testUpdatingAwayFromAFunctionReplacesItWithTheNewDeclarations(): void
    {
        $uri = 'file:///helpers.php';
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, "<?php\nfunction helper(): void {}\n"));

        // A version that names any declaration replaces the previous set wholesale.
        $this->sink->updateDocument(new TextDocument($uri, 'php', 2, "<?php\nfunction other(): void {}\n"));

        self::assertNull(
            self::functionIn($this->backend, 'helper'),
            'a version that names other declarations must drop the previous ones',
        );
        self::assertNotNull(
            self::functionIn($this->backend, 'other'),
            'the new declaration must be registered in the previous one\'s place',
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

    public function testUpdateDocumentReplacesThePriorSymbols(): void
    {
        $uri = 'file:///Doc.php';
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nclass Alpha {}\n"));
        $this->sink->updateDocument(new TextDocument($uri, 'php', 2, "<?php\nnamespace V;\nclass Beta {}\n"));

        self::assertNotNull(
            self::classLikeIn($this->backend, 'V\Beta'),
            'update must register the new class for lookup',
        );
        self::assertNull(
            self::classLikeIn($this->backend, 'V\Alpha'),
            'update must drop the prior class from lookup',
        );
    }

    public function testCloseDocumentClearsTheStore(): void
    {
        $uri = 'file:///Ephemeral.php';
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nclass Ephemeral {}\n"));

        $this->sink->closeDocument($uri);

        self::assertNull(
            self::classLikeIn($this->backend, 'V\Ephemeral'),
            'close must drop the registered class from lookup',
        );
    }

    public function testInvalidateFansOutToTheOnDiskBackends(): void
    {
        $uri = 'file:///workspace/src/Changed.php';
        $onDisk = $this->createMock(InvalidatableInterface::class);
        $onDisk->expects($this->once())
            ->method('invalidate')
            ->with($uri);

        $this->sinkWithOnDiskBackends($onDisk)->invalidate($uri);
    }

    public function testCloseDocumentInvalidatesTheOnDiskBackendsSoTheyReReadFromDisk(): void
    {
        $uri = 'file:///workspace/src/Widget.php';
        $onDisk = $this->createMock(InvalidatableInterface::class);
        // Closing a file that was edited in the editor must drop the on-disk cache
        // so the next query reflects disk rather than the pre-edit value (RFC 1 §5.3).
        $onDisk->expects($this->once())
            ->method('invalidate')
            ->with($uri);

        $this->sinkWithOnDiskBackends($onDisk)->closeDocument($uri);
    }

    public function testOpeningABrokenFileRegistersTheShapeTheSkeletonRecovers(): void
    {
        // The skeleton source in the composite (step-37) recovers a mid-edit
        // document's structural shape, so the write path feeds `DeclarationScanner`
        // a tree even when php-parser alone would produce none (RFC 1 §5.3).
        // Completion of `$this->` on a class the user is still typing must still
        // find its members.
        $uri = 'file:///Broken.php';
        $content = $this->loadFixture('src/IncompleteCode/VeryBroken.php');
        $document = new TextDocument($uri, 'php', 1, $content);

        // The precondition that separates the composite's two arms: php-parser
        // alone yields nothing on this fixture, so a class registered after the
        // write can only have come from the skeleton — the same precondition
        // `CompletionHandlerTest::testCompletionThisInVeryBrokenFile` pins.
        $phpParserOnly = new PhpParserSyntaxSource(new TreeAnnotator(), new ParseMetrics());
        self::assertSame(
            [],
            $phpParserOnly->parse($document),
            'php-parser alone must yield nothing on this fixture, or the test proves nothing about the skeleton',
        );

        $this->sink->openDocument($document);

        $classInfo = self::classLikeIn($this->backend, 'Fixtures\\IncompleteCode\\VeryBroken');
        self::assertNotNull(
            $classInfo,
            'the skeleton must recover the class so member lookup still answers',
        );
        self::assertArrayHasKey(
            'getName',
            $classInfo->methods,
            'the skeleton must reach the class body for member lookup',
        );
    }

    #[DataProvider('classLikeFixtures')]
    public function testEveryClassLikeKindIsRegistered(string $fixture, string $fqn): void
    {
        // The one store answers for every class-like kind; a name registered for
        // lookup must be reachable for every consumer.
        $uri = 'file:///' . $fixture;
        $this->sink->openDocument(new TextDocument($uri, 'php', 1, $this->loadFixture($fixture)));

        self::assertNotNull(
            self::classLikeIn($this->backend, $fqn),
            "{$fqn} must be registered for lookup",
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

    private function sinkWithOnDiskBackends(InvalidatableInterface ...$onDiskBackends): DocumentSymbolSink
    {
        $parser = ProductionSyntaxSource::create()->source;

        return new DocumentSymbolSink(
            $this->backend,
            new DeclarationSymbolInfoFactory(),
            $parser,
            new DeclarationScanner(),
            array_values($onDiskBackends),
        );
    }
}
