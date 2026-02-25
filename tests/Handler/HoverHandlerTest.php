<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\HoverHandler;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\RequestMessage;
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
}
