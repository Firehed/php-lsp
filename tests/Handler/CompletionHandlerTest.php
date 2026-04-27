<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

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
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionHandler::class)]
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
        $locator = self::createStub(ClassLocator::class);
        $this->classRepository = new DefaultClassRepository(
            $this->classInfoFactory,
            $locator,
            $this->parser,
        );
        $this->memberResolver = new MemberResolver($this->classRepository);
        $typeResolver = new BasicTypeResolver($this->memberResolver);
        $indexer = new DocumentIndexer($this->parser, new SymbolExtractor(), $this->symbolIndex);
        $this->handler = new CompletionHandler(
            $this->documents,
            $this->parser,
            $this->symbolIndex,
            $this->memberResolver,
            $this->classRepository,
            $typeResolver,
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
        $code = <<<'PHP'
<?php
class MyClass
{
    public function greet(): string
    {
        return "Hello";
    }

    public function farewell(): string
    {
        return "Goodbye";
    }

    public function test(): void
    {
        $this->
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 15, 'character' => 15], // After $this->
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertNotEmpty($result['items']);

        $labels = array_column($result['items'], 'label');
        self::assertContains('greet', $labels);
        self::assertContains('farewell', $labels);
        self::assertContains('test', $labels);
    }

    public function testThisMethodCompletionWithPrefix(): void
    {
        $code = <<<'PHP'
<?php
class MyClass
{
    public function greet(): string { return "Hello"; }
    public function goodbye(): string { return "Bye"; }
    public function test(): void
    {
        $this->gr
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 7, 'character' => 17], // After $this->gr
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('greet', $labels);
        self::assertNotContains('goodbye', $labels);
    }

    public function testThisPropertyCompletion(): void
    {
        $code = <<<'PHP'
<?php
class MyClass
{
    private string $name;
    protected int $age;

    public function test(): void
    {
        $this->
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 8, 'character' => 15],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('name', $labels);
        self::assertContains('age', $labels);
    }

    public function testThisCompletionIncludesInheritedMembers(): void
    {
        $code = <<<'PHP'
<?php
class MyException extends \Exception
{
    private string $ownProperty;

    public function test(): void
    {
        $this->
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 7, 'character' => 15],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Own members
        self::assertContains('ownProperty', $labels);
        self::assertContains('test', $labels);
        // Inherited members from Exception
        self::assertContains('getMessage', $labels);
        self::assertContains('getCode', $labels);
    }

    public function testStaticMethodCompletion(): void
    {
        $code = <<<'PHP'
<?php
class Math
{
    public static function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public static function multiply(int $a, int $b): int
    {
        return $a * $b;
    }
}

$result = Math::
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 14, 'character' => 16], // After Math::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('add', $labels);
        self::assertContains('multiply', $labels);
        // ::class magic constant should always be suggested
        self::assertContains('class', $labels);
    }

    public function testClassConstantCompletion(): void
    {
        $code = <<<'PHP'
<?php
class Status
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
}

$status = Status::
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 7, 'character' => 18],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('ACTIVE', $labels);
        self::assertContains('INACTIVE', $labels);
    }

    public function testStaticCompletionResolvesImportedClassName(): void
    {
        // Class defined in a namespace, then imported via use statement
        $code = <<<'PHP'
<?php
namespace App\Models;

class User
{
    public const STATUS_ACTIVE = 'active';
    public static function findById(int $id): self {}
}

namespace App\Controllers;

use App\Models\User;

class UserController
{
    public function show(): void
    {
        User::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 17, 'character' => 14], // After User::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should resolve User to App\Models\User and find its members
        self::assertContains('STATUS_ACTIVE', $labels);
        self::assertContains('findById', $labels);
        self::assertContains('class', $labels);
    }

    public function testStaticCompletionResolvesAliasedImport(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Models;

class UserModel
{
    public const ROLE_ADMIN = 'admin';
}

namespace App\Controllers;

use App\Models\UserModel as User;

class Controller
{
    public function test(): void
    {
        User::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 16, 'character' => 14], // After User::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should resolve User alias to App\Models\UserModel
        self::assertContains('ROLE_ADMIN', $labels);
    }

    public function testStaticCompletionShowsOnlyPublicForExternalClass(): void
    {
        $code = <<<'PHP'
<?php
class Target
{
    public static function publicMethod(): void {}
    protected static function protectedMethod(): void {}
    private static function privateMethod(): void {}
    public const PUBLIC_CONST = 'pub';
    protected const PROTECTED_CONST = 'prot';
    private const PRIVATE_CONST = 'priv';
}

class Other
{
    public function test(): void
    {
        Target::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 15, 'character' => 16], // Target::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('publicMethod', $labels);
        self::assertContains('PUBLIC_CONST', $labels);
        self::assertNotContains('protectedMethod', $labels);
        self::assertNotContains('privateMethod', $labels);
        self::assertNotContains('PROTECTED_CONST', $labels);
        self::assertNotContains('PRIVATE_CONST', $labels);
    }

    public function testStaticCompletionShowsAllForSameClass(): void
    {
        $code = <<<'PHP'
<?php
class MyClass
{
    public static function publicMethod(): void {}
    protected static function protectedMethod(): void {}
    private static function privateMethod(): void {}

    public function test(): void
    {
        self::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 9, 'character' => 14], // self::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('publicMethod', $labels);
        self::assertContains('protectedMethod', $labels);
        self::assertContains('privateMethod', $labels);
    }

    public function testStaticCompletionShowsPublicProtectedForSubclass(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass
{
    public static function publicMethod(): void {}
    protected static function protectedMethod(): void {}
    private static function privateMethod(): void {}
}

class ChildClass extends ParentClass
{
    public function test(): void
    {
        ParentClass::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 12, 'character' => 21], // ParentClass::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('publicMethod', $labels);
        self::assertContains('protectedMethod', $labels);
        self::assertNotContains('privateMethod', $labels);
    }

    public function testSelfCompletionIncludesInheritedStaticMembers(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass
{
    public static string $inheritedProperty = 'value';
    public const INHERITED_CONST = 'const';
    public static function inheritedMethod(): void {}
}

class ChildClass extends ParentClass
{
    public static string $ownProperty = 'child';
    public static function ownMethod(): void
    {
        self::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 13, 'character' => 14],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Own static members
        self::assertContains('ownProperty', $labels);
        self::assertContains('ownMethod', $labels);
        // Inherited static members from ParentClass
        self::assertContains('inheritedProperty', $labels);
        self::assertContains('inheritedMethod', $labels);
        self::assertContains('INHERITED_CONST', $labels);
    }

    public function testFunctionCompletion(): void
    {
        $code = <<<'PHP'
<?php
function myCustomFunction(): void {}

$x = arr
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 3, 'character' => 8], // After "arr"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include built-in functions starting with "arr"
        self::assertContains('array_map', $labels);
        self::assertContains('array_filter', $labels);
    }

    public function testExpressionCompletionIncludesImportedClasses(): void
    {
        $code = <<<'PHP'
<?php
use App\Models\User;
use App\Models\UserRepository as Repo;

function foo() {
    $x = Us
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 5, 'character' => 10], // After "Us"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include imported class
        self::assertContains('User', $labels);

        // Check that FQCN is in detail
        $userItems = array_filter($result['items'], fn($item) => $item['label'] === 'User');
        self::assertNotEmpty($userItems);
        $userItem = reset($userItems);
        self::assertIsArray($userItem);
        self::assertSame('App\Models\User', $userItem['detail'] ?? null);
    }

    public function testExpressionCompletionIncludesAliasedImports(): void
    {
        $code = <<<'PHP'
<?php
use App\Models\UserRepository as Repo;

function foo() {
    $x = Rep
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 4, 'character' => 11], // After "Rep"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include aliased import
        self::assertContains('Repo', $labels);

        // Check that FQCN is in detail
        $repoItems = array_filter($result['items'], fn($item) => $item['label'] === 'Repo');
        self::assertNotEmpty($repoItems);
        $repoItem = reset($repoItems);
        self::assertIsArray($repoItem);
        self::assertSame('App\Models\UserRepository', $repoItem['detail'] ?? null);
    }

    public function testExpressionCompletionIncludesGroupedImports(): void
    {
        $code = <<<'PHP'
<?php
use App\Models\{User, Post};

function foo() {
    $x = Us
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 4, 'character' => 10], // After "Us"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Should include both grouped imports
        self::assertContains('User', $labels);

        // Check that FQCN is correct for grouped import
        $userItems = array_filter($result['items'], fn($item) => $item['label'] === 'User');
        self::assertNotEmpty($userItems);
        $userItem = reset($userItems);
        self::assertIsArray($userItem);
        self::assertSame('App\Models\User', $userItem['detail'] ?? null);
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
        $code = '<?php function foo(string $name, int $age) { $n; }';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 47], // After $n
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('$name', $labels);
        self::assertNotContains('$age', $labels); // doesn't match prefix
    }

    public function testVariableCompletionSuggestsLocalVariables(): void
    {
        $code = '<?php function foo() { $logger = new Logger(); $l; }';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 49], // After $l
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('$logger', $labels);
    }

    public function testVariableCompletionSuggestsThisInMethod(): void
    {
        $code = '<?php class Foo { public function bar() { $t; } }';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 44], // After $t
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('$this', $labels);
    }

    public function testVariableCompletionThisShowsClassName(): void
    {
        $code = '<?php class MyClass { public function bar() { $t; } }';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 48], // After $t
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $thisItems = array_filter($result['items'], fn($item) => $item['label'] === '$this');
        self::assertNotEmpty($thisItems);
        $thisItem = reset($thisItems);
        self::assertSame('MyClass', $thisItem['detail'] ?? null);
    }

    public function testVariableCompletionThisShowsNamespacedClassName(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Models;

class User
{
    public function getName(): void
    {
        $t
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 7, 'character' => 10], // After $t
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $thisItems = array_filter($result['items'], fn($item) => $item['label'] === '$this');
        self::assertNotEmpty($thisItems);
        $thisItem = reset($thisItems);
        self::assertSame('App\Models\User', $thisItem['detail'] ?? null);
    }

    public function testVariableCompletionWorksInClosures(): void
    {
        $code = '<?php $fn = function ($param) { $localVar = 1; $l; };';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 49], // After $l
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('$localVar', $labels);
    }

    public function testVariableCompletionSuggestsForeachVariables(): void
    {
        $code = '<?php function foo() { foreach ([1,2] as $item) { $i; } }';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 52], // After $i
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('$item', $labels);
    }

    public function testVariableCompletionIsolatesScopes(): void
    {
        // Two closures - variables from one should not leak to the other
        $code = <<<'PHP'
<?php
$a = [
    'x' => function () { $logger = 1; return $logger; },
    'y' => function () { $siteDir = 2; $s; },
];
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 3, 'character' => 41], // After $s in second closure
            ],
        ]);

        $result = $this->handler->handle($request);

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
        $code = <<<'PHP'
