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
        $userUri = $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'create');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertSame($userUri, $result['uri']);
        self::assertSame(123, $result['range']['start']['line']);
    }

    public function testGoToInstanceMethodDefinition(): void
    {
        $userUri = $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'setName');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertSame($userUri, $result['uri']);
        self::assertSame(47, $result['range']['start']['line']);
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
        $parentUri = $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'inherited_method');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertSame($parentUri, $result['uri']);
        self::assertSame(28, $result['range']['start']['line']);
    }

    public function testGoToOverriddenMethodDefinition(): void
    {
        $this->openFixture('src/Inheritance/ParentClass.php');
        $childUri = $this->openFixture('src/Inheritance/ChildClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'overridden_method');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertSame($childUri, $result['uri']);
        self::assertSame(21, $result['range']['start']['line']);
    }

    public function testGoToParentMethodDefinition(): void
    {
        $parentUri = $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'parent_method');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertSame($parentUri, $result['uri']);
        self::assertSame(28, $result['range']['start']['line']);
    }

    public function testGoToTraitMethodDefinition(): void
    {
        $traitUri = $this->openFixture('src/Traits/HasTimestamps.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'markCreated');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertSame($traitUri, $result['uri']);
        self::assertSame(26, $result['range']['start']['line']);
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
        $parentUri = $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'self_method');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertSame($parentUri, $result['uri']);
        self::assertSame(38, $result['range']['start']['line']);
    }

    public function testGoToStaticKeywordMethodDefinition(): void
    {
        $parentUri = $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'static_method');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertSame($parentUri, $result['uri']);
        self::assertSame(38, $result['range']['start']['line']);
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
        $cursor = $this->openFixtureAtHoverMarker('EdgeCases/ParentWithoutExtends.php', 'parent_method');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertNull($result);
    }

    public function testReturnsNullForSelfOutsideClass(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('EdgeCases/SelfOutsideClass.php', 'self_method');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertNull($result);
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
        $userUri = $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'setName_nullsafe');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertSame($userUri, $result['uri']);
        self::assertSame(47, $result['range']['start']['line']);
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

    public function testGoToEnumMethodFromWithinEnum(): void
    {
        $statusUri = $this->openFixture('src/Enum/Status.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Enum/Status.php', 'enum_method');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertIsArray($result);
        self::assertSame($statusUri, $result['uri']);
        self::assertSame(16, $result['range']['start']['line']);
    }

    public function testGoToSelfDefinitionOutsideClassReturnsNull(): void
    {
        $cursor = $this->openFixtureAtCursor('EdgeCases/SelfOutsideClass.php', 'def_new_self');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertNull($result);
    }

    public function testGoToParentDefinitionWithoutExtendsReturnsNull(): void
    {
        $cursor = $this->openFixtureAtCursor('EdgeCases/ParentWithoutExtends.php', 'def_new_parent');

        $result = $this->handler->handle($this->definitionRequestAt($cursor));

        self::assertNull($result);
    }

    /**
     * Tests handleNameDefinition null path when self is used outside class.
     * Uses inline code with manual offset because cursor must land ON "self",
     * not after it, and the /*|marker* / syntax can't split a keyword.
     */
    public function testGoToSelfClassConstantOutsideClassReturnsNull(): void
    {
        // $x = self::class;
        //      ^--- cursor on 's' of self (character 5)
        $this->openDocument('file:///test.php', '<?php $x = self::class;');

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 11],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertNull($result);
    }
}
