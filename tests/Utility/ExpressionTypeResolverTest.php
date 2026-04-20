<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use Firehed\PhpLsp\Utility\ExpressionTypeResolver;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpressionTypeResolver::class)]
class ExpressionTypeResolverTest extends TestCase
{
    /**
     * @return array<Stmt>
     */
    private static function parseWithParents(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->traverse($ast);

        return $ast;
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function findVariableNode(string $name, array $ast): ?Variable
    {
        $visitor = new class ($name) extends \PhpParser\NodeVisitorAbstract {
            public ?Variable $found = null;

            public function __construct(private readonly string $name)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Variable && $node->name === $this->name) {
                    $this->found = $node;
                    return NodeTraverser::STOP_TRAVERSAL;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->found;
    }

    public function testResolveExpressionTypeReturnsClassNameForThis(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Models;

class User {
    public function getName(): string {
        $this->name;
    }
}
PHP;
        $ast = self::parseWithParents($code);
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $result = ExpressionTypeResolver::resolveExpressionType($thisNode, $ast, null);

        self::assertSame('App\Models\User', $result);
    }

    public function testResolveExpressionTypeReturnsNullForThisOutsideClass(): void
    {
        $code = <<<'PHP'
<?php
function globalFunc(): void {
    $this->foo();
}
PHP;
        $ast = self::parseWithParents($code);
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $result = ExpressionTypeResolver::resolveExpressionType($thisNode, $ast, null);

        self::assertNull($result);
    }

    public function testResolveExpressionTypeDelegatesToTypeResolver(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public function myMethod(): void {
        $user->getName();
    }
}
PHP;
        $ast = self::parseWithParents($code);
        $userNode = self::findVariableNode('user', $ast);

        self::assertNotNull($userNode);

        $typeResolver = $this->createMock(TypeResolverInterface::class);
        $typeResolver->expects(self::once())
            ->method('resolveExpressionType')
            ->willReturn('App\Models\User');

        $result = ExpressionTypeResolver::resolveExpressionType($userNode, $ast, $typeResolver);

        self::assertSame('App\Models\User', $result);
    }

    public function testResolveExpressionTypeReturnsNullWithoutTypeResolver(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public function myMethod(): void {
        $user->getName();
    }
}
PHP;
        $ast = self::parseWithParents($code);
        $userNode = self::findVariableNode('user', $ast);

        self::assertNotNull($userNode);
        $result = ExpressionTypeResolver::resolveExpressionType($userNode, $ast, null);

        self::assertNull($result);
    }

    public function testResolveExpressionTypeReturnsNullWhenTypeResolverReturnsNull(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public function myMethod(): void {
        $unknown->foo();
    }
}
PHP;
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('unknown', $ast);

        self::assertNotNull($varNode);

        $typeResolver = self::createStub(TypeResolverInterface::class);
        $typeResolver->method('resolveExpressionType')
            ->willReturn(null);

        $result = ExpressionTypeResolver::resolveExpressionType($varNode, $ast, $typeResolver);

        self::assertNull($result);
    }
}