<?php
enum Status
{
    case Active;
    case Inactive;
    case Pending;
}

$status = Status::A
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 8, 'character' => 19], // After Status::A
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Active', $labels);
        self::assertNotContains('Inactive', $labels); // doesn't match prefix
        self::assertNotContains('Pending', $labels); // doesn't match prefix
    }

    public function testEnumCaseCompletionNoPrefix(): void
    {
        $code = <<<'PHP'
<?php
enum Status
{
    case Active;
    case Inactive;
}

$status = Status::
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 7, 'character' => 18], // After Status::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('Active', $labels);
        self::assertContains('Inactive', $labels);
        self::assertContains('class', $labels);

        // Check unit enum case detail
        $activeItems = array_filter($result['items'], fn($item) => $item['label'] === 'Active');
        self::assertNotEmpty($activeItems);
        $activeItem = reset($activeItems);
        self::assertSame('case Active', $activeItem['detail'] ?? '');
    }

    public function testEnumBuiltinMethodCompletion(): void
    {
        $code = <<<'PHP'
<?php
enum Status
{
    case Active;
    case Inactive;
}

$cases = Status::c
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 7, 'character' => 18], // After Status::c
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('cases', $labels);
        self::assertContains('class', $labels);
    }

    public function testBackedEnumCompletionInt(): void
    {
        $code = <<<'PHP'
<?php
enum Priority: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
}

