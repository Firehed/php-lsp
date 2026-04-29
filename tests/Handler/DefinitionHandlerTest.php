<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\DefinitionHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinitionHandler::class)]
class DefinitionHandlerTest extends TestCase
{
    use OpensDocumentsTrait;

    private DocumentManager $documents;
    private ParserService $parser;
    private DefaultClassRepository $classRepository;
    private MemberResolver $memberResolver;
    private DefinitionHandler $handler;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $this->parser = new ParserService();
        $classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $this->classRepository = new DefaultClassRepository(
            $classInfoFactory,
            $locator,
            $this->parser,
        );
        $this->memberResolver = new MemberResolver($this->classRepository);
        $typeResolver = new BasicTypeResolver($this->memberResolver);
        $this->handler = new DefinitionHandler(
            $this->documents,
            $this->parser,
            $this->memberResolver,
            $this->classRepository,
            new MemberAccessResolver($typeResolver),
        );
        $indexer = new DocumentIndexer($this->parser, new SymbolExtractor(), new SymbolIndex());
        $this->syncHandler = new TextDocumentSyncHandler(
            $this->documents,
            $this->parser,
            $this->classRepository,
            $classInfoFactory,
            $indexer,
        );
    }

    public function testSupports(): void
    {
        self::assertTrue($this->handler->supports('textDocument/definition'));
        self::assertFalse($this->handler->supports('textDocument/hover'));
    }

    public function testGoToClassDefinition(): void
    {
        $this->openDocument('file:///MyClass.php', '<?php class MyClass {}');
        $this->openDocument('file:///usage.php', '<?php new MyClass();');

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
        $this->openDocument('file:///test.php', '<?php new UnknownClass();');

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
        $classCode = <<<'PHP'
<?php
class MyClass {
    public static function myStaticMethod(): void {}
}
PHP;
        $this->openDocument('file:///MyClass.php', $classCode);
        $this->openDocument('file:///usage.php', '<?php MyClass::myStaticMethod();');

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
        $classCode = <<<'PHP'
<?php
class MyClass {
    public function myMethod(): void {}
}
PHP;
        $this->openDocument('file:///MyClass.php', $classCode);

        $usageCode = <<<'PHP'
<?php
function test(MyClass $obj): void {
    $obj->myMethod();
}
PHP;
        $this->openDocument('file:///usage.php', $usageCode);

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
        $classCode = <<<'PHP'
<?php
class MyClass {
    public function myMethod(): void {}
}
PHP;
        $this->openDocument('file:///MyClass.php', $classCode);

        $usageCode = <<<'PHP'
<?php
function test(): void {
    $obj = new MyClass();
    $obj->myMethod();
}
PHP;
        $this->openDocument('file:///usage.php', $usageCode);

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
        $this->openDocument('file:///usage.php', $usageCode);

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
        $parentCode = <<<'PHP'
<?php
class ParentClass {
    public function inheritedMethod(): void {}
}
PHP;
        $this->openDocument('file:///ParentClass.php', $parentCode);

        $childCode = <<<'PHP'
<?php
class ChildClass extends ParentClass {
}
PHP;
        $this->openDocument('file:///ChildClass.php', $childCode);

        $usageCode = <<<'PHP'
<?php
function test(ChildClass $child): void {
    $child->inheritedMethod();
}
PHP;
        $this->openDocument('file:///usage.php', $usageCode);

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
        $parentCode = <<<'PHP'
<?php
class ParentClass {
    public function overriddenMethod(): void {}
}
PHP;
        $this->openDocument('file:///ParentClass.php', $parentCode);

        $childCode = <<<'PHP'
<?php
class ChildClass extends ParentClass {
    public function overriddenMethod(): void {}
}
PHP;
        $this->openDocument('file:///ChildClass.php', $childCode);

        $usageCode = <<<'PHP'
<?php
function test(ChildClass $child): void {
    $child->overriddenMethod();
}
PHP;
        $this->openDocument('file:///usage.php', $usageCode);

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
        $parentCode = <<<'PHP'
<?php
class ParentClass {
    public function doFoo(): void {}
}
PHP;
        $this->openDocument('file:///ParentClass.php', $parentCode);

        $childCode = <<<'PHP'
<?php
class ChildClass extends ParentClass {
    public function doFoo(): void {
        parent::doFoo();
    }
}
PHP;
        $this->openDocument('file:///ChildClass.php', $childCode);

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

    public function testGoToTraitMethodDefinition(): void
    {
        $traitCode = <<<'PHP'
<?php
trait MyTrait {
    public function traitMethod(): void {}
}
PHP;
        $this->openDocument('file:///MyTrait.php', $traitCode);

        $classCode = <<<'PHP'
<?php
class MyClass {
    use MyTrait;
}
PHP;
        $this->openDocument('file:///MyClass.php', $classCode);

        $usageCode = <<<'PHP'
<?php
function test(MyClass $obj): void {
    $obj->traitMethod();
}
PHP;
        $this->openDocument('file:///usage.php', $usageCode);

        // Request definition at "traitMethod"
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

        // Should go to the trait where the method is defined
        self::assertIsArray($result);
        self::assertSame('file:///MyTrait.php', $result['uri']);
        self::assertSame(2, $result['range']['start']['line']);
    }

    public function testTraitMethodTakesPrecedenceOverParent(): void
    {
        $parentCode = <<<'PHP'
<?php
class ParentClass {
    public function sharedMethod(): void {}
}
PHP;
        $this->openDocument('file:///ParentClass.php', $parentCode);

        $traitCode = <<<'PHP'
<?php
trait MyTrait {
    public function sharedMethod(): void {}
}
PHP;
        $this->openDocument('file:///MyTrait.php', $traitCode);

        $childCode = <<<'PHP'
<?php
class ChildClass extends ParentClass {
    use MyTrait;
}
PHP;
        $this->openDocument('file:///ChildClass.php', $childCode);

        $usageCode = <<<'PHP'
<?php
function test(ChildClass $obj): void {
    $obj->sharedMethod();
}
PHP;
        $this->openDocument('file:///usage.php', $usageCode);

        // Request definition at "sharedMethod"
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

        // Should go to trait (trait takes precedence over parent in PHP)
        self::assertIsArray($result);
        self::assertSame('file:///MyTrait.php', $result['uri']);
        self::assertSame(2, $result['range']['start']['line']);
    }

    public function testReturnsNullForInvalidTextDocumentParam(): void
    {
        $this->openDocument('file:///test.php', '<?php class Foo {}');

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => 'not-an-array',
                'position' => ['line' => 0, 'character' => 10],
            ],
        ]);

        self::assertNull($this->handler->handle($request));
    }

    public function testReturnsNullForInvalidUriParam(): void
    {
        $this->openDocument('file:///test.php', '<?php class Foo {}');

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 123],
                'position' => ['line' => 0, 'character' => 10],
            ],
        ]);

        self::assertNull($this->handler->handle($request));
    }

    public function testReturnsNullForInvalidPositionParam(): void
    {
        $this->openDocument('file:///test.php', '<?php class Foo {}');

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => 'not-an-array',
            ],
        ]);

        self::assertNull($this->handler->handle($request));
    }

    public function testReturnsNullForInvalidLineCharacterParams(): void
    {
        $this->openDocument('file:///test.php', '<?php class Foo {}');

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 'not-int', 'character' => 0],
            ],
        ]);

        self::assertNull($this->handler->handle($request));
    }

    public function testReturnsNullForPositionOutsideCode(): void
    {
        $this->openDocument('file:///test.php', '<?php class Foo {}');

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 0],
            ],
        ]);

        self::assertNull($this->handler->handle($request));
    }

    public function testReturnsNullForDynamicStaticMethodName(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public static function test(): void {
        $method = 'foo';
        self::$method();
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        // Position on $method in self::$method()
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 4, 'character' => 15],
            ],
        ]);

        self::assertNull($this->handler->handle($request));
    }

    public function testReturnsNullForDynamicClassName(): void
    {
        $code = <<<'PHP'
<?php
$class = 'MyClass';
$class::method();
PHP;
        $this->openDocument('file:///test.php', $code);

        // Position on ::method
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 2, 'character' => 10],
            ],
        ]);

        self::assertNull($this->handler->handle($request));
    }

    public function testGoToSelfMethodDefinition(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public static function foo(): void {}
    public static function bar(): void {
        self::foo();
    }
}
PHP;
        $this->openDocument('file:///MyClass.php', $code);

        // Position on foo in self::foo()
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///MyClass.php'],
                'position' => ['line' => 4, 'character' => 15],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertSame('file:///MyClass.php', $result['uri']);
        self::assertSame(2, $result['range']['start']['line']);
    }

    public function testGoToStaticKeywordMethodDefinition(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public static function foo(): void {}
    public function bar(): void {
        static::foo();
    }
}
PHP;
        $this->openDocument('file:///MyClass.php', $code);

        // Position on foo in static::foo()
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///MyClass.php'],
                'position' => ['line' => 4, 'character' => 17],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertSame('file:///MyClass.php', $result['uri']);
        self::assertSame(2, $result['range']['start']['line']);
    }

    public function testReturnsNullForUnknownMethod(): void
    {
        $classCode = <<<'PHP'
<?php
class MyClass {
    public function existingMethod(): void {}
}
PHP;
        $this->openDocument('file:///MyClass.php', $classCode);

        $usageCode = <<<'PHP'
<?php
function test(MyClass $obj): void {
    $obj->nonExistentMethod();
}
PHP;
        $this->openDocument('file:///usage.php', $usageCode);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///usage.php'],
                'position' => ['line' => 2, 'character' => 12],
            ],
        ]);

        self::assertNull($this->handler->handle($request));
    }

    public function testReturnsNullForDynamicInstanceMethodName(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {}
function test(MyClass $obj): void {
    $method = 'foo';
    $obj->$method();
}
PHP;
        $this->openDocument('file:///test.php', $code);

        // Position on $method in $obj->$method()
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 4, 'character' => 11],
            ],
        ]);

        self::assertNull($this->handler->handle($request));
    }

    public function testReturnsNullForParentWithoutExtends(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public function test(): void {
        parent::foo();
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        // Position on foo in parent::foo()
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 3, 'character' => 17],
            ],
        ]);

        self::assertNull($this->handler->handle($request));
    }

    public function testReturnsNullForSelfOutsideClass(): void
    {
        $code = <<<'PHP'
<?php
self::foo();
PHP;
        $this->openDocument('file:///test.php', $code);

        // Position on foo in self::foo()
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 1, 'character' => 7],
            ],
        ]);

        self::assertNull($this->handler->handle($request));
    }

    public function testReturnsNullForBuiltInClassMethod(): void
    {
        // Built-in classes from reflection have no file location
        $code = '<?php DateTime::createFromFormat("Y", "2024");';
        $this->openDocument('file:///test.php', $code);

        // Position on createFromFormat
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 18],
            ],
        ]);

        self::assertNull($this->handler->handle($request));
    }

    public function testReturnsNullForBuiltInClass(): void
    {
        // Built-in classes from reflection have no file location
        $code = '<?php new DateTime();';
        $this->openDocument('file:///test.php', $code);

        // Position on DateTime
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 12],
            ],
        ]);

        self::assertNull($this->handler->handle($request));
    }

    public function testGoToPrivateMethodDefinition(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    private function privateMethod(): void {}
    public function publicMethod(): void {
        $this->privateMethod();
    }
}
PHP;
        $this->openDocument('file:///MyClass.php', $code);

        // Position on privateMethod in $this->privateMethod()
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///MyClass.php'],
                'position' => ['line' => 4, 'character' => 16],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertSame('file:///MyClass.php', $result['uri']);
        self::assertSame(2, $result['range']['start']['line']);
    }

    public function testGoToProtectedMethodDefinition(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    protected function protectedMethod(): void {}
    public function publicMethod(): void {
        $this->protectedMethod();
    }
}
PHP;
        $this->openDocument('file:///MyClass.php', $code);

        // Position on protectedMethod in $this->protectedMethod()
        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///MyClass.php'],
                'position' => ['line' => 4, 'character' => 16],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertSame('file:///MyClass.php', $result['uri']);
        self::assertSame(2, $result['range']['start']['line']);
    }

    public function testGoToNullsafeMethodDefinition(): void
    {
        $classCode = <<<'PHP'
<?php
class MyClass {
    public function myMethod(): void {}
}
PHP;
        $this->openDocument('file:///MyClass.php', $classCode);

        $usageCode = <<<'PHP'
<?php
function test(?MyClass $obj): void {
    $obj?->myMethod();
}
PHP;
        $this->openDocument('file:///usage.php', $usageCode);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///usage.php'],
                'position' => ['line' => 2, 'character' => 13], // On "myMethod"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertSame('file:///MyClass.php', $result['uri']);
        self::assertSame(2, $result['range']['start']['line']);
    }

    public function testGoToNullsafeMethodDefinitionViaAssignment(): void
    {
        $classCode = <<<'PHP'
<?php
class MyClass {
    public function myMethod(): void {}
}
PHP;
        $this->openDocument('file:///MyClass.php', $classCode);

        $usageCode = <<<'PHP'
<?php
function test(): void {
    $obj = rand() ? new MyClass() : null;
    $obj?->myMethod();
}
PHP;
        $this->openDocument('file:///usage.php', $usageCode);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///usage.php'],
                'position' => ['line' => 3, 'character' => 13], // On "myMethod"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertSame('file:///MyClass.php', $result['uri']);
        self::assertSame(2, $result['range']['start']['line']);
    }
}
