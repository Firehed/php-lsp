<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Utility\NodeAtPosition;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NodeAtPosition::class)]
class NodeAtPositionTest extends TestCase
{
    private ParserService $parser;

    protected function setUp(): void
    {
        $this->parser = new ParserService();
    }

    public function testFindClassName(): void
    {
        $code = '<?php new MyClass();';
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        // Position on "MyClass" (line 0, char 10)
        $finder = new NodeAtPosition();
        $node = $finder->find($ast, $doc->offsetAt(0, 10));

        self::assertInstanceOf(Name::class, $node);
        self::assertSame('MyClass', $node->toString());
    }

    public function testFindMethodName(): void
    {
        $code = '<?php $obj->doSomething();';
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        // Position on "doSomething" (line 0, char 12)
        $finder = new NodeAtPosition();
        $node = $finder->find($ast, $doc->offsetAt(0, 12));

        // Returns the Identifier node for the method name
        self::assertInstanceOf(Identifier::class, $node);
        self::assertSame('doSomething', $node->toString());
    }

    public function testFindStaticMethodCall(): void
    {
        $code = '<?php MyClass::staticMethod();';
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        // Position on "MyClass" (line 0, char 8)
        $finder = new NodeAtPosition();
        $node = $finder->find($ast, $doc->offsetAt(0, 8));

        self::assertInstanceOf(Name::class, $node);
        self::assertSame('MyClass', $node->toString());
    }

    public function testReturnsNullOutsideCode(): void
    {
        $code = '<?php // comment';
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $finder = new NodeAtPosition();
        $node = $finder->find($ast, $doc->offsetAt(0, 10));

        self::assertNull($node);
    }

    public function testFilterNarrowsResult(): void
    {
        $code = '<?php $obj->doSomething();';
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $finder = new NodeAtPosition();

        // Without filter: returns the deepest node (Identifier for method name)
        $node = $finder->find($ast, $doc->offsetAt(0, 12));
        self::assertInstanceOf(Identifier::class, $node, 'without filter, deepest node is returned');

        // With filter: returns the deepest node matching the filter
        $node = $finder->find($ast, $doc->offsetAt(0, 12), fn (Node $n) => $n instanceof Node\Expr\MethodCall);
        self::assertInstanceOf(
            Node\Expr\MethodCall::class,
            $node,
            'filter restricts to MethodCall',
        );
    }

    public function testFilterReturnsNullWhenNoMatch(): void
    {
        $code = '<?php $obj->doSomething();';
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);
        $ast = $this->parser->parse($doc);
        self::assertNotNull($ast);

        $finder = new NodeAtPosition();
        $node = $finder->find($ast, $doc->offsetAt(0, 12), fn (Node $n) => $n instanceof Node\Expr\StaticCall);
        self::assertNull($node, 'filter that matches nothing returns null');
    }
}
