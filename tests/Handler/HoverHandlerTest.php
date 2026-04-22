<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\HoverHandler;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HoverHandler::class)]
class HoverHandlerTest extends TestCase
{
    private DocumentManager $documents;
    private ParserService $parser;
    private DefaultClassRepository $classRepository;
    private DefaultClassInfoFactory $classInfoFactory;
    private MemberResolver $memberResolver;
    private HoverHandler $handler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $this->parser = new ParserService();
        $this->classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $this->classRepository = new DefaultClassRepository(
            $this->classInfoFactory,
            $locator,
            $this->parser,
        );
        $this->memberResolver = new MemberResolver($this->classRepository);
        $this->handler = new HoverHandler(
            $this->documents,
            $this->parser,
            $this->classRepository,
            $this->classInfoFactory,
            $this->memberResolver,
        );
    }

    public function testSupports(): void
    {
        self::assertTrue($this->handler->supports('textDocument/hover'));
        self::assertFalse($this->handler->supports('textDocument/definition'));
    }

    public function testHoverOnClass(): void
    {
        $code = <<<'PHP'
<?php
/**
 * A sample class for testing.
 */
class MyClass
{
    public function doSomething(): void {}
}

$x = new MyClass();
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 9, 'character' => 12], // On "MyClass"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('contents', $result);
        self::assertStringContainsString('MyClass', $result['contents']);
    }

    public function testHoverOnClassWithDocblock(): void
    {
        $code = <<<'PHP'
<?php
/**
 * Represents a user in the system.
 *
 * @author Test
 */
class User {}

$u = new User();
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 8, 'character' => 10], // On "User"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('Represents a user', $result['contents']);
    }

    public function testHoverReturnsNullForUnknownPosition(): void
    {
        $code = '<?php // just a comment';
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 10],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertNull($result);
    }

    public function testHoverOnFunction(): void
    {
        $code = <<<'PHP'
<?php
/**
 * Adds two numbers together.
 */
function add(int $a, int $b): int
{
    return $a + $b;
}

$sum = add(1, 2);
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 9, 'character' => 8], // On "add"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('add', $result['contents']);
        self::assertStringContainsString('int', $result['contents']);
    }

    public function testHoverOnMethod(): void
    {
        $code = <<<'PHP'
<?php
class Calculator
{
    /**
     * Multiplies two numbers.
     */
    public function multiply(int $a, int $b): int
    {
        return $a * $b;
    }

    public function test(): void
    {
        $this->multiply(2, 3);
    }
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 13, 'character' => 16], // On "multiply"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('multiply', $result['contents']);
        self::assertStringContainsString('Multiplies two numbers', $result['contents']);
    }

    public function testHoverOnProperty(): void
    {
        $code = <<<'PHP'
<?php
class Person
{
    /**
     * The person's full name.
     */
    public string $name;

    public function greet(): string
    {
        return 'Hello, ' . $this->name;
    }
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 10, 'character' => 35], // On "name"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('$name', $result['contents']);
        self::assertStringContainsString('string', $result['contents']);
    }

    public function testHoverOnBuiltinFunction(): void
    {
        $code = <<<'PHP'
<?php
$arr = [3, 1, 2];
sort($arr);
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 2, 'character' => 2], // On "sort"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('sort', $result['contents']);
    }

    public function testHoverOnStaticMethod(): void
    {
        $code = <<<'PHP'
<?php
class Math
{
    /**
     * Returns the absolute value.
     */
    public static function abs(int $n): int
    {
        return $n < 0 ? -$n : $n;
    }
}

$result = Math::abs(-5);
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 12, 'character' => 16], // On "abs"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('abs', $result['contents']);
        self::assertStringContainsString('Returns the absolute value', $result['contents']);
    }

    public function testHoverOnTypedVariableMethodCall(): void
    {
        $code = <<<'PHP'
<?php
class Calculator
{
    /**
     * Adds two numbers.
     */
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}

function useCalculator(Calculator $calc): void
{
    $calc->add(1, 2);
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        // Create handler with type resolver
        $handlerWithResolver = new HoverHandler(
            $this->documents,
            $this->parser,
            $this->classRepository,
            $this->classInfoFactory,
            $this->memberResolver,
            new BasicTypeResolver(),
        );

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 14, 'character' => 12], // On "add"
            ],
        ]);

        $result = $handlerWithResolver->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('add', $result['contents']);
        self::assertStringContainsString('Adds two numbers', $result['contents']);
    }

    public function testHoverOnAssignedVariableMethodCall(): void
    {
        $code = <<<'PHP'
<?php
class Greeter
{
    /**
     * Greets a person.
     */
    public function greet(string $name): string
    {
        return "Hello, $name!";
    }
}

function test(): void
{
    $greeter = new Greeter();
    $greeter->greet("World");
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $handlerWithResolver = new HoverHandler(
            $this->documents,
            $this->parser,
            $this->classRepository,
            $this->classInfoFactory,
            $this->memberResolver,
            new BasicTypeResolver(),
        );

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 15, 'character' => 15], // On "greet"
            ],
        ]);

        $result = $handlerWithResolver->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('greet', $result['contents']);
        self::assertStringContainsString('Greets a person', $result['contents']);
    }

    public function testHoverOnInheritedMethod(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass
{
    /**
     * Parent method docs.
     */
    public function parentMethod(): void {}
}

class ChildClass extends ParentClass
{
    public function test(): void
    {
        $this->parentMethod();
    }
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 13, 'character' => 16], // On "parentMethod"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('parentMethod', $result['contents']);
        self::assertStringContainsString('Parent method docs', $result['contents']);
    }

    public function testHoverOnInheritedProperty(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass
{
    /**
     * Parent property docs.
     */
    protected string $parentProperty;
}

class ChildClass extends ParentClass
{
    public function test(): void
    {
        $this->parentProperty;
    }
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 13, 'character' => 16], // On "parentProperty"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('$parentProperty', $result['contents']);
        self::assertStringContainsString('Parent property docs', $result['contents']);
    }

    public function testHoverOnMultiLevelInheritedMethod(): void
    {
        $code = <<<'PHP'
<?php
class GrandparentClass
{
    /**
     * Grandparent method docs.
     */
    public function grandparentMethod(): void {}
}

class ParentClass extends GrandparentClass
{
}

class ChildClass extends ParentClass
{
    public function test(): void
    {
        $this->grandparentMethod();
    }
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 17, 'character' => 16],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('grandparentMethod', $result['contents']);
        self::assertStringContainsString('Grandparent method docs', $result['contents']);
    }

    public function testHoverOnMultiLevelInheritedProperty(): void
    {
        $code = <<<'PHP'
<?php
class GrandparentClass
{
    /**
     * Grandparent property docs.
     */
    protected string $grandparentProperty;
}

class ParentClass extends GrandparentClass
{
}

class ChildClass extends ParentClass
{
    public function test(): void
    {
        $this->grandparentProperty;
    }
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 17, 'character' => 16],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('$grandparentProperty', $result['contents']);
        self::assertStringContainsString('Grandparent property docs', $result['contents']);
    }

    public function testHoverOnInheritedMethodWithNamespace(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class ParentClass
{
    /**
     * Namespaced parent method.
     */
    public function parentMethod(): void {}
}

class ChildClass extends ParentClass
{
    public function test(): void
    {
        $this->parentMethod();
    }
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 15, 'character' => 16],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('parentMethod', $result['contents']);
        self::assertStringContainsString('Namespaced parent method', $result['contents']);
    }

    public function testHoverOnInheritedMethodAcrossNamespaces(): void
    {
        $code = <<<'PHP'
<?php
namespace Base;

class ParentClass
{
    /**
     * Method from Base namespace.
     */
    public function baseMethod(): void {}
}

namespace App;

use Base\ParentClass;

class ChildClass extends ParentClass
{
    public function test(): void
    {
        $this->baseMethod();
    }
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 19, 'character' => 16],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('baseMethod', $result['contents']);
        self::assertStringContainsString('Method from Base namespace', $result['contents']);
    }

    public function testHoverOnPrivateInheritedMethodReturnsNull(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass
{
    /**
     * Private parent method.
     */
    private function privateMethod(): void {}
}

class ChildClass extends ParentClass
{
    public function test(): void
    {
        $this->privateMethod();
    }
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 13, 'character' => 16],
            ],
        ]);

        $result = $this->handler->handle($request);

        // Private methods are not inherited, so hover should return null
        self::assertNull($result);
    }

    public function testHoverOnPrivateInheritedPropertyReturnsNull(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass
{
    /**
     * Private parent property.
     */
    private string $privateProperty;
}

class ChildClass extends ParentClass
{
    public function test(): void
    {
        $this->privateProperty;
    }
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 13, 'character' => 16],
            ],
        ]);

        $result = $this->handler->handle($request);

        // Private properties are not inherited, so hover should return null
        self::assertNull($result);
    }

    public function testHoverOnOverriddenMethodShowsChildVersion(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass
{
    /**
     * Parent implementation.
     */
    public function sharedMethod(): void {}
}

class ChildClass extends ParentClass
{
    /**
     * Child implementation.
     */
    public function sharedMethod(): void {}

    public function test(): void
    {
        $this->sharedMethod();
    }
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 18, 'character' => 16],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('sharedMethod', $result['contents']);
        self::assertStringContainsString('Child implementation', $result['contents']);
        self::assertStringNotContainsString('Parent implementation', $result['contents']);
    }

    public function testHoverOnOverriddenPropertyShowsChildVersion(): void
    {
        $code = <<<'PHP'
<?php
class ParentClass
{
    /**
     * Parent property.
     */
    protected string $sharedProperty;
}

class ChildClass extends ParentClass
{
    /**
     * Child property.
     */
    protected string $sharedProperty;

    public function test(): void
    {
        $this->sharedProperty;
    }
}
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 18, 'character' => 16],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('$sharedProperty', $result['contents']);
        self::assertStringContainsString('Child property', $result['contents']);
        self::assertStringNotContainsString('Parent property', $result['contents']);
    }
}
