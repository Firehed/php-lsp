<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use Firehed\PhpLsp\Utility\ExpressionTypeResolver;
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
    use AstTestHelperTrait;
    use LoadsFixturesTrait;

    public function testResolveExpressionTypeReturnsClassNameForThis(): void
    {
        $ast = $this->parseFixtureWithParents('src/Domain/User.php');
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $result = ExpressionTypeResolver::resolveExpressionType($thisNode, $ast, null);

        self::assertInstanceOf(ClassName::class, $result);
        self::assertSame('Fixtures\Domain\User', $result->fqn);
    }

    public function testResolveExpressionTypeReturnsNullForThisOutsideClass(): void
    {
        $ast = $this->parseFixtureWithParents('src/TypeInference/EdgeCases.php');
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $result = ExpressionTypeResolver::resolveExpressionType($thisNode, $ast, null);

        self::assertNull($result);
    }

    public function testResolveExpressionTypeDelegatesToTypeResolver(): void
    {
        $ast = $this->parseFixtureWithParents('src/TypeInference/BuiltinTypes.php');
        $dtNode = self::findVariableNode('dt', $ast);

        self::assertNotNull($dtNode);

        $typeResolver = $this->createMock(TypeResolverInterface::class);
        $typeResolver->expects(self::once())
            ->method('resolveExpressionType')
            ->willReturn(new ClassName('DateTime'));

        $result = ExpressionTypeResolver::resolveExpressionType($dtNode, $ast, $typeResolver);

        self::assertInstanceOf(ClassName::class, $result);
        self::assertSame('DateTime', $result->fqn);
    }

    public function testResolveExpressionTypeReturnsNullWithoutTypeResolver(): void
    {
        $ast = $this->parseFixtureWithParents('src/TypeInference/BuiltinTypes.php');
        $dtNode = self::findVariableNode('dt', $ast);

        self::assertNotNull($dtNode);
        $result = ExpressionTypeResolver::resolveExpressionType($dtNode, $ast, null);

        self::assertNull($result);
    }

    public function testResolveExpressionTypeReturnsNullWhenTypeResolverReturnsNull(): void
    {
        $ast = $this->parseFixtureWithParents('src/TypeInference/EdgeCases.php');
        $varNode = self::findVariableNode('unknown', $ast);

        self::assertNotNull($varNode);

        $typeResolver = self::createStub(TypeResolverInterface::class);
        $typeResolver->method('resolveExpressionType')
            ->willReturn(null);

        $result = ExpressionTypeResolver::resolveExpressionType($varNode, $ast, $typeResolver);

        self::assertNull($result);
    }

    /**
     * @return array<Stmt>
     */
    private function parseFixtureWithParents(string $fixturePath): array
    {
        $code = $this->loadFixture($fixturePath);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->traverse($ast);

        return $ast;
    }
}