$p = Priority::
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 8, 'character' => 15], // After Priority::
            ],
        ]);

        $result = $this->handler->handle($request);

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
        $code = <<<'PHP'
<?php
enum Color: string
{
    case Red = 'red';
    case Green = 'green';
    case Blue = 'blue';
}

$c = Color::
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 8, 'character' => 12], // After Color::
            ],
        ]);

        $result = $this->handler->handle($request);

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
        $code = <<<'PHP'
<?php
enum Priority: int
{
    case Low = 1;
    case High = 2;
}

$p = Priority::f
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 7, 'character' => 16], // After Priority::f
            ],
        ]);

        $result = $this->handler->handle($request);

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
        $code = <<<'PHP'
<?php
class User
{
    public string $name;
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
}

function processUser(User $user): void
{
    $user->
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $handler = new CompletionHandler(
            $this->documents,
            $this->parser,
            $this->symbolIndex,
            $this->memberResolver,
            $this->classRepository,
            new BasicTypeResolver($this->memberResolver),
        );

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 10, 'character' => 11], // After $user->
            ],
        ]);

        $result = $handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('name', $labels);
        self::assertContains('getName', $labels);
        self::assertContains('setName', $labels);
    }

    public function testTypedVariableCompletionFromNewExpression(): void
    {
        $code = <<<'PHP'
<?php
class Logger
{
    public function info(string $message): void {}
    public function error(string $message): void {}
}

function foo(): void
{
    $logger = new Logger();
    $logger->
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $handler = new CompletionHandler(
            $this->documents,
            $this->parser,
            $this->symbolIndex,
            $this->memberResolver,
            $this->classRepository,
            new BasicTypeResolver($this->memberResolver),
        );

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 10, 'character' => 13], // After $logger->
            ],
        ]);

        $result = $handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('info', $labels);
        self::assertContains('error', $labels);
    }

    public function testTypedVariableCompletionWithPrefix(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    public function getName(): string { return ''; }
    public function getEmail(): string { return ''; }
    public function setName(string $name): void {}
}

