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
}
