<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\SignatureHelpHandler;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\RequestMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignatureHelpHandler::class)]
class SignatureHelpHandlerTest extends TestCase
{
    private DocumentManager $documents;
    private ParserService $parser;
    private SignatureHelpHandler $handler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $this->parser = new ParserService();
        $this->handler = new SignatureHelpHandler($this->documents, $this->parser, null);
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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
        $this->documents->open('file:///test.php', 'php', 1, $code);

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
}
