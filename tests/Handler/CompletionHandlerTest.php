<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\ClassCandidates;
use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompletionItemKind;
use Firehed\PhpLsp\Completion\FunctionCandidates;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Completion\VariableCandidates;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionHandler::class)]
#[CoversClass(BuiltinTypeCandidates::class)]
#[CoversClass(ClassCandidates::class)]
#[CoversClass(CompletionItemFactory::class)]
#[CoversClass(FunctionCandidates::class)]
#[CoversClass(KeywordCandidates::class)]
#[CoversClass(MemberCandidates::class)]
#[CoversClass(NamedArgumentCandidates::class)]
#[CoversClass(VariableCandidates::class)]
class CompletionHandlerTest extends TestCase
{
    use OpensDocumentsTrait;

    private DocumentManager $documents;
    private ParserService $parser;
    private SymbolIndex $symbolIndex;
    private DefaultClassRepository $classRepository;
    private DefaultClassInfoFactory $classInfoFactory;
    private MemberResolver $memberResolver;
    private CompletionHandler $handler;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $this->parser = new ParserService();
        $this->symbolIndex = new SymbolIndex();
        $this->classInfoFactory = new DefaultClassInfoFactory();
        $locator = new ComposerClassLocator(__DIR__ . '/../Fixtures');
        $this->classRepository = new DefaultClassRepository(
            $this->classInfoFactory,
            $locator,
            $this->parser,
        );
        $this->memberResolver = new MemberResolver($this->classRepository);
        $typeResolver = new BasicTypeResolver($this->memberResolver);
        $symbolResolver = new SymbolResolver(
            $this->parser,
            $this->classRepository,
            $this->memberResolver,
            $typeResolver,
        );
        $indexer = new DocumentIndexer($this->parser, new SymbolExtractor(), $this->symbolIndex);
        $this->handler = new CompletionHandler(
            $this->documents,
            $symbolResolver,
            new ClassCandidates($this->symbolIndex, $symbolResolver),
            new FunctionCandidates($symbolResolver),
            new KeywordCandidates(),
            new VariableCandidates($symbolResolver),
            new MemberCandidates($symbolResolver),
            new NamedArgumentCandidates(),
            new BuiltinTypeCandidates(),
        );
        $this->syncHandler = new TextDocumentSyncHandler(
            $this->documents,
            $this->parser,
            $this->classRepository,
            $this->classInfoFactory,
            $indexer,
        );
    }

    public function testSupports(): void
    {
        self::assertTrue($this->handler->supports('textDocument/completion'));
        self::assertFalse($this->handler->supports('textDocument/hover'));
    }

    public function testThisMethodCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'this_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertNotEmpty($result['items']);

        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels);
        self::assertContains('setName', $labels);
        self::assertContains('getCount', $labels);
        self::assertContains('isActive', $labels);
    }

    public function testThisMethodCompletionWithPrefix(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'this_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels);
        self::assertContains('getCount', $labels);
        self::assertNotContains('setName', $labels);
        self::assertNotContains('isActive', $labels);
    }

    public function testThisPropertyCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'this_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('name', $labels);
        self::assertContains('count', $labels);
        self::assertContains('active', $labels);
    }

    public function testThisCompletionIncludesInheritedMembers(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/InheritanceCompletion.php', 'this_inherited');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Own members
        self::assertContains('ownProperty', $labels);
        self::assertContains('ownMethod', $labels);
        // Inherited from ChildClass
        self::assertContains('childMethod', $labels);
        // Inherited from ParentClass
        self::assertContains('parentMethod', $labels);
        // Inherited from Grandparent
        self::assertContains('grandparentMethod', $labels);
    }

    /**
     * @see https://github.com/Firehed/php-lsp/issues/185
     */
    public function testThisCompletionIncludesTraitMembers(): void
    {
        $this->openFixture('src/Traits/HasTimestamps.php');
        $cursor = $this->openFixtureAtCursor('src/Completion/InheritanceCompletion.php', 'this_inherited');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Trait properties from HasTimestamps
        self::assertContains('createdAt', $labels);
        self::assertContains('updatedAt', $labels);
        // Trait methods from HasTimestamps
        self::assertContains('getCreatedAt', $labels);
        self::assertContains('markCreated', $labels);
    }

    public function testStaticMethodCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/StaticCaller.php', 'external_static');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('create', $labels);
        self::assertContains('getInstance', $labels);
        self::assertContains('class', $labels);
    }

    public function testClassConstantCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/StaticCaller.php', 'external_static');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('NAME', $labels);
    }

    public function testStaticCompletionResolvesImportedClassName(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/MultiNamespaceImports.php', 'imported_static');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should resolve UserModel to Fixtures\Namespacing\Models\UserModel
        self::assertContains('STATUS_ACTIVE', $labels);
        self::assertContains('findById', $labels);
        self::assertContains('class', $labels);
    }

    public function testStaticCompletionResolvesAliasedImport(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/MultiNamespaceImports.php', 'aliased_static');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should resolve User alias to Fixtures\Namespacing\Models\UserModel
        self::assertContains('ROLE_ADMIN', $labels);
    }

    public function testStaticCompletionShowsOnlyPublicForExternalClass(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/StaticCaller.php', 'external_static');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Public members visible
        self::assertContains('create', $labels);
        self::assertContains('getInstance', $labels);
        self::assertContains('NAME', $labels);
        // Protected/private not visible from external class
        self::assertNotContains('reset', $labels);
        self::assertNotContains('INTERNAL', $labels);
        self::assertNotContains('SECRET', $labels);
    }

    public function testStaticCompletionShowsAllForSameClass(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/StaticAccess.php', 'self_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // All visibility levels visible from within same class
        self::assertContains('create', $labels);
        self::assertContains('getInstance', $labels);
        self::assertContains('reset', $labels);
        self::assertContains('NAME', $labels);
        self::assertContains('INTERNAL', $labels);
        self::assertContains('SECRET', $labels);
    }

    public function testStaticCompletionShowsPublicProtectedForSubclass(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/InheritanceCompletion.php', 'parent_class_static');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('staticMethod', $labels);
        self::assertContains('protectedStaticMethod', $labels);
        self::assertNotContains('privateStaticMethod', $labels);
    }

    public function testStaticCompletionShowsPublicProtectedForDirectChild(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Inheritance/ChildClass.php', 'direct_parent_static');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('staticMethod', $labels);
        self::assertContains('protectedStaticMethod', $labels);
        self::assertNotContains('privateStaticMethod', $labels);
    }

    public function testSelfCompletionIncludesInheritedStaticMembers(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/InheritanceCompletion.php', 'self_inherited');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Own static members
        self::assertContains('ownStaticProperty', $labels);
        self::assertContains('ownStaticMethod', $labels);
        // Inherited static members from ParentClass
        self::assertContains('staticProperty', $labels);
        self::assertContains('staticMethod', $labels);
        self::assertContains('PARENT_CONST', $labels);
    }

    public function testFunctionCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/FunctionCompletion.php', 'builtin_function');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include built-in functions starting with "arr"
        self::assertContains('array_map', $labels);
        self::assertContains('array_filter', $labels);
    }

    public function testExpressionCompletionIncludesImportedClasses(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'imported_class_partial');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include imported class
        self::assertContains('User', $labels);

        // Check that FQCN is in detail
        $userItems = array_filter($result['items'], fn($item) => $item['label'] === 'User');
        self::assertNotEmpty($userItems);
        $userItem = reset($userItems);
        self::assertIsArray($userItem);
        self::assertSame('Fixtures\Namespacing\Models\User', $userItem['detail'] ?? null);
    }

    public function testExpressionCompletionIncludesAliasedImports(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'aliased_class_partial');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include aliased import
        self::assertContains('Repo', $labels);

        // Check that FQCN is in detail
        $repoItems = array_filter($result['items'], fn($item) => $item['label'] === 'Repo');
        self::assertNotEmpty($repoItems);
        $repoItem = reset($repoItems);
        self::assertIsArray($repoItem);
        self::assertSame('Fixtures\Namespacing\Models\UserRepository', $repoItem['detail'] ?? null);
    }

    public function testExpressionCompletionIncludesGroupedImports(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'grouped_import_partial');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include both grouped imports
        self::assertContains('User', $labels);

        // Check that FQCN is correct for grouped import
        $userItems = array_filter($result['items'], fn($item) => $item['label'] === 'User');
        self::assertNotEmpty($userItems);
        $userItem = reset($userItems);
        self::assertIsArray($userItem);
        self::assertSame('Fixtures\Namespacing\Models\User', $userItem['detail'] ?? null);
    }

    public function testNewCompletionIncludesIndexedClasses(): void
    {
        // Add a class to the index
        $this->symbolIndex->add(new Symbol(
            'MyIndexedClass',
            'App\MyIndexedClass',
            SymbolKind::Class_,
            new Location('file:///other.php', 0, 0, 0, 0),
        ));

        $code = '<?php $x = new MyIn';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 19],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('MyIndexedClass', $labels);
    }

    public function testNewCompletionExcludesIndexedInterfaces(): void
    {
        // Add an interface and a class to the index
        $this->symbolIndex->add(new Symbol(
            'MyInterface',
            'App\MyInterface',
            SymbolKind::Interface_,
            new Location('file:///other.php', 0, 0, 0, 0),
        ));
        $this->symbolIndex->add(new Symbol(
            'MyClass',
            'App\MyClass',
            SymbolKind::Class_,
            new Location('file:///other.php', 0, 0, 0, 0),
        ));

        $code = '<?php $x = new My';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 17],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include class
        self::assertContains('MyClass', $labels);
        // Should NOT include interface
        self::assertNotContains('MyInterface', $labels);
    }

    public function testNewCompletionExcludesAbstractClasses(): void
    {
        // Index the classes first (AbstractBase is abstract, SealedClass is concrete)
        $this->openFixture('src/Utility/ClassModifiers.php');
        $cursor = $this->openFixtureAtCursor('src/Completion/NewCompletion.php', 'new_abstract');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // Should include concrete class (imported via use statement)
        self::assertContains('SealedClass', $labels);
        // Should NOT include abstract class
        self::assertNotContains('AbstractBase', $labels);
    }

    public function testExpressionCompletionIncludesAllIndexedTypes(): void
    {
        // Add various symbol types to the index
        $this->symbolIndex->add(new Symbol(
            'MyClass',
            'App\MyClass',
            SymbolKind::Class_,
            new Location('file:///other.php', 0, 0, 0, 0),
        ));
        $this->symbolIndex->add(new Symbol(
            'MyInterface',
            'App\MyInterface',
            SymbolKind::Interface_,
            new Location('file:///other.php', 0, 0, 0, 0),
        ));
        $this->symbolIndex->add(new Symbol(
            'MyTrait',
            'App\MyTrait',
            SymbolKind::Trait_,
            new Location('file:///other.php', 0, 0, 0, 0),
        ));

        // Expression context (not `new`)
        $code = '<?php $x = My';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 13],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Expression context should include all types
        self::assertContains('MyClass', $labels);
        self::assertContains('MyInterface', $labels);
        self::assertContains('MyTrait', $labels);
    }

    public function testTypeHintCompletionInReturnType(): void
    {
        $code = '<?php function foo(): str';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 25],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('string', $labels);
        // Should NOT contain functions
        self::assertNotContains('strlen', $labels);
        self::assertNotContains('str_replace', $labels);
    }

    public function testTypeHintCompletionInParameter(): void
    {
        $code = '<?php function foo(str';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 22],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('string', $labels);
    }

    public function testTypeHintCompletionIncludesBuiltinTypes(): void
    {
        $code = '<?php function foo(): ';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 22],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('string', $labels);
        self::assertContains('int', $labels);
        self::assertContains('bool', $labels);
        self::assertContains('array', $labels);
        self::assertContains('void', $labels);
        self::assertContains('mixed', $labels);
    }

    public function testTypeHintCompletionForPropertyType(): void
    {
        $code = '<?php class Foo { private str';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 29],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('string', $labels);
    }

    public function testPropertyTypeNullableExcludesInvalidTypes(): void
    {
        // Nullable type context (after ?)
        $code = '<?php trait MyTrait {} class Foo { private ?';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 45],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');

        self::assertContainsCommonBuiltinTypes($labels);
        self::assertNotContainsNonTypeItems($labels);

        // Invalid for property types specifically
        self::assertNotContains('void', $labels);
        self::assertNotContains('never', $labels);
        self::assertNotContains('self', $labels);
        self::assertNotContains('static', $labels);
        self::assertNotContains('parent', $labels);

        // Traits are not valid type hints
        self::assertNotContains('MyTrait', $labels);
    }

    public function testPropertyTypeUnionExcludesInvalidTypes(): void
    {
        // Union type context (after |)
        $code = '<?php trait MyTrait {} class Foo { private int|';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 48],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');

        self::assertContainsCommonBuiltinTypes($labels);
        self::assertNotContainsNonTypeItems($labels);

        // Invalid for property types
        self::assertNotContains('void', $labels);
        self::assertNotContains('never', $labels);
        self::assertNotContains('self', $labels);
        self::assertNotContains('static', $labels);
        self::assertNotContains('parent', $labels);

        // Traits are not valid type hints
        self::assertNotContains('MyTrait', $labels);
    }

    public function testPropertyTypeIntersectionExcludesInvalidTypes(): void
    {
        // Intersection type context (after &)
        $code = '<?php trait MyTrait {} class Foo { private Countable&';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 54],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');

        self::assertContainsCommonBuiltinTypes($labels);
        self::assertNotContainsNonTypeItems($labels);

        // Invalid for property types
        self::assertNotContains('void', $labels);
        self::assertNotContains('never', $labels);
        self::assertNotContains('self', $labels);
        self::assertNotContains('static', $labels);
        self::assertNotContains('parent', $labels);

        // Traits are not valid type hints
        self::assertNotContains('MyTrait', $labels);
    }

    public function testParameterTypeExcludesInvalidTypes(): void
    {
        $code = '<?php trait MyTrait {} function foo(';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 36],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');

        self::assertContainsCommonBuiltinTypes($labels);
        self::assertNotContainsNonTypeItems($labels);

        // self and parent ARE valid for parameters
        self::assertContains('self', $labels);
        self::assertContains('parent', $labels);

        // Invalid for parameter types specifically
        self::assertNotContains('void', $labels);
        self::assertNotContains('never', $labels);
        self::assertNotContains('static', $labels);

        // Traits are not valid type hints
        self::assertNotContains('MyTrait', $labels);
    }

    public function testReturnTypeIncludesAllValidTypes(): void
    {
        $code = '<?php trait MyTrait {} function foo(): ';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 39],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');

        self::assertContainsCommonBuiltinTypes($labels);
        self::assertNotContainsNonTypeItems($labels);

        // All special types valid for return
        self::assertContains('void', $labels);
        self::assertContains('never', $labels);
        self::assertContains('self', $labels);
        self::assertContains('static', $labels);
        self::assertContains('parent', $labels);

        // Traits are not valid type hints
        self::assertNotContains('MyTrait', $labels);
    }

    public function testReturnTypeUnionIncludesAllReturnTypes(): void
    {
        $code = '<?php trait MyTrait {} function foo(): int|';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 44],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');

        self::assertContainsCommonBuiltinTypes($labels);
        self::assertNotContainsNonTypeItems($labels);

        // All return-type-specific types should be available
        self::assertContains('static', $labels);
        self::assertContains('self', $labels);
        self::assertContains('parent', $labels);
        self::assertContains('void', $labels);
        self::assertContains('never', $labels);

        // Traits are not valid type hints
        self::assertNotContains('MyTrait', $labels);
    }

    public function testReturnTypeIntersectionIncludesAllReturnTypes(): void
    {
        $code = '<?php trait MyTrait {} function foo(): Countable&';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 50],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');

        self::assertContainsCommonBuiltinTypes($labels);
        self::assertNotContainsNonTypeItems($labels);

        // All return-type-specific types should be available
        self::assertContains('static', $labels);
        self::assertContains('self', $labels);
        self::assertContains('parent', $labels);
        self::assertContains('void', $labels);
        self::assertContains('never', $labels);

        // Traits are not valid type hints
        self::assertNotContains('MyTrait', $labels);
    }

    public function testReturnTypeNullableIncludesAllValidTypes(): void
    {
        // Nullable return type context (after ?)
        $code = '<?php function foo(): ?';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 23],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');

        self::assertContainsCommonBuiltinTypes($labels);
        self::assertNotContainsNonTypeItems($labels);

        // All return-type-specific types should be available
        self::assertContains('static', $labels);
        self::assertContains('self', $labels);
        self::assertContains('parent', $labels);
        self::assertContains('void', $labels);
        self::assertContains('never', $labels);
    }

    public function testReturnTypeNullableWithSpaceIncludesAllValidTypes(): void
    {
        // Edge case: space after ? in nullable return type (cursor after space, before typing)
        $code = '<?php function foo(): ? ';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 24],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');

        self::assertContainsCommonBuiltinTypes($labels);

        // Should be return type context, not parameter
        self::assertContains('static', $labels);
        self::assertContains('void', $labels);
        self::assertContains('never', $labels);
    }

    public function testImportedTraitAppearsInExpressionContext(): void
    {
        // Verify traits DO appear in expression context (control for type hint test)
        $this->openFixture('src/Traits/SingletonTrait.php');
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'trait_expression_partial');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');

        // Imported trait should appear in expression context
        self::assertContains('SingletonTrait', $labels);
    }

    public function testTypeHintExcludesImportedTraits(): void
    {
        // Issue #90: Traits imported via `use` statements should not appear in type hints
        $this->openFixture('src/Traits/SingletonTrait.php');
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'type_hint_return');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');

        // Should be detected as type hint context
        self::assertContains('string', $labels);

        // Imported classes should appear
        self::assertContains('User', $labels);

        // Imported trait should NOT appear in type hint context
        self::assertNotContains('SingletonTrait', $labels);
    }

    public function testKeywordCompletions(): void
    {
        $code = '<?php fore';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 10],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('foreach', $labels);
    }

    public function testKeywordCompletionsIncludeControlFlow(): void
    {
        $code = '<?php ret';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 9],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('return', $labels);
    }

    public function testKeywordCompletionsIncludeDeclarations(): void
    {
        $code = '<?php cla';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 9],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('class', $labels);
    }

    public function testClassBodyOnlySuggestsClassLevelKeywords(): void
    {
        $code = '<?php class Foo { p';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 19],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include visibility keywords
        self::assertContains('public', $labels);
        self::assertContains('private', $labels);
        self::assertContains('protected', $labels);
        // Should NOT include functions like print_r
        self::assertNotContains('print_r', $labels);
        self::assertNotContains('print', $labels);
    }

    public function testAfterVisibilityKeywordSuggestsFunction(): void
    {
        $code = '<?php class Foo { public f';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 26],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('function', $labels);
    }

    public function testAfterVisibilityKeywordSuggestsModifiers(): void
    {
        $code = '<?php class Foo { public s';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 26],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('static', $labels);
        self::assertContains('string', $labels); // type hint
    }

    public function testKeywordsNotSuggestedInTypeHintContext(): void
    {
        $code = '<?php function foo(): ret';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 25],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // return is not a valid type hint
        self::assertNotContains('return', $labels);
    }

    public function testVariableCompletionSuggestsParameters(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/Variables.php', 'param_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('$name', $labels);
        self::assertNotContains('$age', $labels);
    }

    public function testVariableCompletionSuggestsLocalVariables(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/Variables.php', 'local_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('$logger', $labels);
    }

    public function testVariableCompletionWorksInGlobalScope(): void
    {
        $cursor = $this->openFixtureAtCursor('TopLevel/global_scope_variable.php', 'global_var_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('$currentUser', $labels, 'File-level variable should be offered');
        self::assertNotContains('$loginCount', $labels, 'Prefix "c" should filter out $loginCount');
    }

    public function testMemberCompletionWorksOnGlobalVariable(): void
    {
        $cursor = $this->openFixtureAtCursor('TopLevel/global_scope_completion.php', 'global_member_access');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels, 'Members of a typed file-level variable should be offered');
    }

    public function testVariableCompletionSuggestsThisInMethod(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/Variables.php', 'this_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('$this', $labels);
    }

    public function testVariableCompletionThisShowsClassName(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/Variables.php', 'this_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $thisItems = array_filter($result['items'], fn($item) => $item['label'] === '$this');
        self::assertNotEmpty($thisItems);
        $thisItem = reset($thisItems);
        self::assertSame('Fixtures\Completion\Variables', $thisItem['detail'] ?? null);
    }

    public function testVariableCompletionWorksInClosures(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/Variables.php', 'closure_local');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('$localVar', $labels);
    }

    public function testVariableCompletionSuggestsForeachVariables(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/Variables.php', 'foreach_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('$item', $labels);
    }

    public function testVariableCompletionIsolatesScopes(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ClosureVariables.php', 'closure_scope_isolated');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('$siteDir', $labels);
        self::assertNotContains('$logger', $labels); // From other closure
    }

    public function testVariableCompletionShowsTypeInDetail(): void
    {
        $code = '<?php function foo(string $name) { $x; }';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 36], // After $
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $nameItems = array_filter($result['items'], fn($item) => $item['label'] === '$name');
        self::assertNotEmpty($nameItems);
        $nameItem = reset($nameItems);
        self::assertSame('string', $nameItem['detail'] ?? null);
    }

    public function testCompletionReturnsEmptyForUnknownContext(): void
    {
        $code = '<?php $x = 1;';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 12],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }

    public function testEnumCaseCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EnumUsage.php', 'unit_enum_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Active', $labels);
        self::assertNotContains('Inactive', $labels);
        self::assertNotContains('Pending', $labels);
    }

    public function testEnumCaseCompletionNoPrefix(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EnumUsage.php', 'unit_enum_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Active', $labels);
        self::assertContains('Inactive', $labels);
        self::assertContains('Pending', $labels);
        self::assertContains('class', $labels);

        // Check unit enum case detail
        $activeItems = array_filter($result['items'], fn($item) => $item['label'] === 'Active');
        self::assertNotEmpty($activeItems);
        $activeItem = reset($activeItems);
        self::assertSame('case Active', $activeItem['detail'] ?? '');
    }

    public function testEnumBuiltinMethodCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EnumUsage.php', 'unit_enum_builtin');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('cases', $labels);
        self::assertContains('class', $labels);
    }

    public function testBackedEnumCompletionInt(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EnumUsage.php', 'backed_int_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Cases
        self::assertContains('Low', $labels);
        self::assertContains('Medium', $labels);
        self::assertContains('High', $labels);
        // Built-in methods for backed enums
        self::assertContains('cases', $labels);
        self::assertContains('from', $labels);
        self::assertContains('tryFrom', $labels);
        // Magic constant
        self::assertContains('class', $labels);

        // Check from() signature shows int type
        $fromItems = array_filter($result['items'], fn($item) => $item['label'] === 'from');
        self::assertNotEmpty($fromItems);
        $fromItem = reset($fromItems);
        self::assertStringContainsString('int', $fromItem['detail'] ?? '');

        // Check enum case detail shows backing value
        $lowItems = array_filter($result['items'], fn($item) => $item['label'] === 'Low');
        self::assertNotEmpty($lowItems);
        $lowItem = reset($lowItems);
        self::assertSame('case Low = 1', $lowItem['detail'] ?? '');
    }

    public function testBackedEnumCompletionString(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EnumUsage.php', 'backed_string_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Cases
        self::assertContains('Red', $labels);
        self::assertContains('Green', $labels);
        self::assertContains('Blue', $labels);
        // Built-in methods for backed enums
        self::assertContains('cases', $labels);
        self::assertContains('from', $labels);
        self::assertContains('tryFrom', $labels);

        // Check from() signature shows string type
        $fromItems = array_filter($result['items'], fn($item) => $item['label'] === 'from');
        self::assertNotEmpty($fromItems);
        $fromItem = reset($fromItems);
        self::assertStringContainsString('string', $fromItem['detail'] ?? '');

        // Check enum case detail shows backing value
        $redItems = array_filter($result['items'], fn($item) => $item['label'] === 'Red');
        self::assertNotEmpty($redItems);
        $redItem = reset($redItems);
        self::assertSame("case Red = 'red'", $redItem['detail'] ?? '');
    }

    public function testBackedEnumMethodPrefixFiltering(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EnumUsage.php', 'backed_int_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('from', $labels);
        self::assertNotContains('cases', $labels);
        self::assertNotContains('tryFrom', $labels);
        self::assertNotContains('Low', $labels);
        self::assertNotContains('High', $labels);
    }

    public function testTypedVariableCompletionFromParameter(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'param_access');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // Members available via typed parameter
        self::assertContains('getName', $labels);
        self::assertContains('setName', $labels);
        self::assertContains('active', $labels);
    }

    public function testTypedVariableCompletionFromNewExpression(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'var_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels);
        self::assertContains('setName', $labels);
    }

    public function testTypedVariableCompletionWithPrefix(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'var_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels);
        self::assertContains('getCount', $labels);
        self::assertNotContains('setName', $labels);
    }

    public function testTypedVariableCompletionFromStaticMethodReturningSelf(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Mixed/ProceduralWithClass.php', 'var_from_static_call');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // Static method 'create' should not appear in instance completions
        self::assertNotContains('create', $labels);
        // Instance methods should appear
        self::assertContains('triggerSelfEmpty', $labels);
    }

    public function testTypedVariableCompletionFromStaticMethodReturningSelfNullsafe(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Mixed/ProceduralWithClass.php', 'var_from_static_call_nullsafe');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // Static method 'create' should not appear in instance completions
        self::assertNotContains('create', $labels);
        // Instance methods should appear
        self::assertContains('triggerSelfEmpty', $labels);
    }

    public function testTypedVariableCompletionReturnsEmptyWhenTypeUnknown(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Mixed/ProceduralWithClass.php', 'unknown_var');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }

    public function testDynamicVariableNameReturnsEmpty(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Mixed/ProceduralWithClass.php', 'dynamic_var');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }

    public function testDynamicStaticAccessReturnsEmpty(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Mixed/ProceduralWithClass.php', 'dynamic_static');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }

    public function testTypedVariableCompletionIncludesInheritedMembers(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Mixed/ProceduralWithClass.php', 'array_object_access');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // ArrayObject methods from reflection
        self::assertContains('append', $labels);
        self::assertContains('count', $labels);
        self::assertContains('getIterator', $labels);
    }

    public function testTypedVariableCompletionExcludesNonPublicMembers(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ExternalAccess.php', 'external_method_access');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Public members should be included
        self::assertContains('active', $labels);
        self::assertContains('getName', $labels);
        self::assertContains('getCount', $labels);
        // Protected and private members should be excluded
        self::assertNotContains('name', $labels);
        self::assertNotContains('count', $labels);
        self::assertNotContains('secretMethod', $labels);
        self::assertNotContains('hiddenMethod', $labels);
    }

    public function testStandaloneFunctionAccessExcludesNonPublicMembers(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Mixed/ProceduralWithClass.php', 'standalone_function_access');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Public members should be included
        self::assertContains('active', $labels);
        self::assertContains('getName', $labels);
        // Non-public members should be excluded (no enclosing class context)
        self::assertNotContains('name', $labels);
        self::assertNotContains('secretMethod', $labels);
    }

    public function testStandaloneFunctionStaticAccessExcludesNonPublicMembers(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Mixed/ProceduralWithClass.php', 'standalone_static_access');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Public static members should be included
        self::assertContains('create', $labels);
        self::assertContains('NAME', $labels);
        // Non-public members should be excluded (no enclosing class context)
        self::assertNotContains('INTERNAL', $labels);
        self::assertNotContains('SECRET', $labels);
        self::assertNotContains('reset', $labels);
    }

    public function testSelfConstantCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/StaticAccess.php', 'self_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('NAME', $labels);
        self::assertContains('INTERNAL', $labels);
        self::assertContains('SECRET', $labels);
        self::assertContains('class', $labels);
    }


    public function testStaticConstantCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/StaticAccess.php', 'static_keyword');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('NAME', $labels);
        self::assertContains('INTERNAL', $labels);
        self::assertContains('SECRET', $labels);
        self::assertContains('class', $labels);
    }

    public function testSelfConstantCompletionWithPrefix(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/StaticAccess.php', 'self_const_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('NAME', $labels);
        self::assertNotContains('INTERNAL', $labels);
        self::assertNotContains('SECRET', $labels);
        self::assertNotContains('class', $labels);
    }

    public function testSelfCompletionInAnonymousClassReturnsEmpty(): void
    {
        $cursor = $this->openFixtureAtCursor('AnonymousClass.php', 'self_in_anonymous');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertEmpty($result['items']);
    }

    public function testSelfCompletionInMultiClassFile(): void
    {
        $cursor = $this->openFixtureAtCursor('MultiClass/MultiClass.php', 'self_in_second_class');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('SECOND_CONST', $labels);
        self::assertNotContains('FIRST_CONST', $labels);
    }

    public function testSelfStaticMethodCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/StaticAccess.php', 'self_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // Static methods should appear
        self::assertContains('create', $labels);
        self::assertContains('getInstance', $labels);
        self::assertContains('reset', $labels);
        // Instance methods should NOT appear
        self::assertNotContains('triggerSelfEmpty', $labels);
        self::assertNotContains('triggerSelfPrefix', $labels);
    }

    public function testSelfStaticPropertyCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/StaticAccess.php', 'self_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // Static properties should appear
        self::assertContains('instance', $labels);
        self::assertContains('counter', $labels);
        // Instance properties should NOT appear
        self::assertNotContains('instanceProp', $labels);
    }

    public function testSelfCompletionOutsideClassReturnsEmpty(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Mixed/ProceduralWithClass.php', 'self_outside_class');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertEmpty($result['items']);
    }

    public function testParentMethodCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/InheritanceCompletion.php', 'parent_access');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('__construct', $labels);
        self::assertContains('parentMethod', $labels);
        self::assertContains('protectedMethod', $labels);
    }

    public function testParentMethodCompletionIncludesStaticMethods(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/InheritanceCompletion.php', 'parent_access');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('staticMethod', $labels);
        self::assertContains('protectedStaticMethod', $labels);
    }

    public function testParentMethodCompletionReturnsEmptyWhenNoParent(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NoParent.php', 'parent_no_parent');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertEmpty($result['items']);
    }

    public function testParentMethodCompletionWithPrefix(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/InheritanceCompletion.php', 'parent_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('parentMethod', $labels);
        self::assertContains('protectedMethod', $labels);
        self::assertNotContains('staticMethod', $labels);
        self::assertNotContains('__construct', $labels);
    }

    public function testTypedVariableCompletionResolvesParameterType(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Mixed/ProceduralWithClass.php', 'user_param_access');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels);
    }

    /**
     * Assert that type hint completions contain common valid builtin types.
     *
     * @param list<string> $labels
     */
    private static function assertContainsCommonBuiltinTypes(array $labels): void
    {
        self::assertContains('string', $labels);
        self::assertContains('int', $labels);
        self::assertContains('float', $labels);
        self::assertContains('bool', $labels);
        self::assertContains('array', $labels);
        self::assertContains('object', $labels);
        self::assertContains('mixed', $labels);
        self::assertContains('iterable', $labels);
        self::assertContains('callable', $labels);
        self::assertContains('null', $labels);
        self::assertContains('true', $labels);
        self::assertContains('false', $labels);
    }

    /**
     * Assert that completions do NOT contain items invalid in any type hint context.
     *
     * @param list<string> $labels
     */
    private static function assertNotContainsNonTypeItems(array $labels): void
    {
        // Functions should never appear in type hints
        self::assertNotContains('strlen', $labels);
        self::assertNotContains('array_map', $labels);
        self::assertNotContains('str_replace', $labels);
        self::assertNotContains('preg_match', $labels);
        self::assertNotContains('json_encode', $labels);

        // Control flow keywords
        self::assertNotContains('if', $labels);
        self::assertNotContains('else', $labels);
        self::assertNotContains('foreach', $labels);
        self::assertNotContains('while', $labels);
        self::assertNotContains('for', $labels);
        self::assertNotContains('switch', $labels);
        self::assertNotContains('match', $labels);
        self::assertNotContains('try', $labels);
        self::assertNotContains('catch', $labels);
        self::assertNotContains('return', $labels);
        self::assertNotContains('throw', $labels);

        // Declaration keywords
        self::assertNotContains('class', $labels);
        self::assertNotContains('interface', $labels);
        self::assertNotContains('trait', $labels);
        self::assertNotContains('enum', $labels);
        self::assertNotContains('function', $labels);
        self::assertNotContains('namespace', $labels);
        self::assertNotContains('use', $labels);
        self::assertNotContains('extends', $labels);
        self::assertNotContains('implements', $labels);
        self::assertNotContains('const', $labels);

        // Visibility/modifier keywords
        self::assertNotContains('public', $labels);
        self::assertNotContains('private', $labels);
        self::assertNotContains('protected', $labels);
        self::assertNotContains('final', $labels);
        self::assertNotContains('abstract', $labels);
        self::assertNotContains('readonly', $labels);

        // Other non-type keywords
        self::assertNotContains('new', $labels);
        self::assertNotContains('instanceof', $labels);
        self::assertNotContains('clone', $labels);
        self::assertNotContains('echo', $labels);
        self::assertNotContains('print', $labels);
        self::assertNotContains('include', $labels);
        self::assertNotContains('require', $labels);
        self::assertNotContains('global', $labels);
        self::assertNotContains('unset', $labels);
        self::assertNotContains('isset', $labels);
        self::assertNotContains('empty', $labels);
        self::assertNotContains('list', $labels);
        self::assertNotContains('fn', $labels);
        self::assertNotContains('yield', $labels);

        // PHP constants
        self::assertNotContains('PHP_VERSION', $labels);
        self::assertNotContains('PHP_INT_MAX', $labels);
    }

    public function testThisCompletionOutsideClassReturnsEmpty(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Mixed/ProceduralWithClass.php', 'this_outside_class');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }

    public function testThisCompletionInAnonymousClassReturnsEmpty(): void
    {
        $cursor = $this->openFixtureAtCursor('AnonymousClass.php', 'this_in_anonymous');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }

    public function testStaticCompletionFromAnonymousClassContext(): void
    {
        $cursor = $this->openFixtureAtCursor('AnonymousClass.php', 'static_from_anonymous');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // External static access from anonymous class - only public visible
        self::assertContains('create', $labels);
        self::assertNotContains('reset', $labels);
    }

    public function testStaticCompletionWithDeeperInheritance(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Inheritance/ChildClass.php', 'grandparent_access');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // ChildClass extends ParentClass extends Grandparent - should see protected static members
        self::assertContains('grandparentStaticPublic', $labels);
        self::assertContains('grandparentStaticProtected', $labels);
    }

    public function testStaticCompletionInClassWithoutNamespace(): void
    {
        $cursor = $this->openFixtureAtCursor('NoNamespace.php', 'self_no_namespace');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('staticMethod', $labels);
    }

    public function testUserDefinedFunctionCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('FunctionCompletion.php', 'user_defined_function');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $items = $result['items'];
        $labels = array_column($items, 'label');

        self::assertContains('calculateSum', $labels, 'calculateSum should be in completions');

        $functionItem = null;
        foreach ($items as $item) {
            if ($item['label'] === 'calculateSum') {
                $functionItem = $item;
                break;
            }
        }

        self::assertNotNull($functionItem);
        self::assertSame(3, $functionItem['kind'] ?? null); // KIND_FUNCTION
        $detail = $functionItem['detail'] ?? '';
        self::assertStringContainsString('function calculateSum', $detail);
        self::assertStringContainsString('int $a', $detail);
        self::assertStringContainsString('int $b', $detail);
        self::assertStringContainsString(': int', $detail);
        self::assertStringContainsString('Adds two numbers', $functionItem['documentation'] ?? '');
    }

    public function testThisCompletionTargetsEnclosingClassNotFirstClass(): void
    {
        // Issue #173: When multiple classes in file, $this-> should complete
        // members of the enclosing class, not the first class in the file
        $cursor = $this->openFixtureAtCursor('MultiClass/MultiClass.php', 'this_in_second_class');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');

        // Should have ChildInMultiFile's own members
        self::assertContains('ownProperty', $labels);
        self::assertContains('ownMethod', $labels);
        self::assertContains('triggerThisInChild', $labels);

        // Should also have inherited members
        self::assertContains('inheritedProperty', $labels);
        self::assertContains('inheritedMethod', $labels);
    }

    public function testThisCompletionWithUnrelatedClassesInFile(): void
    {
        // Two unrelated classes in the same file - cursor in second class
        // should get its members, not the first class's
        $cursor = $this->openFixtureAtCursor('MultiClass/MultiClass.php', 'this_in_unrelated_second');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');

        // Should have SecondUnrelated's members
        self::assertContains('secondProperty', $labels);
        self::assertContains('secondMethod', $labels);
        self::assertContains('triggerThisInSecond', $labels);

        // Should NOT have FirstUnrelated's members
        self::assertNotContains('firstProperty', $labels);
        self::assertNotContains('firstMethod', $labels);
    }

    public function testNullsafeThisMemberCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'nullsafe_this_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels);
        self::assertContains('setName', $labels);
    }

    public function testNullsafeVariableMemberCompletion(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'nullsafe_var_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels);
    }

    public function testNullsafeThisMemberCompletionWithPrefix(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'nullsafe_this_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels);
        self::assertContains('getCount', $labels);
        self::assertNotContains('setName', $labels);
    }

    // Chain completion tests (issue #11)

    public function testChainCompletionPropertyChain(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ChainCompletion.php', 'property_chain');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels);
    }

    public function testChainCompletionMethodChain(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ChainCompletion.php', 'method_chain');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels);
    }

    public function testChainCompletionOnPrimitiveReturnsEmpty(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ChainCompletion.php', 'primitive_chain');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertSame([], $result['items']);
    }

    public function testChainCompletionMultiLevel(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ChainCompletion.php', 'multi_level_chain');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('toUpper', $labels);
    }

    public function testChainCompletionFromStaticMethod(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ChainCompletion.php', 'static_method_chain');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('build', $labels);
    }

    public function testChainCompletionFromFunctionCall(): void
    {
        $cursor = $this->openFixtureAtCursor('FunctionCompletion.php', 'function_return_chain');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('get', $labels);
    }

    public function testChainCompletionMultiLine(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ChainCompletion.php', 'multi_line_chain');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('toUpper', $labels);
    }

    public function testChainCompletionNullsafePropertyChain(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ChainCompletion.php', 'nullsafe_property_chain');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels);
    }

    public function testChainCompletionMixedNullsafe(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ChainCompletion.php', 'mixed_nullsafe_chain');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels);
    }

    public function testChainCompletionNamespacedFunctionReturn(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/FunctionCompletion.php', 'function_return_chain');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('get', $labels);
    }

    public function testSameClassVisibilityShowsPrivateMembers(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'param_access');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // Same-class access shows all visibility levels
        self::assertContains('secretMethod', $labels);
        self::assertContains('hiddenMethod', $labels);
        self::assertContains('getName', $labels);
    }

    public function testChainCompletionAfterSelfStaticCall(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/StaticAccess.php', 'self_chain');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getInstanceProp', $labels);
        self::assertContains('instanceProp', $labels);
    }

    public function testChainCompletionAfterStaticStaticCall(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/StaticAccess.php', 'static_chain');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getInstanceProp', $labels);
        self::assertContains('instanceProp', $labels);
    }

    public function testChainCompletionAfterSelfStaticCallWithPrefix(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/StaticAccess.php', 'self_chain_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getInstanceProp', $labels);
        self::assertNotContains('instanceProp', $labels);
    }

    // =========================================================================
    // Context-based filtering
    // =========================================================================

    public function testNoCompletionsInComment(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ContextFiltering.php', 'in_comment');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertSame([], $result['items'], 'No completions should be offered inside comments');
    }

    public function testNoCompletionsForMemberAccessInComment(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ContextFiltering.php', 'member_in_comment');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertSame([], $result['items'], 'No completions for $this-> inside comments');
    }

    public function testOnlyVariablesInHeredoc(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/ContextFiltering.php', 'in_heredoc');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);

        foreach ($result['items'] as $item) {
            self::assertSame(
                6, // KIND_VARIABLE
                $item['kind'] ?? 0,
                "Only variable completions should be offered in heredoc, got: {$item['label']}",
            );
        }
    }

    // =========================================================================
    // Named argument completion (issue #126)
    // =========================================================================

    public function testNamedArgumentCompletionEmpty(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'named_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // All parameters should be suggested
        self::assertContains('name:', $labels);
        self::assertContains('count:', $labels);
        self::assertContains('active:', $labels);
    }

    public function testAttributeNamedArgumentCompletion(): void
    {
        $this->openFixture('src/Attributes/Route.php');
        $cursor = $this->openFixtureAtCursor('src/Completion/AttributeNamedArguments.php', 'attr_arg_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('path:', $labels, 'Attribute constructor named arguments are offered');
        self::assertContains('method:', $labels, 'Attribute constructor named arguments are offered');
    }

    public function testAttributeNamedArgumentCompletionAfterPositional(): void
    {
        $this->openFixture('src/Attributes/Route.php');
        $cursor = $this->openFixtureAtCursor('src/Completion/AttributeNamedArguments.php', 'attr_arg_second');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertNotContains('path:', $labels, 'The first parameter is already filled positionally');
        self::assertContains('method:', $labels, 'Remaining named arguments are still offered');
    }

    public function testNamedArgumentCompletionAfterPositional(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'after_positional');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // First param is used positionally, remaining should be suggested
        self::assertNotContains('name:', $labels);
        self::assertContains('count:', $labels);
        self::assertContains('active:', $labels);
    }

    public function testNamedArgumentCompletionAfterNamed(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'after_named');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // 'name' and 'count' are both used (count appears after cursor)
        self::assertNotContains('name:', $labels);
        self::assertNotContains('count:', $labels);
        self::assertContains('active:', $labels);
    }

    public function testNamedArgumentCompletionMiddle(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'middle_named');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // 'name' and 'active' are used, only 'count' remains
        self::assertNotContains('name:', $labels);
        self::assertContains('count:', $labels);
        self::assertNotContains('active:', $labels);
    }

    public function testNamedArgumentCompletionForStaticCall(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'static_named_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertNotEmpty($result['items'], 'Got: ' . json_encode($labels));
        self::assertContains('value:', $labels, 'Got: ' . json_encode($labels));
        self::assertContains('limit:', $labels);
    }

    public function testNamedArgumentCompletionForConstructor(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'new_named_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('name:', $labels);
        self::assertContains('age:', $labels);
    }

    public function testNamedArgumentCompletionShowsTypeInDetail(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'named_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);

        $nameItems = array_filter($result['items'], fn($item) => $item['label'] === 'name:');
        self::assertNotEmpty($nameItems);
        $nameItem = reset($nameItems);
        self::assertStringContainsString('string', $nameItem['detail'] ?? '');
    }

    public function testNamedArgumentCompletionInProceduralContext(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'procedural_empty');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('message:', $labels);
        self::assertContains('level:', $labels);
        self::assertContains('verbose:', $labels);
    }

    public function testNamedArgumentCompletionInProceduralContextFiltersUsed(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'procedural_after_named');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // 'message' and 'level' are used
        self::assertNotContains('message:', $labels);
        self::assertNotContains('level:', $labels);
        // Only 'verbose' remains
        self::assertContains('verbose:', $labels);
    }

    public function testNamedArgumentCompletionWithIncompletePrefix(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'incomplete_with_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // multipleParams(string $name, int $count, bool $active)
        // Prefix 'n' filters to only name: (starts with n)
        self::assertContains('name:', $labels, 'Got: ' . json_encode($labels));
        // count: and active: don't start with 'n' so they're filtered out
        self::assertNotContains('count:', $labels);
        self::assertNotContains('active:', $labels);
    }

    public function testNamedArgumentCompletionExcludesPositionallyFilledWhenMixed(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'mixed_positional_named');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // name: was filled positionally - should NOT be offered
        self::assertNotContains('name:', $labels, 'Bug: name: was filled positionally but is being offered');
        // count: is already used by name - should NOT be offered
        self::assertNotContains('count:', $labels);
        // active: is the only remaining unfilled parameter
        self::assertContains('active:', $labels);
    }

    public function testNamedArgumentCompletionIsAdditive(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'additive_with_variable');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // Named argument completions should be present
        self::assertContains('name:', $labels, 'Named args should be offered');
        // Variable completions should ALSO be present (additive behavior)
        self::assertContains('$localVar', $labels, 'Variable completions should also be offered');
    }

    public function testNamedArgumentCompletionExcludesVariadicParameters(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'variadic_excluded');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // withVariadic(string $name, string ...$values)
        // Regular param should be offered
        self::assertContains('name:', $labels, 'Regular param should be offered');
        // Variadic param should NOT be offered (PHP doesn't support named variadic args)
        self::assertNotContains('values:', $labels, 'Variadic params cannot be used as named arguments');
    }

    public function testNamedArgumentCompletionForNullsafeMethodCall(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'nullsafe_method_call');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // multipleParams(string $name, int $count, bool $active)
        // First param filled positionally, remaining should be offered
        self::assertNotContains('name:', $labels, 'First param filled positionally');
        self::assertContains('count:', $labels, 'Nullsafe method call should offer named args');
        self::assertContains('active:', $labels, 'Nullsafe method call should offer named args');
    }

    public function testNamedArgumentCompletionWhileEditingInCompleteCall(): void
    {
        // Open the file containing ParamClass so ClassRepository can find it
        $this->openFixture('src/Completion/NamedArguments.php');

        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'editing_in_complete');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // ParamClass(string $name, int $age = 0)
        // First param filled positionally, age is already used
        self::assertNotContains('name:', $labels, 'First param filled positionally');
        self::assertNotContains('age:', $labels, 'age is already used as named arg');
    }

    public function testNamedArgumentCompletionWhileEditingBeforeColon(): void
    {
        // Open the file containing ParamClass so ClassRepository can find it
        $this->openFixture('src/Completion/NamedArguments.php');

        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'editing_before_colon');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // For invalid syntax `new ParamClass('test', : 5)`, the call context should still be detected
        // and named args should be offered
        self::assertTrue(
            in_array('name:', $labels, true) || in_array('age:', $labels, true),
            'Should offer some named args when editing before colon',
        );
    }

    public function testVariableCompletionInsideCallContext(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'variable_in_call');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // Should offer both named args and variables starting with $va
        self::assertContains('$variable', $labels, 'Should offer variable completions inside call');
        self::assertContains('name:', $labels, 'Should also offer named args inside call');
    }

    public function testNamedArgAfterCompleteValue(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'after_named_value');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // After a complete named arg value without comma, should still offer remaining params
        self::assertContains('count:', $labels, 'Should offer remaining named args after complete value');
    }

    public function testNamedArgAfterStringValue(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'after_string_value');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // After a string value, should still detect call context and offer named args
        self::assertContains('count:', $labels, 'Should offer named args after string value');
    }

    public function testNamedArgAfterDoubleQuotedString(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'after_double_string');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // After a double-quoted string, should still detect call context
        self::assertContains('count:', $labels, 'Should offer named args after double-quoted string');
    }

    public function testNoStatementKeywordsAfterNamedArgColon(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'after_colon');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // Statement keywords should NOT be offered after `name: `
        self::assertNotContains('if', $labels, 'Statement keywords should not appear after named arg colon');
        self::assertNotContains('class', $labels, 'Declaration keywords should not appear after named arg colon');
        self::assertNotContains('function', $labels, 'Declaration keywords should not appear after named arg colon');
        self::assertNotContains('while', $labels, 'Statement keywords should not appear after named arg colon');
        // Expression keywords SHOULD be offered
        self::assertContains('new', $labels, 'Expression keywords should appear after named arg colon');
        self::assertContains('true', $labels, 'Literal keywords should appear after named arg colon');
        self::assertContains('false', $labels, 'Literal keywords should appear after named arg colon');
        self::assertContains('null', $labels, 'Literal keywords should appear after named arg colon');
    }

    public function testExpressionKeywordsFilteredByPrefixAfterColon(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'after_colon_prefix');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        // 'n' prefix should match 'new' and 'null' but not 'true', 'false', 'match'
        self::assertContains('new', $labels, 'new should match prefix n');
        self::assertContains('null', $labels, 'null should match prefix n');
        self::assertNotContains('true', $labels, 'true should not match prefix n');
        self::assertNotContains('if', $labels, 'Statement keywords should not appear');
        // name: is already used, should not be offered
        self::assertNotContains('name:', $labels, 'Already-used named arg should not be offered');
    }

    // =========================================================================
    // Incomplete code in control structures - text-based fallback
    // =========================================================================

    public function testCompletionThisInIfCondition(): void
    {
        // File with proper closing braces - parser recovers
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/SingleIncomplete.php', 'this_in_if');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');

        self::assertContains('getName', $labels, 'Methods should be offered');
        self::assertContains('name', $labels, 'Properties should be offered');
    }

    public function testCompletionThisInVeryBrokenFile(): void
    {
        // File with NO closing braces - parser fails completely
        // This tests the pure text-based fallback for both class detection AND member extraction
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/VeryBroken.php', 'this_in_if');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        // Verify parser fails
        $ast = $this->parser->parse($document);
        self::assertEmpty($ast, 'Parser should fail completely for very broken file');

        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');

        // Should still offer class members via pure text-based fallback
        self::assertContains('getName', $labels, 'Methods should be offered even when parser fails');
        self::assertContains('name', $labels, 'Properties should be offered even when parser fails');
    }

    public function testCompletionChainedAccessInIfCondition(): void
    {
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/ChainedAccess.php', 'chained_in_if');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result, 'Chained access in if() should return completions');
        self::assertArrayHasKey('items', $result);
        self::assertNotEmpty($result['items'], 'Should offer User methods');
    }

    public function testAttributeContextOffersOnlyAttributes(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/AttributeCompletion.php', 'attr_empty');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Route', $labels, 'Imported attribute classes are offered in a #[ position');
        self::assertContains('NoConstructorAttribute', $labels, 'Attribute classes without a constructor are offered');
        self::assertNotContains('User', $labels, 'A plain class is not an attribute');
        self::assertNotContains('Entity', $labels, 'An interface is not an attribute');
        self::assertNotContains('SingletonTrait', $labels, 'A trait is not an attribute');
        self::assertNotContains('Status', $labels, 'An enum is not an attribute');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into a #[ position (issue #252)',
        );
        self::assertNotContains(
            CompletionItemKind::Keyword->value,
            $kinds,
            'Keywords must not leak into a #[ position',
        );
    }

    public function testAttributeWithPrefixOffersAttributeNotFunctions(): void
    {
        // The #[ analog of the #298 report: `#[date` must not leak `date_*` functions.
        $cursor = $this->openFixtureAtCursor('src/Completion/AttributePrefixCompletion.php', 'attr_prefix');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Route', $labels, 'An in-scope attribute matching the prefix is offered');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not be offered in a #[ position (issue #252)',
        );
    }

    public function testAttributeGroupedContinuationOffersAttributes(): void
    {
        // Grouped attributes: `#[A, B` — the position after the comma is still an
        // attribute-name position.
        $cursor = $this->openFixtureAtCursor('src/Completion/AttributeGroupedCompletion.php', 'attr_grouped');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Route', $labels, 'Attributes are offered after a comma in a grouped attribute');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into a grouped attribute (issue #252)',
        );
    }

    /**
     * Interface-list positions (`implements` and `interface … extends`) share one
     * behavior: interfaces from imports/index are offered, everything else is not.
     * The cases are data-driven so each new interface-list position (#312–#316)
     * plugs in as a row rather than a copy-pasted method.
     *
     * @param list<string> $expectedPresent interface labels that must be offered
     * @param list<string> $expectedAbsentLabels non-interface labels that must not be offered
     * @param list<CompletionItemKind> $expectedAbsentKinds item kinds that must not leak in
     */
    #[DataProvider('provideInterfaceListContexts')]
    public function testInterfaceListContextOffersOnlyInterfaces(
        string $fixture,
        string $marker,
        array $expectedPresent,
        array $expectedAbsentLabels,
        array $expectedAbsentKinds,
    ): void {
        $cursor = $this->openFixtureAtCursor($fixture, $marker);
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        foreach ($expectedPresent as $label) {
            self::assertContains($label, $labels, "Interface {$label} must be offered in an interface list");
        }
        foreach ($expectedAbsentLabels as $label) {
            self::assertNotContains($label, $labels, "{$label} is not an interface and must not be offered");
        }

        $kinds = array_column($result['items'], 'kind');
        foreach ($expectedAbsentKinds as $kind) {
            self::assertNotContains(
                $kind->value,
                $kinds,
                "{$kind->name} items must not leak into an interface list",
            );
        }
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{
     *   string,
     *   string,
     *   list<string>,
     *   list<string>,
     *   list<CompletionItemKind>,
     * }>
     */
    public static function provideInterfaceListContexts(): iterable
    {
        // `implements` list (issue #298).
        yield 'implements empty' => [
            'src/Completion/ImplementsCompletion.php',
            'implements_empty',
            ['Entity'],
            ['User', 'SingletonTrait', 'Status'],
            [CompletionItemKind::Function, CompletionItemKind::Keyword],
        ];
        // The original #298 report: `implements D` offered `date_*` functions
        // instead of the in-scope interface starting with `D`.
        yield 'implements D prefix' => [
            'src/Completion/ImplementsPrefixCompletion.php',
            'implements_d_prefix',
            ['Describable'],
            ['date_add'],
            [CompletionItemKind::Function],
        ];
        // Exercises the reflection resolution path: an imported built-in interface
        // is offered while the built-in class of the same prefix is excluded.
        yield 'implements builtin' => [
            'src/Completion/ImplementsBuiltinCompletion.php',
            'implements_builtin',
            ['SessionHandlerInterface'],
            ['SessionHandler', 'session_start'],
            [CompletionItemKind::Function],
        ];

        // `interface … extends` list (issue #312).
        yield 'interface extends empty' => [
            'src/Completion/InterfaceExtendsCompletion.php',
            'interface_extends_empty',
            ['Entity'],
            ['User', 'SingletonTrait', 'Status'],
            [CompletionItemKind::Function, CompletionItemKind::Keyword],
        ];
        yield 'interface extends D prefix' => [
            'src/Completion/InterfaceExtendsPrefixCompletion.php',
            'interface_extends_d_prefix',
            ['Describable'],
            ['date_add'],
            [CompletionItemKind::Function],
        ];
        // The comma-list form: an interface may extend several interfaces.
        yield 'interface extends list continuation' => [
            'src/Completion/InterfaceExtendsListCompletion.php',
            'interface_extends_list',
            ['Describable'],
            [],
            [CompletionItemKind::Function],
        ];
    }

    public function testInterfaceExtendsContextOffersOnlyInterfaces(): void
    {
        $cursor = $this->openFixtureAtCursor(
            'src/Completion/InterfaceExtendsCompletion.php',
            'interface_extends_empty',
        );
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Entity', $labels, 'Imported interfaces are valid in an interface extends list');
        self::assertNotContains('User', $labels, 'An interface cannot extend a class');
        self::assertNotContains('SingletonTrait', $labels, 'An interface cannot extend a trait');
        self::assertNotContains('Status', $labels, 'An interface cannot extend an enum');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into an interface extends list (issue #312)',
        );
        self::assertNotContains(
            CompletionItemKind::Keyword->value,
            $kinds,
            'Keywords must not leak into an interface extends list',
        );
    }

    public function testInterfaceExtendsWithPrefixOffersInterfaceNotFunctions(): void
    {
        // Mirrors the #298 report for the extends position: `extends D` must offer the
        // in-scope interface starting with `D`, not built-in `date_*` functions.
        $cursor = $this->openFixtureAtCursor(
            'src/Completion/InterfaceExtendsPrefixCompletion.php',
            'interface_extends_d_prefix',
        );
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Describable', $labels, 'An in-scope interface starting with D should be offered');
        self::assertNotContains('date_add', $labels, 'Built-in functions must not be offered in an extends list');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'No function should be offered in an interface extends list (issue #312)',
        );
    }

    public function testInterfaceExtendsListContinuationOffersInterfaces(): void
    {
        // The comma-list form: an interface may extend several interfaces.
        $cursor = $this->openFixtureAtCursor(
            'src/Completion/InterfaceExtendsListCompletion.php',
            'interface_extends_list',
        );
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains(
            'Describable',
            $labels,
            'Interfaces are valid after a comma in an interface extends list',
        );

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into a comma-separated interface extends list (issue #312)',
        );
    }

    public function testClassExtendsContextOffersOnlyExtendableClasses(): void
    {
        $cursor = $this->openFixtureAtCursor(
            'src/Completion/ClassExtendsCompletion.php',
            'class_extends_empty',
        );
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('ParentClass', $labels, 'A non-final class can be extended');
        self::assertNotContains('FinalDescendant', $labels, 'A final class cannot be extended');
        self::assertNotContains('Entity', $labels, 'A class cannot extend an interface');
        self::assertNotContains('SingletonTrait', $labels, 'A class cannot extend a trait');
        self::assertNotContains('Status', $labels, 'A class cannot extend an enum');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into a class extends clause (issue #313)',
        );
        self::assertNotContains(
            CompletionItemKind::Keyword->value,
            $kinds,
            'Keywords must not leak into a class extends clause (issue #313)',
        );
    }

    public function testClassExtendsWithPrefixOffersClassNotFunctions(): void
    {
        // Mirrors the #298 report for the extends position: `extends P` must offer the
        // in-scope class starting with `P`, not built-in `printf`/`print_r`.
        $cursor = $this->openFixtureAtCursor(
            'src/Completion/ClassExtendsPrefixCompletion.php',
            'class_extends_p_prefix',
        );
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('ParentClass', $labels, 'An in-scope class starting with P should be offered');
        self::assertNotContains('printf', $labels, 'Built-in functions must not be offered in an extends clause');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'No function should be offered in a class extends clause (issue #313)',
        );
    }

    public function testCatchContextOffersOnlyThrowables(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/CatchCompletion.php', 'catch_empty');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('AppException', $labels, 'A Throwable subclass can be caught');
        self::assertContains('ExceptionInterface', $labels, 'An interface extending Throwable can be caught');
        self::assertNotContains('User', $labels, 'A non-Throwable class cannot be caught');
        self::assertNotContains('Entity', $labels, 'A non-Throwable interface cannot be caught');
        self::assertNotContains('SingletonTrait', $labels, 'A trait cannot be caught');
        self::assertNotContains('Status', $labels, 'An enum cannot be caught');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into a catch clause (issue #314)',
        );
        self::assertNotContains(
            CompletionItemKind::Keyword->value,
            $kinds,
            'Keywords must not leak into a catch clause (issue #314)',
        );
    }

    public function testMultiCatchContinuationOffersThrowables(): void
    {
        // The `|`-separated multi-catch form must keep offering Throwables (issue #314).
        $cursor = $this->openFixtureAtCursor('src/Completion/MultiCatchCompletion.php', 'catch_multi');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('ExceptionInterface', $labels, 'A Throwable is offered after the multi-catch pipe');
        self::assertNotContains('User', $labels, 'A non-Throwable class cannot be caught');
    }

    public function testCatchWithPrefixOffersThrowableNotFunctions(): void
    {
        // Mirrors the #298 report for the catch position: `catch (A` must offer the
        // in-scope Throwable starting with `A`, not built-in functions.
        $cursor = $this->openFixtureAtCursor('src/Completion/CatchPrefixCompletion.php', 'catch_a_prefix');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('AppException', $labels, 'An in-scope Throwable starting with A should be offered');
        self::assertNotContains('array_map', $labels, 'Built-in functions must not be offered in a catch clause');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'No function should be offered in a catch clause (issue #314)',
        );
    }

    public function testImplementsAcrossMultipleLinesOffersInterfaces(): void
    {
        self::markTestSkipped(
            'Wrapped/multi-line implements is not yet handled: the classifier is single-line, '
            . 'so continuation lines fall through to Expression completion. See issue #310.',
        );

        // @phpstan-ignore-next-line deadCode.unreachable (documents the target behavior; unskip with #310)
        $cursor = $this->openFixtureAtCursor('src/Completion/WrappedImplementsCompletion.php', 'wrapped_implements');
        $result = $this->handler->handle($this->completionRequestAt($cursor));

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Entity', $labels, 'Interfaces are valid in a wrapped implements list');

        $kinds = array_column($result['items'], 'kind');
        self::assertNotContains(
            CompletionItemKind::Function->value,
            $kinds,
            'Functions must not leak into a wrapped implements list (issue #310)',
        );
    }
}
