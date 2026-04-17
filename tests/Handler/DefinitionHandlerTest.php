<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\DefinitionHandler;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinitionHandler::class)]
class DefinitionHandlerTest extends TestCase
{
    private DocumentManager $documents;
    private SymbolIndex $index;
    private ParserService $parser;
    private DefinitionHandler $handler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $this->index = new SymbolIndex();
        $this->parser = new ParserService();
        $indexer = new DocumentIndexer(
            $this->parser,
            new SymbolExtractor(),
            $this->index,
        );
        $this->handler = new DefinitionHandler(
            $this->documents,
            $this->parser,
            $this->index,
            classLocator: null,
            typeResolver: new BasicTypeResolver(),
        );
    }

    public function testSupports(): void
    {
        self::assertTrue($this->handler->supports('textDocument/definition'));
        self::assertFalse($this->handler->supports('textDocument/hover'));
    }

    public function testGoToClassDefinition(): void
    {
        // Set up a class definition
        $classCode = '<?php class MyClass {}';
        $this->documents->open('file:///MyClass.php', 'php', 1, $classCode);
        $classDoc = $this->documents->get('file:///MyClass.php');
        self::assertNotNull($classDoc);
        $ast = $this->parser->parse($classDoc);
        self::assertNotNull($ast);
        $symbols = (new SymbolExtractor())->extract($classDoc, $ast);
        foreach ($symbols as $symbol) {
            $this->index->add($symbol);
        }

        // Set up usage
        $usageCode = '<?php new MyClass();';
        $this->documents->open('file:///usage.php', 'php', 1, $usageCode);

        // Request definition at "MyClass" in usage (position after "new ")
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///usage.php'],
                'position' => ['line' => 0, 'character' => 10], // On "MyClass"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertSame('file:///MyClass.php', $result['uri']);
        self::assertArrayHasKey('range', $result);
    }

    public function testReturnsNullForUnknownSymbol(): void
    {
        $code = '<?php new UnknownClass();';
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 10],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertNull($result);
    }

    public function testReturnsNullForUnknownDocument(): void
    {
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///unknown.php'],
                'position' => ['line' => 0, 'character' => 0],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertNull($result);
    }

    public function testGoToStaticMethodDefinition(): void
    {
        // Class with a static method
        $classCode = <<<'PHP'
<?php
class MyClass {
    public static function myStaticMethod(): void {}
}
PHP;
        $this->documents->open('file:///MyClass.php', 'php', 1, $classCode);
        $classDoc = $this->documents->get('file:///MyClass.php');
        self::assertNotNull($classDoc);
        $ast = $this->parser->parse($classDoc);
        self::assertNotNull($ast);
        $symbols = (new SymbolExtractor())->extract($classDoc, $ast);
        foreach ($symbols as $symbol) {
            $this->index->add($symbol);
        }

        // Usage: MyClass::myStaticMethod()
        $usageCode = '<?php MyClass::myStaticMethod();';
        $this->documents->open('file:///usage.php', 'php', 1, $usageCode);

        // Request definition at "myStaticMethod" (character 15 is on the method name)
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///usage.php'],
                'position' => ['line' => 0, 'character' => 15],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertSame('file:///MyClass.php', $result['uri']);
        // Line 2 (0-indexed) is where the method is defined
        self::assertSame(2, $result['range']['start']['line']);
    }

    public function testGoToInstanceMethodDefinition(): void
    {
        // Class with an instance method
        $classCode = <<<'PHP'
<?php
class MyClass {
    public function myMethod(): void {}
}
PHP;
        $this->documents->open('file:///MyClass.php', 'php', 1, $classCode);
        $classDoc = $this->documents->get('file:///MyClass.php');
        self::assertNotNull($classDoc);
        $ast = $this->parser->parse($classDoc);
        self::assertNotNull($ast);
        $symbols = (new SymbolExtractor())->extract($classDoc, $ast);
        foreach ($symbols as $symbol) {
            $this->index->add($symbol);
        }

        // Usage: $obj->myMethod() where $obj is typed
        $usageCode = <<<'PHP'
<?php
function test(MyClass $obj): void {
    $obj->myMethod();
}
PHP;
        $this->documents->open('file:///usage.php', 'php', 1, $usageCode);

        // Request definition at "myMethod" on line 2 (0-indexed)
        // "$obj->myMethod()" - "myMethod" starts at character 10
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///usage.php'],
                'position' => ['line' => 2, 'character' => 12],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertSame('file:///MyClass.php', $result['uri']);
        self::assertSame(2, $result['range']['start']['line']);
    }

    public function testGoToMethodDefinitionViaAssignment(): void
    {
        // Class with an instance method
        $classCode = <<<'PHP'
<?php
class MyClass {
    public function myMethod(): void {}
}
PHP;
        $this->documents->open('file:///MyClass.php', 'php', 1, $classCode);
        $classDoc = $this->documents->get('file:///MyClass.php');
        self::assertNotNull($classDoc);
        $ast = $this->parser->parse($classDoc);
        self::assertNotNull($ast);
        $symbols = (new SymbolExtractor())->extract($classDoc, $ast);
        foreach ($symbols as $symbol) {
            $this->index->add($symbol);
        }

        // Usage: $obj = new MyClass(); $obj->myMethod();
        $usageCode = <<<'PHP'
<?php
function test(): void {
    $obj = new MyClass();
    $obj->myMethod();
}
PHP;
        $this->documents->open('file:///usage.php', 'php', 1, $usageCode);

        // Request definition at "myMethod" on line 3 (0-indexed)
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///usage.php'],
                'position' => ['line' => 3, 'character' => 12],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertSame('file:///MyClass.php', $result['uri']);
        self::assertSame(2, $result['range']['start']['line']);
    }

    public function testReturnsNullForMethodOnUnknownType(): void
    {
        $usageCode = <<<'PHP'
<?php
function test($obj): void {
    $obj->unknownMethod();
}
PHP;
        $this->documents->open('file:///usage.php', 'php', 1, $usageCode);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///usage.php'],
                'position' => ['line' => 2, 'character' => 12],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertNull($result);
    }

    public function testGoToInheritedMethodDefinition(): void
    {
        // Parent class with method
        $parentCode = <<<'PHP'
<?php
class ParentClass {
    public function inheritedMethod(): void {}
}
PHP;
        $this->documents->open('file:///ParentClass.php', 'php', 1, $parentCode);
        $parentDoc = $this->documents->get('file:///ParentClass.php');
        self::assertNotNull($parentDoc);
        $parentAst = $this->parser->parse($parentDoc);
        self::assertNotNull($parentAst);
        foreach ((new SymbolExtractor())->extract($parentDoc, $parentAst) as $symbol) {
            $this->index->add($symbol);
        }

        // Child class that extends parent
        $childCode = <<<'PHP'
<?php
class ChildClass extends ParentClass {
}
PHP;
        $this->documents->open('file:///ChildClass.php', 'php', 1, $childCode);
        $childDoc = $this->documents->get('file:///ChildClass.php');
        self::assertNotNull($childDoc);
        $childAst = $this->parser->parse($childDoc);
        self::assertNotNull($childAst);
        foreach ((new SymbolExtractor())->extract($childDoc, $childAst) as $symbol) {
            $this->index->add($symbol);
        }

        // Usage: $child->inheritedMethod()
        $usageCode = <<<'PHP'
<?php
function test(ChildClass $child): void {
    $child->inheritedMethod();
}
PHP;
        $this->documents->open('file:///usage.php', 'php', 1, $usageCode);

        // Request definition at "inheritedMethod"
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///usage.php'],
                'position' => ['line' => 2, 'character' => 14],
            ],
        ]);

        $result = $this->handler->handle($request);

        // Should go to ParentClass where the method is actually defined
        self::assertIsArray($result);
        self::assertSame('file:///ParentClass.php', $result['uri']);
        self::assertSame(2, $result['range']['start']['line']);
    }

    public function testGoToOverriddenMethodDefinition(): void
    {
        // Parent class with method
        $parentCode = <<<'PHP'
<?php
class ParentClass {
    public function overriddenMethod(): void {}
}
PHP;
        $this->documents->open('file:///ParentClass.php', 'php', 1, $parentCode);
        $parentDoc = $this->documents->get('file:///ParentClass.php');
        self::assertNotNull($parentDoc);
        $parentAst = $this->parser->parse($parentDoc);
        self::assertNotNull($parentAst);
        foreach ((new SymbolExtractor())->extract($parentDoc, $parentAst) as $symbol) {
            $this->index->add($symbol);
        }

        // Child class that overrides the method
        $childCode = <<<'PHP'
<?php
class ChildClass extends ParentClass {
    public function overriddenMethod(): void {}
}
PHP;
        $this->documents->open('file:///ChildClass.php', 'php', 1, $childCode);
        $childDoc = $this->documents->get('file:///ChildClass.php');
        self::assertNotNull($childDoc);
        $childAst = $this->parser->parse($childDoc);
        self::assertNotNull($childAst);
        foreach ((new SymbolExtractor())->extract($childDoc, $childAst) as $symbol) {
            $this->index->add($symbol);
        }

        // Usage: $child->overriddenMethod()
        $usageCode = <<<'PHP'
<?php
function test(ChildClass $child): void {
    $child->overriddenMethod();
}
PHP;
        $this->documents->open('file:///usage.php', 'php', 1, $usageCode);

        // Request definition at "overriddenMethod"
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///usage.php'],
                'position' => ['line' => 2, 'character' => 14],
            ],
        ]);

        $result = $this->handler->handle($request);

        // Should go to ChildClass where the method is overridden
        self::assertIsArray($result);
        self::assertSame('file:///ChildClass.php', $result['uri']);
        self::assertSame(2, $result['range']['start']['line']);
    }

    public function testGoToParentMethodDefinition(): void
    {
        // Parent class with method
        $parentCode = <<<'PHP'
<?php
class ParentClass {
    public function doFoo(): void {}
}
PHP;
        $this->documents->open('file:///ParentClass.php', 'php', 1, $parentCode);
        $parentDoc = $this->documents->get('file:///ParentClass.php');
        self::assertNotNull($parentDoc);
        $parentAst = $this->parser->parse($parentDoc);
        self::assertNotNull($parentAst);
        foreach ((new SymbolExtractor())->extract($parentDoc, $parentAst) as $symbol) {
            $this->index->add($symbol);
        }

        // Child class that calls parent::doFoo()
        $childCode = <<<'PHP'
<?php
class ChildClass extends ParentClass {
    public function doFoo(): void {
        parent::doFoo();
    }
}
PHP;
        $this->documents->open('file:///ChildClass.php', 'php', 1, $childCode);
        $childDoc = $this->documents->get('file:///ChildClass.php');
        self::assertNotNull($childDoc);
        $childAst = $this->parser->parse($childDoc);
        self::assertNotNull($childAst);
        foreach ((new SymbolExtractor())->extract($childDoc, $childAst) as $symbol) {
            $this->index->add($symbol);
        }

        // Request definition at "doFoo" in parent::doFoo() on line 3
        // "        parent::doFoo();" - doFoo starts at character 16
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///ChildClass.php'],
                'position' => ['line' => 3, 'character' => 17],
            ],
        ]);

        $result = $this->handler->handle($request);

        // Should go to ParentClass, not ChildClass
        self::assertIsArray($result);
        self::assertSame('file:///ParentClass.php', $result['uri']);
        self::assertSame(2, $result['range']['start']['line']);
    }
}