function processUser(User $user): void
{
    $user->get
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $handler = new CompletionHandler(
            $this->documents,
            $this->parser,
            $this->symbolIndex,
            $this->memberResolver,
            $this->classRepository,
            new BasicTypeResolver($this->memberResolver),
        );

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 10, 'character' => 14], // After $user->get
            ],
        ]);

        $result = $handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('getName', $labels);
        self::assertContains('getEmail', $labels);
        self::assertNotContains('setName', $labels);
    }

    public function testTypedVariableCompletionReturnsEmptyWhenTypeUnknown(): void
    {
        $code = <<<'PHP'
<?php
function foo(): void
{
    $unknown->
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $handler = new CompletionHandler(
            $this->documents,
            $this->parser,
            $this->symbolIndex,
            $this->memberResolver,
            $this->classRepository,
            new BasicTypeResolver($this->memberResolver),
        );

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 3, 'character' => 14], // After $unknown->
            ],
        ]);

        $result = $handler->handle($request);

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }

    public function testTypedVariableCompletionIncludesInheritedMembers(): void
    {
        $code = <<<'PHP'
<?php
function foo(ArrayObject $obj): void
{
    $obj->
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $handler = new CompletionHandler(
            $this->documents,
            $this->parser,
            $this->symbolIndex,
            $this->memberResolver,
            $this->classRepository,
            new BasicTypeResolver($this->memberResolver),
        );

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 3, 'character' => 10], // After $obj->
            ],
        ]);

        $result = $handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // ArrayObject methods from reflection
        self::assertContains('append', $labels);
        self::assertContains('count', $labels);
        self::assertContains('getIterator', $labels);
    }

    public function testTypedVariableCompletionExcludesNonPublicMembers(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    public string $name;
    protected string $email;
    private string $password;

    public function getName(): string { return $this->name; }
    protected function getEmail(): string { return $this->email; }
    private function getPassword(): string { return $this->password; }
}

