<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\SignatureHelpHandler;
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

#[CoversClass(SignatureHelpHandler::class)]
class SignatureHelpHandlerTest extends TestCase
{
    use OpensDocumentsTrait;

    private DocumentManager $documents;
    private ParserService $parser;
    private DefaultClassRepository $classRepository;
    private DefaultClassInfoFactory $classInfoFactory;
    private MemberResolver $memberResolver;
    private SignatureHelpHandler $handler;
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
        $this->handler = new SignatureHelpHandler(
            $this->documents,
            $this->parser,
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
        self::assertTrue($this->handler->supports('textDocument/signatureHelp'));
        self::assertFalse($this->handler->supports('textDocument/hover'));
    }

    public function testSignatureHelpOnUserDefinedFunction(): void
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
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/signatureHelp',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 9, 'character' => 11], // Inside add(|1, 2)
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('signatures', $result);
        self::assertCount(1, $result['signatures']);
        self::assertStringContainsString('add', $result['signatures'][0]['label']);
        self::assertStringContainsString('int $a', $result['signatures'][0]['label']);
        self::assertEquals(0, $result['activeParameter']);
    }

    public function testSignatureHelpSecondParameter(): void
    {
        $code = <<<'PHP'
<?php
function greet(string $name, int $age): string
{
    return "Hello $name, age $age";
}

greet("Alice", 30);
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/signatureHelp',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 6, 'character' => 16], // After the comma: greet("Alice",| 30)
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertEquals(1, $result['activeParameter']);
    }

    public function testSignatureHelpOnBuiltinFunction(): void
    {
        $code = <<<'PHP'
<?php
$arr = [3, 1, 2];
array_map(fn($x) => $x * 2, $arr);
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/signatureHelp',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 2, 'character' => 10], // Inside array_map(|
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('array_map', $result['signatures'][0]['label']);
    }

    public function testSignatureHelpOnMethod(): void
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
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/signatureHelp',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 13, 'character' => 24], // Inside multiply(|2, 3)
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('multiply', $result['signatures'][0]['label']);
        self::assertStringContainsString('Multiplies two numbers', $result['signatures'][0]['documentation'] ?? '');
    }

    public function testSignatureHelpOnStaticMethod(): void
    {
        $code = <<<'PHP'
<?php
class Math
{
    public static function abs(int $n): int
    {
        return $n < 0 ? -$n : $n;
    }
}

$result = Math::abs(-5);
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/signatureHelp',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 9, 'character' => 19], // Inside abs(|-5)
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('abs', $result['signatures'][0]['label']);
        self::assertStringContainsString('int $n', $result['signatures'][0]['label']);
    }

    public function testSignatureHelpOnSelfStaticMethod(): void
    {
        $code = <<<'PHP'
<?php
class Calculator
{
    public static function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function useAdd(): int
    {
        return self::add(1, 2);
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/signatureHelp',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 10, 'character' => 24], // Inside self::add(|1, 2)
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('add', $result['signatures'][0]['label']);
        self::assertStringContainsString('int $a', $result['signatures'][0]['label']);
    }

    public function testSignatureHelpOnConstructor(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    public function __construct(string $name, int $age)
    {
    }
}

$user = new User("Alice", 30);
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/signatureHelp',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 8, 'character' => 17], // Inside new User(|"Alice", 30)
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('__construct', $result['signatures'][0]['label']);
        self::assertStringContainsString('string $name', $result['signatures'][0]['label']);
    }

    public function testSignatureHelpReturnsNullOutsideCall(): void
    {
        $code = '<?php $x = 1;';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/signatureHelp',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 10],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertNull($result);
    }

    public function testSignatureHelpOnTypedVariableMethodCall(): void
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
}

function useCalculator(Calculator $calc): void
{
    $calc->multiply(2, 3);
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/signatureHelp',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 14, 'character' => 21], // Inside multiply(|2, 3)
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('multiply', $result['signatures'][0]['label']);
        self::assertStringContainsString('Multiplies two numbers', $result['signatures'][0]['documentation'] ?? '');
    }

    public function testSignatureHelpOnAssignedVariableMethodCall(): void
    {
        $code = <<<'PHP'
<?php
class Greeter
{
    /**
     * Greets a person by name.
     */
    public function greet(string $name, string $greeting = 'Hello'): string
    {
        return "$greeting, $name!";
    }
}

function test(): void
{
    $greeter = new Greeter();
    $greeter->greet("World");
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/signatureHelp',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 15, 'character' => 21], // Inside greet(|"World")
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('greet', $result['signatures'][0]['label']);
        self::assertStringContainsString('Greets a person', $result['signatures'][0]['documentation'] ?? '');
    }

    public function testSignatureHelpOnNullsafeMethodCall(): void
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
}

class Container
{
    public ?Calculator $calc;

    public function test(): void
    {
        $this->calc?->multiply(2, 3);
    }
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/signatureHelp',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 18, 'character' => 31], // Inside multiply(|2, 3)
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('multiply', $result['signatures'][0]['label']);
        self::assertStringContainsString('Multiplies two numbers', $result['signatures'][0]['documentation'] ?? '');
    }

    public function testSignatureHelpOnNullsafeTypedVariableMethodCall(): void
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

function useCalculator(?Calculator $calc): void
{
    $calc?->add(1, 2);
}
PHP;
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/signatureHelp',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 14, 'character' => 17], // Inside add(|1, 2)
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('add', $result['signatures'][0]['label']);
        self::assertStringContainsString('Adds two numbers', $result['signatures'][0]['documentation'] ?? '');
    }
}
