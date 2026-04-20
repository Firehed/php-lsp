<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScopeFinder::class)]
class ScopeFinderTest extends TestCase
{
    use AstTestHelperTrait;

    public function testFindEnclosingScopeReturnsMethodForThis(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public function myMethod(): void {
        $this->foo();
    }
}
PHP;
        $ast = self::parseWithParents($code);
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $scope = ScopeFinder::findEnclosingScope($thisNode);

        self::assertInstanceOf(Stmt\ClassMethod::class, $scope);
        self::assertSame('myMethod', $scope->name->toString());
    }

    public function testFindEnclosingScopeReturnsNullOutsideFunction(): void
    {
        $code = <<<'PHP'
<?php
$globalVar = 1;
PHP;
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('globalVar', $ast);

        self::assertNotNull($varNode);
        $scope = ScopeFinder::findEnclosingScope($varNode);

        self::assertNull($scope);
    }

    public function testFindEnclosingScopeReturnsFunction(): void
    {
        $code = <<<'PHP'
<?php
function myFunction(): void {
    $var = 1;
}
PHP;
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('var', $ast);

        self::assertNotNull($varNode);
        $scope = ScopeFinder::findEnclosingScope($varNode);

        self::assertInstanceOf(Stmt\Function_::class, $scope);
        self::assertSame('myFunction', $scope->name->toString());
    }

    public function testFindEnclosingScopeReturnsClosure(): void
    {
        $code = <<<'PHP'
<?php
$fn = function () {
    $var = 1;
};
PHP;
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('var', $ast);

        self::assertNotNull($varNode);
        $scope = ScopeFinder::findEnclosingScope($varNode);

        self::assertInstanceOf(Closure::class, $scope);
    }

    public function testFindEnclosingScopeReturnsArrowFunction(): void
    {
        $code = <<<'PHP'
<?php
$fn = fn() => $var = 1;
PHP;
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('var', $ast);

        self::assertNotNull($varNode);
        $scope = ScopeFinder::findEnclosingScope($varNode);

        self::assertInstanceOf(ArrowFunction::class, $scope);
    }

    public function testFindEnclosingClassNodeReturnsClass(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public function myMethod(): void {
        $this->foo();
    }
}
PHP;
        $ast = self::parseWithParents($code);
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $classNode = ScopeFinder::findEnclosingClassNode($thisNode);

        self::assertInstanceOf(Stmt\Class_::class, $classNode);
        self::assertNotNull($classNode->name);
        self::assertSame('MyClass', $classNode->name->toString());
    }

    public function testFindEnclosingClassNodeReturnsInterface(): void
    {
        $code = <<<'PHP'
<?php
interface MyInterface {
    public function myMethod(): void;
}
PHP;
        $ast = self::parseWithParents($code);

        $visitor = new class () extends \PhpParser\NodeVisitorAbstract {
            public ?Stmt\ClassMethod $found = null;

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\ClassMethod) {
                    $this->found = $node;
                    return NodeTraverser::STOP_TRAVERSAL;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        self::assertNotNull($visitor->found);
        $classNode = ScopeFinder::findEnclosingClassNode($visitor->found);

        self::assertInstanceOf(Stmt\Interface_::class, $classNode);
    }

    public function testFindEnclosingClassNodeReturnsTrait(): void
    {
        $code = <<<'PHP'
<?php
trait MyTrait {
    public function myMethod(): void {
        $var = 1;
    }
}
PHP;
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('var', $ast);

        self::assertNotNull($varNode);
        $classNode = ScopeFinder::findEnclosingClassNode($varNode);

        self::assertInstanceOf(Stmt\Trait_::class, $classNode);
    }

    public function testFindEnclosingClassNodeReturnsEnum(): void
    {
        $code = <<<'PHP'
<?php
enum Status: string {
    case Active = 'active';

    public function label(): string {
        $var = 1;
        return $this->value;
    }
}
PHP;
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('var', $ast);

        self::assertNotNull($varNode);
        $classNode = ScopeFinder::findEnclosingClassNode($varNode);

        self::assertInstanceOf(Stmt\Enum_::class, $classNode);
    }

    public function testFindEnclosingClassNodeReturnsNullOutsideClass(): void
    {
        $code = <<<'PHP'
<?php
function globalFunc(): void {
    $var = 1;
}
PHP;
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('var', $ast);

        self::assertNotNull($varNode);
        $classNode = ScopeFinder::findEnclosingClassNode($varNode);

        self::assertNull($classNode);
    }

    public function testFindEnclosingClassNameReturnsShortName(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public function myMethod(): void {
        $this->foo();
    }
}
PHP;
        $ast = self::parseWithParents($code);
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $className = ScopeFinder::findEnclosingClassName($thisNode);

        self::assertSame('MyClass', $className);
    }

    public function testFindEnclosingClassNameReturnsFqn(): void
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
        $className = ScopeFinder::findEnclosingClassName($thisNode);

        self::assertSame('App\Models\User', $className);
    }

    public function testFindEnclosingClassNameReturnsNullOutsideClass(): void
    {
        $code = <<<'PHP'
<?php
function globalFunc(): void {
    $var = 1;
}
PHP;
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('var', $ast);

        self::assertNotNull($varNode);
        $className = ScopeFinder::findEnclosingClassName($varNode);

        self::assertNull($className);
    }

    public function testFindEnclosingClassNameReturnsNullForAnonymousClass(): void
    {
        $code = <<<'PHP'
<?php
$obj = new class {
    public function myMethod(): void {
        $var = 1;
    }
};
PHP;
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('var', $ast);

        self::assertNotNull($varNode);
        $className = ScopeFinder::findEnclosingClassName($varNode);

        self::assertNull($className);
    }

    public function testNodeContainsLineReturnsTrueWhenLineInRange(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public function test(): void {}
}
PHP;
        $ast = self::parseWithParents($code);
        $classNode = $ast[0];
        self::assertInstanceOf(Stmt\Class_::class, $classNode);

        // Line 1 (0-indexed) is inside the class (lines 2-4 in 1-indexed)
        self::assertTrue(ScopeFinder::nodeContainsLine($classNode, 1));
        self::assertTrue(ScopeFinder::nodeContainsLine($classNode, 2));
        self::assertTrue(ScopeFinder::nodeContainsLine($classNode, 3));
    }

    public function testNodeContainsLineReturnsFalseWhenLineOutsideRange(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
}
PHP;
        $ast = self::parseWithParents($code);
        $classNode = $ast[0];
        self::assertInstanceOf(Stmt\Class_::class, $classNode);

        // Line 0 is <?php, before the class
        self::assertFalse(ScopeFinder::nodeContainsLine($classNode, 0));
        // Line 10 is well after the class ends
        self::assertFalse(ScopeFinder::nodeContainsLine($classNode, 10));
    }

    public function testFindClassAtLineReturnsClassContainingLine(): void
    {
        $code = <<<'PHP'
<?php
class First {}

class Second {
    public function test(): void {}
}
PHP;
        $ast = self::parseWithParents($code);

        // Line 4 (0-indexed) is inside Second class
        $class = ScopeFinder::findClassAtLine($ast, 4);
        self::assertNotNull($class);
        self::assertSame('Second', $class->name?->toString());
    }

    public function testFindClassAtLineReturnsNullWhenNotInClass(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {}

$var = 1;
PHP;
        $ast = self::parseWithParents($code);

        // Line 3 (0-indexed) is after the class
        $class = ScopeFinder::findClassAtLine($ast, 3);
        self::assertNull($class);
    }
}