function foo(User $user): void
{
    $user->
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $handler = new CompletionHandler(
            $this->documents,
            $this->parser,
            $this->symbolIndex,
            $this->memberResolver,
            $this->classRepository,
            new BasicTypeResolver($this->memberResolver),
        );

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 14, 'character' => 11], // After $user->
            ],
        ]);

        $result = $handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        // Public members should be included
        self::assertContains('name', $labels);
        self::assertContains('getName', $labels);
        // Protected and private members should be excluded
        self::assertNotContains('email', $labels);
        self::assertNotContains('password', $labels);
        self::assertNotContains('getEmail', $labels);
        self::assertNotContains('getPassword', $labels);
    }

    public function testSelfConstantCompletion(): void
    {
        $code = <<<'PHP'
<?php
class Foo
{
    public const FOO = 'foo';
    public const BAR = 'bar';

    public function thing(): string
    {
        return self::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 8, 'character' => 21], // After self::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('FOO', $labels);
        self::assertContains('BAR', $labels);
        self::assertContains('class', $labels);
    }

    public function testSelfConstantCompletionNamespaced(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Models;

class Foo
{
    public const FOO = 'foo';
    public const BAR = 'bar';

    public function thing(): string
    {
        return self::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 10, 'character' => 21], // After self::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('FOO', $labels);
        self::assertContains('BAR', $labels);
        self::assertContains('class', $labels);
    }

    public function testStaticConstantCompletion(): void
    {
        $code = <<<'PHP'
<?php
class Foo
{
    public const FOO = 'foo';
    public const BAR = 'bar';

    public function thing(): string
    {
        return static::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 8, 'character' => 23], // After static::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('FOO', $labels);
        self::assertContains('BAR', $labels);
        self::assertContains('class', $labels);
    }

    public function testSelfConstantCompletionWithPrefix(): void
    {
        $code = <<<'PHP'
<?php
class Foo
{
    public const FOO = 'foo';
    public const BAR = 'bar';

    public function thing(): string
    {
        return self::FO
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 8, 'character' => 23], // After self::FO
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('FOO', $labels);
        self::assertNotContains('BAR', $labels);
        self::assertNotContains('class', $labels);
    }

    public function testSelfCompletionInAnonymousClassReturnsEmpty(): void
    {
        $code = <<<'PHP'
<?php
$obj = new class {
    public const FOO = 'foo';

    public function thing(): string
    {
        return self::
    }
};
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 6, 'character' => 21], // After self::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertEmpty($result['items']);
    }

    public function testSelfCompletionInMultiClassFile(): void
    {
        $code = <<<'PHP'
<?php
class First
{
    public const FIRST_CONST = 1;
}

class Second
{
    public const SECOND_CONST = 2;

    public function thing(): int
    {
        return self::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 12, 'character' => 21], // After self::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('SECOND_CONST', $labels);
        self::assertNotContains('FIRST_CONST', $labels);
    }

    public function testSelfStaticMethodCompletion(): void
    {
        $code = <<<'PHP'
<?php
class Foo
{
    public static function staticMethod(): void {}
    public function instanceMethod(): void {}

    public function thing(): void
    {
        self::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 8, 'character' => 14], // After self::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('staticMethod', $labels);
        self::assertNotContains('instanceMethod', $labels);
    }

    public function testSelfStaticPropertyCompletion(): void
    {
        $code = <<<'PHP'
<?php
class Foo
{
    public static string $staticProp = 'static';
    public string $instanceProp = 'instance';

    public function thing(): void
    {
        self::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 8, 'character' => 14], // After self::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('staticProp', $labels);
        self::assertNotContains('instanceProp', $labels);
    }

    public function testSelfCompletionOutsideClassReturnsEmpty(): void
    {
        $code = <<<'PHP'
<?php
function foo(): void
{
    self::
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 3, 'character' => 10], // After self::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertEmpty($result['items']);
    }

    public function testParentMethodCompletion(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass
{
    public function __construct(string $name) {}
    protected function greet(): string { return 'Hello'; }
}

class ChildClass extends ParentClass
{
    public function __construct(string $name)
    {
        parent::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 11, 'character' => 16], // After parent::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('__construct', $labels);
        self::assertContains('greet', $labels);
    }

    public function testParentMethodCompletionReturnsEmptyWhenNoParent(): void
    {
        $code = <<<'PHP'
<?php
class MyClass
{
    public function test(): void
    {
        parent::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 5, 'character' => 16], // After parent::
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertEmpty($result['items']);
    }

    public function testParentMethodCompletionWithPrefix(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass
{
    public function __construct() {}
    protected function greet(): string { return 'Hello'; }
    protected function goodbye(): string { return 'Bye'; }
}

class ChildClass extends ParentClass
{
    public function test(): void
    {
        parent::gr
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 12, 'character' => 18], // After parent::gr
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('greet', $labels);
        self::assertNotContains('goodbye', $labels);
        self::assertNotContains('__construct', $labels);
    }

    public function testTypedVariableCompletionResolvesParameterType(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    public function getName(): string { return ''; }
}

function foo(User $user): void
{
    $user->
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 8, 'character' => 11], // After $user->
            ],
        ]);

        $result = $this->handler->handle($request);

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
        $code = <<<'PHP'
<?php
$this->
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 1, 'character' => 7],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }

    public function testThisCompletionInAnonymousClassReturnsEmpty(): void
    {
        $code = <<<'PHP'
<?php
$x = new class {
    public function foo(): void {
        $this->
    }
};
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 3, 'character' => 15],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }

    public function testStaticCompletionFromAnonymousClassContext(): void
    {
        $code = <<<'PHP'
<?php
class Target
{
    public static function publicMethod(): void {}
    protected static function protectedMethod(): void {}
}

$x = new class {
    public function foo(): void {
        Target::
    }
};
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 9, 'character' => 16],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('publicMethod', $labels);
        self::assertNotContains('protectedMethod', $labels);
    }

    public function testStaticCompletionWithDeeperInheritance(): void
    {
        // InheritanceChild extends InheritanceParent extends InheritanceGrandparent
        // Test that Child can access protected members of Grandparent via reflection
        $code = <<<'PHP'
<?php
namespace Firehed\PhpLsp\Tests\Repository;

use Firehed\PhpLsp\Tests\Repository\InheritanceGrandparent;

class InheritanceChild extends InheritanceParent
{
    public function foo(): void
    {
        InheritanceGrandparent::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 9, 'character' => 32],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('grandparentPublic', $labels);
        self::assertContains('grandparentProtected', $labels);
    }

    public function testStaticCompletionInClassWithoutNamespace(): void
    {
        $code = <<<'PHP'
<?php
class NoNamespace
{
    public static function test(): void {
        self::
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 4, 'character' => 14],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('test', $labels);
    }

    public function testUserDefinedFunctionCompletion(): void
    {
        $code = <<<'PHP'
<?php
/**
 * Adds two numbers.
 */
function calculateSum(int $a, int $b): int
{
    return $a + $b;
}

$result = calc
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 9, 'character' => 14],
            ],
        ]);

        $result = $this->handler->handle($request);

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
        $code = <<<'PHP'
<?php
class ParentClass
{
    protected string $inheritedProperty;
    public function inheritedMethod(): void {}
}

class ChildClass extends ParentClass
{
    private string $ownProperty;

    public function ownMethod(): void {}

    public function test(): void
    {
        $this->
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 15, 'character' => 15],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');

        // Should have ChildClass's own members
        self::assertContains('ownProperty', $labels);
        self::assertContains('ownMethod', $labels);
        self::assertContains('test', $labels);

        // Should also have inherited members
        self::assertContains('inheritedProperty', $labels);
        self::assertContains('inheritedMethod', $labels);
    }

    public function testThisCompletionWithUnrelatedClassesInFile(): void
    {
        // Two unrelated classes in the same file - cursor in second class
        // should get its members, not the first class's
        $code = <<<'PHP'
<?php
class FirstClass
{
    public string $firstProperty;
    public function firstMethod(): void {}
}

class SecondClass
{
    public string $secondProperty;

    public function secondMethod(): void {}

    public function test(): void
    {
        $this->
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 15, 'character' => 15],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        $labels = array_column($result['items'], 'label');

        // Should have SecondClass's members
        self::assertContains('secondProperty', $labels);
        self::assertContains('secondMethod', $labels);
        self::assertContains('test', $labels);

        // Should NOT have FirstClass's members
        self::assertNotContains('firstProperty', $labels);
        self::assertNotContains('firstMethod', $labels);
    }
}
