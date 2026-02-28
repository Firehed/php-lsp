<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\TypeInference\PhpStanTypeInferenceService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionHandler::class)]
class CompletionHandlerTest extends TestCase
{
    private DocumentManager $documents;
    private ParserService $parser;
    private CompletionHandler $handler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $this->parser = new ParserService();
        $this->handler = new CompletionHandler($this->documents, $this->parser, null);
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

    public function testVariableMemberCompletion(): void
    {
        $documents = new DocumentManager();
        $parser = new ParserService();
        $typeInference = new PhpStanTypeInferenceService();
        $handler = new CompletionHandler($documents, $parser, null, $typeInference);

        // Use a real autoloaded class
        $code = <<<'PHP'
<?php

class Example {
    public function test(): void {
        $doc = new \Firehed\PhpLsp\Document\TextDocument('uri', 'php', 1, 'content');
        $doc->
    }
}
PHP;
        $documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 5, 'character' => 14], // After $doc->
            ],
        ]);

        $result = $handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);
        self::assertNotEmpty($result['items']);

        $labels = array_column($result['items'], 'label');
        self::assertContains('getContent', $labels);
        self::assertContains('getLine', $labels);
        self::assertContains('uri', $labels);
    }

    public function testVariableMemberCompletionWithPrefix(): void
    {
        $documents = new DocumentManager();
        $parser = new ParserService();
        $typeInference = new PhpStanTypeInferenceService();
        $handler = new CompletionHandler($documents, $parser, null, $typeInference);

        // Use a real autoloaded class
        $code = <<<'PHP'
<?php

class Example {
    public function test(): void {
        $doc = new \Firehed\PhpLsp\Document\TextDocument('uri', 'php', 1, 'content');
        $doc->get
    }
}
PHP;
        $documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 5, 'character' => 17], // After $doc->get
            ],
        ]);

        $result = $handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('items', $result);

        $labels = array_column($result['items'], 'label');
        self::assertContains('getContent', $labels);
        self::assertContains('getLine', $labels);
        // uri doesn't start with 'get'
        self::assertNotContains('uri', $labels);
    }

    public function testCompletionFallsBackWithoutTypeInference(): void
    {
        // Handler without type inference should still work for $this-> cases
        $code = <<<'PHP'
<?php
class MyClass
{
    public function greet(): string { return "Hello"; }

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
                'position' => ['line' => 7, 'character' => 15],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        $labels = array_column($result['items'], 'label');
        self::assertContains('greet', $labels);
    }

    public function testNoCompletionInsideLineComment(): void
    {
        $code = <<<'PHP'
<?php
// Call arr
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 1, 'character' => 11], // After "arr" in comment
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }

    public function testNoCompletionInsideBlockComment(): void
    {
        $code = <<<'PHP'
<?php
/* Call arr
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 1, 'character' => 11], // After "arr" in comment
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }

    public function testNoCompletionInsideString(): void
    {
        $code = <<<'PHP'
<?php
$x = "call arr
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 1, 'character' => 14], // After "arr" in string
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }

    public function testNoCompletionInsideSingleQuotedString(): void
    {
        $code = <<<'PHP'
<?php
$x = 'call arr
PHP;
        $this->documents->open('file:///test.php', 'php', 1, $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 1, 'character' => 14], // After "arr" in string
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertEmpty($result['items']);
    }
}
