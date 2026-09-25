<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\Symbol;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\OpenDocumentBackend;
use Firehed\PhpLsp\Parser\ParseMetrics;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The open-document backend is the highest-precedence source (RFC 1 §5.3): a
 * document event parses the buffer and registers its declarations, and the lookup
 * store, namespace enumeration and prefix search all answer from one map of
 * {@see \Firehed\PhpLsp\Domain\DeclaredSymbol}s per document. These prove each
 * query, that a document's registration is replaced on update and dropped on
 * close, and that a malformed document contributes nothing rather than crashing
 * (RFC 1 §9).
 */
final class OpenDocumentBackendTest extends TestCase
{
    use LoadsFixturesTrait;
    use LooksUpBackendSymbolsTrait;

    private OpenDocumentBackend $backend;

    protected function setUp(): void
    {
        $this->backend = self::buildBackend();
    }

    public function testLookupClassLikeReturnsARegisteredClass(): void
    {
        $this->backend->openDocument($this->widgetDocument('file:///Widget.php'));

        $info = self::classLikeIn($this->backend, 'V\Widget');

        self::assertNotNull($info, 'a registered class must resolve');
        self::assertSame(
            'V\Widget',
            $info->name->qualifiedName->fullyQualifiedName(),
            'the registered class must be returned unchanged',
        );
    }

    public function testLookupClassLikeIsCaseInsensitive(): void
    {
        $this->backend->openDocument($this->widgetDocument('file:///Widget.php'));

        self::assertNotNull(
            self::classLikeIn($this->backend, 'v\WIDGET'),
            'PHP matches class-like names case-insensitively',
        );
    }

