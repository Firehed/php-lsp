<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Resolution\EnclosingClassResolver;
use Firehed\PhpLsp\Resolution\TextFallbackHelper;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnclosingClassResolver::class)]
final class EnclosingClassResolverTest extends TestCase
{
    private EnclosingClassResolver $resolver;
    private ParserService $parser;

    protected function setUp(): void
    {
        $this->parser = new ParserService();
        $this->resolver = new EnclosingClassResolver(new TextFallbackHelper());
    }

    public function testForNodeReadsParentChainWhenAttached(): void
    {
        $document = $this->document(<<<'PHP'
        <?php
        namespace App;
        class Widget {
            public function trigger(): void {
                $this;
            }
        }
        PHP);
        $ast = $this->parser->parse($document);
        self::assertNotNull($ast);
        $thisNode = (new NodeFinder())->findFirst($ast, fn (Node $n): bool =>
            $n instanceof Variable && $n->name === 'this'
        );
        self::assertInstanceOf(Variable::class, $thisNode);

        $enclosing = $this->resolver->forNode($thisNode, $ast, $document);

        self::assertSame('App\\Widget', $enclosing, 'parent chain resolves the enclosing class');
    }

    public function testForNodeFallsBackToTextWhenParentChainIsDetached(): void
    {
        $document = $this->document(<<<'PHP'
        <?php
        namespace App;
        class Widget {
            public function trigger(): void {
                // cursor line
            }
        }
        PHP);
        $ast = $this->parser->parse($document);
        self::assertNotNull($ast);

        // A synthetic $this Variable — no parent chain, but seeded with a
        // document position inside the class body (line 4, zero-based).
        $synthetic = new Variable('this');
        $synthetic->setAttribute('startLine', 5);
        $synthetic->setAttribute('startFilePos', $document->offsetAt(4, 0));

        $enclosing = $this->resolver->forNode($synthetic, $ast, $document);

        self::assertSame(
            'App\\Widget',
            $enclosing,
            'a detached node with a document position falls back to the text-based scan',
        );
    }

    public function testForNodeReturnsNullWhenBothPathsFail(): void
    {
        $document = $this->document('<?php $x = 1;');
        $ast = $this->parser->parse($document);
        self::assertNotNull($ast);

        $synthetic = new Variable('this'); // no parent, no valid startFilePos

        self::assertNull(
            $this->resolver->forNode($synthetic, $ast, $document),
            'without a parent chain and without a document position, the resolver gives up',
        );
    }

    private function document(string $code): TextDocument
    {
        return new TextDocument('file:///t.php', 'php', 1, $code);
    }
}
