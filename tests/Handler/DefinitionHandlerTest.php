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
}
