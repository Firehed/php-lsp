<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use Firehed\PhpLsp\Utility\ExpressionTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpressionTypeResolver::class)]
class ExpressionTypeResolverTest extends TestCase
{
    use AstTestHelperTrait;

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

        self::assertInstanceOf(ClassName::class, $result);
        self::assertSame('App\Models\User', $result->fqn);
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
            // @phpstan-ignore argument.type (test class doesn't exist)
            ->willReturn(new ClassName('App\Models\User'));

        $result = ExpressionTypeResolver::resolveExpressionType($userNode, $ast, $typeResolver);

        self::assertInstanceOf(ClassName::class, $result);
        self::assertSame('App\Models\User', $result->fqn);
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
