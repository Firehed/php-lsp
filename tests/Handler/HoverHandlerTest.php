<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Handler\HoverHandler;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\TypeInference\PhpStanTypeInferenceService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HoverHandler::class)]
class HoverHandlerTest extends TestCase
{
    private DocumentManager $documents;
    private ParserService $parser;
    private HoverHandler $handler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $this->parser = new ParserService();
        $this->handler = new HoverHandler($this->documents, $this->parser, null);
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

    public function testHoverOnVariableShowsInferredType(): void
    {
        $documents = new DocumentManager();
        $parser = new ParserService();
        $typeInference = new PhpStanTypeInferenceService();
        $handler = new HoverHandler($documents, $parser, null, $typeInference);

        // Use a real autoloaded class
        $code = <<<'PHP'
<?php

class Example {
    public function test(): void {
        $doc = new \Firehed\PhpLsp\Document\TextDocument('uri', 'php', 1, 'content');
        echo $doc->uri;
    }
}
PHP;
        $documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 5, 'character' => 14], // On "$doc"
            ],
        ]);

        $result = $handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('contents', $result);
        self::assertStringContainsString('TextDocument', $result['contents']);
    }

    public function testHoverOnMethodCallOnVariable(): void
    {
        $documents = new DocumentManager();
        $parser = new ParserService();
        $typeInference = new PhpStanTypeInferenceService();
        $handler = new HoverHandler($documents, $parser, null, $typeInference);

        // Use a real autoloaded class
        $code = <<<'PHP'
<?php

class Example {
    public function test(): void {
        $doc = new \Firehed\PhpLsp\Document\TextDocument('uri', 'php', 1, 'content');
        $content = $doc->getContent();
    }
}
PHP;
        $documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 5, 'character' => 25], // On "getContent"
            ],
        ]);

        $result = $handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('contents', $result);
        self::assertStringContainsString('getContent', $result['contents']);
        self::assertStringContainsString('string', $result['contents']);
    }

    public function testHoverFallsBackWithoutTypeInference(): void
    {
        // Handler without type inference should still work for $this-> cases
        $code = <<<'PHP'
<?php
class Calculator
{
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
                'position' => ['line' => 10, 'character' => 16], // On "multiply"
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertStringContainsString('multiply', $result['contents']);
    }
}
