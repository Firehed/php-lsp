<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionHandler::class)]
class CompletionHandlerTest extends TestCase
{
    private DocumentManager $documents;
    private ParserService $parser;
    private SymbolIndex $symbolIndex;
    private CompletionHandler $handler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $this->parser = new ParserService();
        $this->symbolIndex = new SymbolIndex();
        $this->handler = new CompletionHandler($this->documents, $this->parser, $this->symbolIndex, null);
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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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

    public function testFunctionCompletion(): void
    {
        $code = <<<'PHP'
<?php
function myCustomFunction(): void {}

$x = arr
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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

    public function testKeywordCompletions(): void
    {
        $code = '<?php fore';
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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

    public function testVariableCompletionWorksInClosures(): void
    {
        $code = '<?php $fn = function ($param) { $localVar = 1; $l; };';
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $handler = new CompletionHandler(
            $this->documents,
            $this->parser,
            $this->symbolIndex,
            null,
            new BasicTypeResolver(),
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
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $handler = new CompletionHandler(
            $this->documents,
            $this->parser,
            $this->symbolIndex,
            null,
            new BasicTypeResolver(),
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
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $handler = new CompletionHandler(
            $this->documents,
            $this->parser,
            $this->symbolIndex,
            null,
            new BasicTypeResolver(),
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
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $handler = new CompletionHandler(
            $this->documents,
            $this->parser,
            $this->symbolIndex,
            null,
            new BasicTypeResolver(),
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
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $handler = new CompletionHandler(
            $this->documents,
            $this->parser,
            $this->symbolIndex,
            null,
            new BasicTypeResolver(),
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
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $handler = new CompletionHandler(
            $this->documents,
            $this->parser,
            $this->symbolIndex,
            null,
            new BasicTypeResolver(),
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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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

    public function testTypedVariableCompletionReturnsEmptyWithoutTypeResolver(): void
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
        $this->documents->open('file:///test.php', 'php', 1, $code);

        // Handler without type resolver (uses default from setUp)
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
        // Without type resolver, no completions for typed variables
        self::assertEmpty($result['items']);
    }
}