    public function testLookupClassLikeReturnsNullForAnUnregisteredClass(): void
    {
        self::assertNull(
            self::classLikeIn($this->backend, 'V\Absent'),
            'a name no open document declares is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testUpdateDocumentReplacesThePriorClassesForThatUri(): void
    {
        $uri = 'file:///Doc.php';
        $this->backend->openDocument(
            new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nclass Alpha {}\n"),
        );
        $this->backend->updateDocument(
            new TextDocument($uri, 'php', 2, "<?php\nnamespace V;\nclass Beta {}\n"),
        );

        self::assertNull(
            self::classLikeIn($this->backend, 'V\Alpha'),
            'the prior class must be dropped when the document is re-registered',
        );
        self::assertNotNull(
            self::classLikeIn($this->backend, 'V\Beta'),
            'the new class must be registered',
        );
    }

    public function testCloseDocumentDropsItsClasses(): void
    {
        $uri = 'file:///Ephemeral.php';
        $this->backend->openDocument(
            new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nclass Ephemeral {}\n"),
        );

        $this->backend->closeDocument($uri);

        self::assertNull(
            self::classLikeIn($this->backend, 'V\Ephemeral'),
            'closing a document must drop the classes it registered',
        );
    }

    public function testCloseDocumentIsANoOpForAnUnknownUri(): void
    {
        $this->backend->closeDocument('file:///never-opened.php');

        self::assertNull(
            self::classLikeIn($this->backend, 'V\Nothing'),
            'closing a document that was never registered must not error',
        );
    }

    public function testLookupFunctionReturnsARegisteredFunction(): void
    {
        $this->backend->openDocument(
            new TextDocument('file:///helpers.php', 'php', 1, "<?php\nnamespace V;\nfunction format(): void {}\n"),
        );

        $info = self::functionIn($this->backend, 'V\format');

        self::assertNotNull($info, 'a registered function must resolve');
        self::assertSame(
            'V\format',
            $info->name->qualifiedName->fullyQualifiedName(),
            'the registered function must be returned unchanged',
        );
    }

    public function testLookupFunctionIsCaseInsensitive(): void
    {
        $this->backend->openDocument(
            new TextDocument('file:///helpers.php', 'php', 1, "<?php\nnamespace V;\nfunction format(): void {}\n"),
        );

        self::assertNotNull(
            self::functionIn($this->backend, 'V\FORMAT'),
            'PHP matches function names case-insensitively',
        );
    }

    public function testLookupFunctionReturnsNullForAnUnregisteredFunction(): void
    {
        self::assertNull(
            self::functionIn($this->backend, 'V\absent'),
            'a name no open document declares is absent from this backend (RFC 1 §5.3)',
        );
    }

    public function testRegistrationCarriesEveryKind(): void
    {
        // The point of the kind-parameterized write path: registering a document with
        // a constant answers for that kind and not for another. Constants are the one
        // kind PHP matches case-sensitively on the name itself; the namespace is still
        // case-insensitive.
        $this->backend->openDocument(
            new TextDocument('file:///consts.php', 'php', 1, "<?php\nnamespace V;\nconst LIMIT = 1;\n"),
        );

        self::assertNotNull(
            $this->backend->lookupConstant(ConstantName::fromFullyQualified('V\LIMIT')),
            'a registered constant must resolve for the constant kind',
        );
        self::assertNull(
            $this->backend->lookupFunction(FunctionName::fromFullyQualified('V\LIMIT')),
            'and must not answer for another symbol namespace',
        );
        self::assertNotNull(
            $this->backend->lookupConstant(ConstantName::fromFullyQualified('v\LIMIT')),
            'the namespace of a constant is still matched case-insensitively',
        );
        self::assertNull(
            $this->backend->lookupConstant(ConstantName::fromFullyQualified('V\limit')),
            'but its own name is not: constants are the one kind PHP matches case-sensitively',
        );
    }

    public function testFunctionAndClassLikeRegistrationsDoNotCollide(): void
    {
        $this->backend->openDocument(new TextDocument(
            'file:///Dual.php',
            'php',
            1,
            "<?php\nnamespace V;\nclass Dual {}\nfunction Dual(): void {}\n",
        ));

        self::assertNotNull(
            self::classLikeIn($this->backend, 'V\Dual'),
            'the class-like must resolve',
        );
        self::assertNotNull(
            self::functionIn($this->backend, 'V\Dual'),
            'a function sharing the name must resolve too: the symbol namespaces are independent',
        );
    }

    public function testUpdateDocumentReplacesThePriorFunctionsForThatUri(): void
    {
        $uri = 'file:///helpers.php';
        $this->backend->openDocument(
            new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nfunction alpha(): void {}\n"),
        );
        $this->backend->updateDocument(
            new TextDocument($uri, 'php', 2, "<?php\nnamespace V;\nfunction beta(): void {}\n"),
        );

        self::assertNull(
            self::functionIn($this->backend, 'V\alpha'),
            'the prior function must be dropped when the document is re-registered',
        );
        self::assertNotNull(
            self::functionIn($this->backend, 'V\beta'),
            'the new function must be registered',
        );
    }

    public function testCloseDocumentDropsItsFunctions(): void
    {
        $uri = 'file:///helpers.php';
        $this->backend->openDocument(
            new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nfunction ephemeral(): void {}\n"),
        );

        $this->backend->closeDocument($uri);

        self::assertNull(
            self::functionIn($this->backend, 'V\ephemeral'),
            'closing a document must drop the functions it registered',
        );
    }

    public function testOpenDocumentRegistersADeclarationBelowTheTopLevel(): void
    {
        // A conditionally declared polyfill is a name the file validly declares, and
        // the on-disk backends resolve one. An open document must agree, or opening
        // a file would make a name that already resolved disappear (RFC 1 §4.2).
        $content = "<?php\nif (!function_exists('polyfill')) {\n    function polyfill(): void {}\n}\n";

        $this->backend->openDocument(new TextDocument('file:///polyfill.php', 'php', 1, $content));

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
        $this->backend->openDocument(
            new TextDocument($uri, 'php', 1, $this->loadFixture('MultiClass/MultiClass.php')),
        );

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
        $this->backend->openDocument(new TextDocument($uri, 'php', 1, $content));

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
        $this->backend->openDocument(new TextDocument($uri, 'php', 1, $content));

        $functionInfo = self::functionIn($this->backend, 'Fixtures\MultiClass\duplicated');
        self::assertNotNull($functionInfo, 'the duplicated function must still resolve');
        self::assertSame(
            'string',
            $functionInfo->returnType?->format(),
            'the first declaration (returning string) must win, matching runtime and the on-disk backends',
        );
    }

    public function testSearchClassLikeFiltersByPrefixAndToClassLikeKindsOnly(): void
    {
        $this->backend->openDocument(new TextDocument(
            'file:///doc.php',
            'php',
            1,
            "<?php\nnamespace App;\n"
                . "class User {}\n"
                . "class UserEnum {}\n"
                . "interface UserInterface {}\n"
                . "trait UserTrait {}\n"
                . "class Entity {}\n"
                . "function Userland(): void {}\n",
        ));

        $results = $this->backend->search('User', NameKind::ClassLike);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains('App\User', $fqns, 'a class-like matching the prefix must be found');
        self::assertContains('App\UserEnum', $fqns, 'another class-like matching the prefix must be found');
        self::assertContains('App\UserInterface', $fqns, 'a third class-like matching the prefix must be found');
        self::assertContains('App\UserTrait', $fqns, 'a fourth class-like matching the prefix must be found');
        self::assertNotContains('App\Entity', $fqns, 'a class-like not matching the prefix must be excluded');
        self::assertNotContains(
            'App\Userland',
            $fqns,
            'a function must be excluded even when its name matches the prefix',
        );
    }

    public function testSearchFunctionFiltersByPrefixAndToFunctionKindOnly(): void
    {
        $this->backend->openDocument(new TextDocument(
            'file:///doc.php',
            'php',
            1,
            "<?php\nnamespace App;\nfunction format(): void {}\nclass Formatter {}\n",
        ));

        $results = $this->backend->search('format', NameKind::Function_);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains('App\format', $fqns, 'a function matching the prefix must be found');
        self::assertNotContains(
            'App\Formatter',
            $fqns,
            'a class-like must be excluded from a function search',
        );
    }

    public function testSearchConstantFiltersByPrefixAndToConstantKindOnly(): void
    {
        $this->backend->openDocument(new TextDocument(
            'file:///doc.php',
            'php',
            1,
            "<?php\nnamespace App;\nconst DEBUG = 1;\nclass Debugger {}\n",
        ));

        $results = $this->backend->search('D', NameKind::Constant);

        $fqns = array_map(static fn(Symbol $s): string => $s->fullyQualifiedName, $results);
        self::assertContains('App\DEBUG', $fqns, 'a constant matching the prefix must be found');
        self::assertNotContains(
            'App\Debugger',
            $fqns,
            'a class-like must be excluded from a constant search',
        );
    }

    public function testLookupResolvesToTheFirstDeclarationWhenTwoDocumentsShareAName(): void
    {
        $firstUri = 'file:///first/Shared.php';
        $secondUri = 'file:///second/Shared.php';
        $this->backend->openDocument(
            new TextDocument($firstUri, 'php', 1, "<?php\nnamespace V;\nclass Shared {}\n"),
        );
        $this->backend->openDocument(
            new TextDocument($secondUri, 'php', 1, "<?php\nnamespace V;\nclass Shared {}\n"),
        );

        $info = self::classLikeIn($this->backend, 'V\Shared');

        self::assertNotNull($info, 'a name two documents declare must still resolve');
        self::assertSame(
            '/first/Shared.php',
            $info->file,
            'the first document to declare the name in map order wins lookup, matching search and childrenOf',
        );
    }

    public function testClosingOneOfTwoDocumentsThatShareANameKeepsTheOtherReachable(): void
    {
        $firstUri = 'file:///first/Shared.php';
        $secondUri = 'file:///second/Shared.php';
        $this->backend->openDocument(
            new TextDocument($firstUri, 'php', 1, "<?php\nnamespace V;\nclass Shared {}\n"),
        );
        $this->backend->openDocument(
            new TextDocument($secondUri, 'php', 1, "<?php\nnamespace V;\nclass Shared {}\n"),
        );

        $this->backend->closeDocument($secondUri);

        $info = self::classLikeIn($this->backend, 'V\Shared');
        self::assertNotNull(
            $info,
            'the still-open document keeps declaring the name; closing another must not drop lookup',
        );
        self::assertSame(
            '/first/Shared.php',
            $info->file,
            'the surviving declaration is the one the still-open document holds',
        );
    }

    public function testUpdatingTheFirstOfTwoDocumentsThatShareANameKeepsItsPrecedence(): void
    {
        $firstUri = 'file:///first/Shared.php';
        $secondUri = 'file:///second/Shared.php';
        $this->backend->openDocument(
            new TextDocument($firstUri, 'php', 1, "<?php\nnamespace V;\nclass Shared {}\n"),
        );
        $this->backend->openDocument(
            new TextDocument($secondUri, 'php', 1, "<?php\nnamespace V;\nclass Shared {}\n"),
        );

        $this->backend->updateDocument(
            new TextDocument($firstUri, 'php', 2, "<?php\nnamespace V;\nfinal class Shared {}\n"),
        );

        $info = self::classLikeIn($this->backend, 'V\Shared');
        self::assertNotNull($info, 'a name two documents declare must still resolve after an edit');
        self::assertTrue(
            $info->isFinal,
            'editing a document keeps its place in map order, so it still wins over a later-opened document',
        );
    }

    public function testSearchReportsASharedNameOnceAcrossDocuments(): void
    {
        $this->backend->openDocument(
            new TextDocument('file:///a.php', 'php', 1, "<?php\nnamespace App;\nclass Widget {}\n"),
        );
        $this->backend->openDocument(
            new TextDocument('file:///b.php', 'php', 1, "<?php\nnamespace App;\nclass Widget {}\n"),
        );

        $results = $this->backend->search('Widget', NameKind::ClassLike);

        self::assertCount(
            1,
            $results,
            'a name two documents declare must appear once in search, matching lookup',
        );
        self::assertSame(
            'file:///a.php',
            $results[0]->location->uri,
            'the first-declaring document wins the merged entry, matching lookup',
        );
    }

    public function testChildrenOfReportsASharedNameOnceAcrossDocuments(): void
    {
        $this->backend->openDocument(
            new TextDocument('file:///a.php', 'php', 1, "<?php\nnamespace App;\nclass Widget {}\n"),
        );
        // Same class under PHP's case-insensitive rule; the spelling is the only
        // way to see which document's declaration survived the merge.
        $this->backend->openDocument(
            new TextDocument('file:///b.php', 'php', 1, "<?php\nnamespace App;\nclass WIDGET {}\n"),
        );

        $contents = $this->backend->childrenOf(new NamespaceName('App'));

        $fqns = array_map(
            static fn($symbol): string => $symbol->fullyQualifiedName,
            $contents->symbols,
        );
        self::assertSame(
            ['App\Widget'],
            $fqns,
            'a name two documents declare must appear once when the namespace is enumerated, '
                . 'spelled as the first-declaring document spells it, matching lookup and search',
        );
    }

    public function testChildrenOfEnumeratesTheOpenDocumentNamespace(): void
    {
        $this->backend->openDocument(
            new TextDocument('file:///User.php', 'php', 1, "<?php\nnamespace App;\nclass User {}\n"),
        );
        $this->backend->openDocument(
            new TextDocument('file:///Thing.php', 'php', 1, "<?php\nnamespace App\\Sub;\nclass Thing {}\n"),
        );

        $contents = $this->backend->childrenOf(new NamespaceName('App'));

        $symbolFqns = array_map(
            static fn($symbol): string => $symbol->fullyQualifiedName,
            $contents->symbols,
        );
        self::assertContains('App\User', $symbolFqns, 'a symbol declared directly in the namespace must be listed');
        self::assertContains(
            'App\Sub',
            $contents->childNamespaces,
            'a namespace with a deeper declaration must be listed as a child',
        );
    }

    public function testOpeningABrokenFileRegistersTheShapeTheSkeletonRecovers(): void
    {
        // The skeleton source in the composite recovers a mid-edit document's
        // structural shape, so the write path feeds `DeclarationScanner` a tree even
        // when php-parser alone would produce none (RFC 1 §5.3). Completion of
        // `$this->` on a class the user is still typing must still find its members.
        $uri = 'file:///Broken.php';
        $content = $this->loadFixture('src/IncompleteCode/VeryBroken.php');
        $document = new TextDocument($uri, 'php', 1, $content);

        // Precondition that separates the composite's two arms: php-parser alone
        // yields nothing on this fixture, so a class registered after the write can
        // only have come from the skeleton.
        $phpParserOnly = new PhpParserSyntaxSource(new TreeAnnotator(), new ParseMetrics());
        self::assertSame(
            [],
            $phpParserOnly->parse($document),
            'php-parser alone must yield nothing on this fixture, or the test proves nothing about the skeleton',
        );

        $this->backend->openDocument($document);

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
        $this->backend->openDocument(new TextDocument($uri, 'php', 1, $this->loadFixture($fixture)));

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

    private function widgetDocument(string $uri): TextDocument
    {
        return new TextDocument($uri, 'php', 1, "<?php\nnamespace V;\nclass Widget {}\n");
    }

    private static function buildBackend(): OpenDocumentBackend
    {
        return new OpenDocumentBackend(
            ProductionSyntaxSource::create()->source,
            new DeclarationScanner(),
            new DeclarationSymbolInfoFactory(),
        );
    }
}
