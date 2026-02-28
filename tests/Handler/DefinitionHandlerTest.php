<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Handler\DefinitionHandler;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\TypeInference\PhpStanTypeInferenceService;
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

    public function testGoToDefinitionForMethodCallOnVariable(): void
    {
        $documents = new DocumentManager();
        $parser = new ParserService();
        $index = new SymbolIndex();
        $typeInference = new PhpStanTypeInferenceService();

        // Use the project root to enable ComposerClassLocator
        $projectRoot = dirname(__DIR__, 2);
        $classLocator = new ComposerClassLocator($projectRoot);

        $handler = new DefinitionHandler($documents, $parser, $index, $classLocator, $typeInference);

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
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 5, 'character' => 25], // On "getContent"
            ],
        ]);

        $result = $handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('uri', $result);
        self::assertStringContainsString('TextDocument.php', $result['uri']);
        self::assertArrayHasKey('range', $result);
    }

    public function testGoToDefinitionForPropertyFetchOnVariable(): void
    {
        $documents = new DocumentManager();
        $parser = new ParserService();
        $index = new SymbolIndex();
        $typeInference = new PhpStanTypeInferenceService();

        $projectRoot = dirname(__DIR__, 2);
        $classLocator = new ComposerClassLocator($projectRoot);

        $handler = new DefinitionHandler($documents, $parser, $index, $classLocator, $typeInference);

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
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 5, 'character' => 20], // On "uri"
            ],
        ]);

        $result = $handler->handle($request);

        self::assertIsArray($result);
        self::assertArrayHasKey('uri', $result);
        self::assertStringContainsString('TextDocument.php', $result['uri']);
    }

    public function testDefinitionFallsBackWithoutTypeInference(): void
    {
        // Handler without type inference should still work for class references
        $classCode = '<?php class TestClass {}';
        $this->documents->open('file:///TestClass.php', 'php', 1, $classCode);
        $classDoc = $this->documents->get('file:///TestClass.php');
        self::assertNotNull($classDoc);
        $ast = $this->parser->parse($classDoc);
        self::assertNotNull($ast);
        $symbols = (new SymbolExtractor())->extract($classDoc, $ast);
        foreach ($symbols as $symbol) {
            $this->index->add($symbol);
        }

        $usageCode = '<?php new TestClass();';
        $this->documents->open('file:///usage.php', 'php', 1, $usageCode);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/definition',
            'params' => [
                'textDocument' => ['uri' => 'file:///usage.php'],
                'position' => ['line' => 0, 'character' => 10],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertIsArray($result);
        self::assertSame('file:///TestClass.php', $result['uri']);
    }
}
