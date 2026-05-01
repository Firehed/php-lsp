<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\HoverHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HoverHandler::class)]
class HoverHandlerTest extends TestCase
{
    use OpensDocumentsTrait;

    private DocumentManager $documents;
    private ParserService $parser;
    private DefaultClassRepository $classRepository;
    private DefaultClassInfoFactory $classInfoFactory;
    private MemberResolver $memberResolver;
    private HoverHandler $handler;
    private TextDocumentSyncHandler $syncHandler;

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
        $typeResolver = new BasicTypeResolver($this->memberResolver);
        $this->handler = new HoverHandler(
            $this->documents,
            $this->parser,
            $this->classRepository,
            $this->memberResolver,
            new MemberAccessResolver($typeResolver),
        );
        $indexer = new DocumentIndexer($this->parser, new SymbolExtractor(), new SymbolIndex());
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
        $this->openDocument('file:///test.php', $code);

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
        $this->openDocument('file:///test.php', $code);

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
        $this->openDocument('file:///test.php', $code);

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
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'signatureHelpAdd');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('signatureHelpAdd', $result['contents']);
        self::assertStringContainsString('int', $result['contents']);
        self::assertStringContainsString('Adds two numbers together', $result['contents']);
    }

    public function testHoverOnMethod(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'setName');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']);
        self::assertStringContainsString('Updates the user', $result['contents']);
    }

    public function testHoverOnProperty(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'manager');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$manager', $result['contents']);
        self::assertStringContainsString('User', $result['contents']);
    }

    public function testHoverOnBuiltinFunction(): void
    {
        $code = <<<'PHP'
<?php
$arr = [3, 1, 2];
sort($arr);
PHP;
        $this->openDocument('file:///test.php', $code);

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

    public function testHoverOnExternalFunctionWithDocblock(): void
    {
        require_once __DIR__ . '/../Domain/Fixtures/documented_function.php';

        $code = <<<'PHP'
<?php
testDocumentedFunction();
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 1, 'character' => 5],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('testDocumentedFunction', $result['contents']);
        self::assertStringContainsString('A test function with documentation', $result['contents']);
    }

    public function testHoverOnStaticMethod(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'create');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('create', $result['contents']);
    }

    public function testHoverOnTypedVariableMethodCall(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'typedVarMethod');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']);
        self::assertStringContainsString('Updates the user', $result['contents']);
    }

    public function testHoverOnAssignedVariableMethodCall(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'assignedVarMethod');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']);
        self::assertStringContainsString('Updates the user', $result['contents']);
    }

    public function testHoverOnInheritedMethod(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'inherited_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('parentMethod', $result['contents']);
        self::assertStringContainsString('Parent method documentation', $result['contents']);
    }

    public function testHoverOnInheritedProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'inherited_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$parentProperty', $result['contents']);
        self::assertStringContainsString('Parent property', $result['contents']);
    }

    public function testHoverOnMultiLevelInheritedMethod(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'grandparent_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('grandparentMethod', $result['contents']);
        self::assertStringContainsString('Grandparent method documentation', $result['contents']);
    }

    public function testHoverOnMultiLevelInheritedProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'grandparent_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$grandparentProperty', $result['contents']);
        self::assertStringContainsString('Grandparent property', $result['contents']);
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
        $this->openDocument('file:///test.php', $code);

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

    public function testHoverOnTraitMethod(): void
    {
        $this->openFixture('src/Traits/HasTimestamps.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'markCreated');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('markCreated', $result['contents']);
    }

    public function testHoverOnTraitProperty(): void
    {
        $code = <<<'PHP'
<?php
trait HasName
{
    /**
     * The name value.
     */
    protected string $name;
}

class Person
{
    use HasName;

    public function test(): void
    {
        $this->name;
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

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
        self::assertStringContainsString('$name', $result['contents']);
        self::assertStringContainsString('The name value', $result['contents']);
    }

    public function testHoverOnInterfaceMethod(): void
    {
        $code = <<<'PHP'
<?php
interface Greeter
{
    /**
     * Says hello.
     */
    public function greet(): void;
}

function test(Greeter $g): void
{
    $g->greet();
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 11, 'character' => 8],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('greet', $result['contents']);
        self::assertStringContainsString('Says hello', $result['contents']);
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
        $this->openDocument('file:///test.php', $code);

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
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'private_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertNull($result);
    }

    public function testHoverOnPrivateInheritedPropertyReturnsNull(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'private_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertNull($result);
    }

    public function testHoverOnOverriddenMethodShowsChildVersion(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'overridden_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('overriddenMethod', $result['contents']);
        self::assertStringContainsString('Child implementation', $result['contents']);
        self::assertStringNotContainsString('Parent implementation', $result['contents']);
    }

    public function testHoverOnOverriddenPropertyShowsChildVersion(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'shared_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$sharedProperty', $result['contents']);
        self::assertStringContainsString('Child override', $result['contents']);
        self::assertStringNotContainsString('Shared property from parent', $result['contents']);
    }

    public function testHoverOnStaticProperty(): void
    {
        $code = <<<'PHP'
<?php
class Config
{
    /**
     * Application name.
     */
    public static string $appName = 'MyApp';
}

$name = Config::$appName;
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 9, 'character' => 18],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('$appName', $result['contents']);
        self::assertStringContainsString('static', $result['contents']);
        self::assertStringContainsString('Application name', $result['contents']);
    }

    public function testHoverOnBuiltinClassMethod(): void
    {
        $code = <<<'PHP'
<?php
function test(ArrayObject $obj): void
{
    $obj->getArrayCopy();
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $handlerWithResolver = new HoverHandler(
            $this->documents,
            $this->parser,
            $this->classRepository,
            $this->memberResolver,
            new MemberAccessResolver(new BasicTypeResolver($this->memberResolver)),
        );

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 3, 'character' => 12],
            ],
        ]);

        $result = $handlerWithResolver->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('getArrayCopy', $result['contents']);
    }

    public function testHoverOnBuiltinClassProperty(): void
    {
        $code = <<<'PHP'
<?php
function test(Exception $e): void
{
    $e->message;
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $handlerWithResolver = new HoverHandler(
            $this->documents,
            $this->parser,
            $this->classRepository,
            $this->memberResolver,
            new MemberAccessResolver(new BasicTypeResolver($this->memberResolver)),
        );

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 3, 'character' => 9],
            ],
        ]);

        $result = $handlerWithResolver->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('$message', $result['contents']);
    }

    public function testHoverOnMethodWithVariadicParameter(): void
    {
        $code = <<<'PHP'
<?php
class Logger
{
    public function log(string $level, string ...$messages): void {}

    public function test(): void
    {
        $this->log('info', 'a', 'b');
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 7, 'character' => 16],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('...$messages', $result['contents']);
    }

    public function testHoverOnMethodWithOptionalParameter(): void
    {
        $code = <<<'PHP'
<?php
class Greeter
{
    public function greet(string $name, string $prefix = 'Hello'): string
    {
        return "$prefix, $name!";
    }

    public function test(): void
    {
        $this->greet('World');
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 10, 'character' => 16],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('$prefix = ...', $result['contents']);
        self::assertStringNotContainsString('$name = ...', $result['contents']);
    }

    public function testHoverOnNullsafeMethodCall(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'setName_nullsafe');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']);
        self::assertStringContainsString('Updates the user', $result['contents']);
    }

    public function testHoverOnNullsafePropertyFetch(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'manager_nullsafe');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$manager', $result['contents']);
        self::assertStringContainsString('User', $result['contents']);
    }

    public function testHoverOnNullsafeTypedVariableMethodCall(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'nullsafeTypedVar');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']);
        self::assertStringContainsString('Updates the user', $result['contents']);
    }

    public function testHoverOnNullsafeProtectedPropertyMethodCall(): void
    {
        $code = <<<'PHP'
<?php
class Calculator
{
    /**
     * Divides two numbers.
     */
    public function divide(int $a, int $b): float
    {
        return $a / $b;
    }
}

class Container
{
    protected ?Calculator $calc;

    public function test(): void
    {
        $this->calc?->divide(10, 2);
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 18, 'character' => 23], // On "divide"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('divide', $result['contents']);
        self::assertStringContainsString('Divides two numbers', $result['contents']);
    }

    public function testHoverOnChainedNullsafeMethodCall(): void
    {
        $code = <<<'PHP'
<?php
class Inner
{
    /**
     * Returns the value.
     */
    public function getValue(): int
    {
        return 42;
    }
}

class Middle
{
    public ?Inner $inner;
}

class Outer
{
    private ?Middle $middle;

    public function test(): void
    {
        $this->middle?->inner?->getValue();
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 23, 'character' => 33], // On "getValue"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('getValue', $result['contents']);
        self::assertStringContainsString('Returns the value', $result['contents']);
    }
}
