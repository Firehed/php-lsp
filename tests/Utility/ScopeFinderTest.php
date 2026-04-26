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

    public function testResolveExtendsNameReturnsNullWhenNoExtends(): void
    {
        $code = '<?php class MyClass {}';
        $ast = self::parseWithParents($code);
        $class = $ast[0];
        self::assertInstanceOf(Stmt\Class_::class, $class);

        self::assertNull(ScopeFinder::resolveExtendsName($class));
    }

    public function testResolveExtendsNameReturnsParentName(): void
    {
        $code = '<?php class Child extends ParentClass {}';
        $ast = self::parseWithParents($code);
        $class = $ast[0];
        self::assertInstanceOf(Stmt\Class_::class, $class);

        self::assertSame('ParentClass', ScopeFinder::resolveExtendsName($class));
    }

    public function testResolveExtendsNameUsesResolvedNameWhenAvailable(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
use Other\ParentClass;
class Child extends ParentClass {}
PHP;
        $ast = self::parseWithParents($code);
        $namespace = $ast[0];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $class = $namespace->stmts[1];
        self::assertInstanceOf(Stmt\Class_::class, $class);

        self::assertSame('Other\ParentClass', ScopeFinder::resolveExtendsName($class));
    }

    public function testResolveNameReturnsRawNameWhenNoResolvedAttribute(): void
    {
        $code = '<?php class Foo extends Bar {}';
        $ast = self::parseWithParents($code);
        $class = $ast[0];
        self::assertInstanceOf(Stmt\Class_::class, $class);
        self::assertNotNull($class->extends);

        self::assertSame('Bar', ScopeFinder::resolveName($class->extends));
    }

    public function testResolveNameUsesResolvedNameWhenAvailable(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
use Other\Bar;
class Foo extends Bar {}
PHP;
        $ast = self::parseWithParents($code);
        $namespace = $ast[0];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $class = $namespace->stmts[1];
        self::assertInstanceOf(Stmt\Class_::class, $class);
        self::assertNotNull($class->extends);

        self::assertSame('Other\Bar', ScopeFinder::resolveName($class->extends));
    }

    public function testIterateTopLevelStatementsYieldsStatementsDirectly(): void
    {
        $code = <<<'PHP'
<?php
class First {}
class Second {}
function myFunc() {}
PHP;
        $ast = self::parseWithParents($code);

        $statements = iterator_to_array(ScopeFinder::iterateTopLevelStatements($ast));

        self::assertCount(3, $statements);
        self::assertInstanceOf(Stmt\Class_::class, $statements[0]);
        self::assertInstanceOf(Stmt\Class_::class, $statements[1]);
        self::assertInstanceOf(Stmt\Function_::class, $statements[2]);
    }

    public function testIterateTopLevelStatementsFlattensNamespace(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class First {}
class Second {}
PHP;
        $ast = self::parseWithParents($code);

        $statements = iterator_to_array(ScopeFinder::iterateTopLevelStatements($ast));

        self::assertCount(2, $statements);
        self::assertInstanceOf(Stmt\Class_::class, $statements[0]);
        self::assertSame('First', $statements[0]->name?->toString());
        self::assertInstanceOf(Stmt\Class_::class, $statements[1]);
        self::assertSame('Second', $statements[1]->name?->toString());
    }

    public function testFindFunctionReturnsNullWhenNotFound(): void
    {
        $code = <<<'PHP'
<?php
function other(): void {}
PHP;
        $ast = self::parseWithParents($code);

        self::assertNull(ScopeFinder::findFunction('nonexistent', $ast));
    }

    public function testFindFunctionReturnsMatchingFunction(): void
    {
        $code = <<<'PHP'
<?php
function first(): void {}
function second(): int { return 1; }
function third(): string { return ''; }
PHP;
        $ast = self::parseWithParents($code);

        $found = ScopeFinder::findFunction('second', $ast);

        self::assertNotNull($found);
        self::assertSame('second', $found->name->toString());
    }

    public function testFindFunctionWorksWithNamespace(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Utils;

function helper(): void {}
PHP;
        $ast = self::parseWithParents($code);

        $found = ScopeFinder::findFunction('helper', $ast);

        self::assertNotNull($found);
        self::assertSame('helper', $found->name->toString());
    }

    public function testResolveClassNameDelegatesToResolveName(): void
    {
        $code = <<<'PHP'
<?php
namespace App;
use Other\Bar;
class Foo extends Bar {}
PHP;
        $ast = self::parseWithParents($code);
        $namespace = $ast[0];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $class = $namespace->stmts[1];
        self::assertInstanceOf(Stmt\Class_::class, $class);
        self::assertNotNull($class->extends);

        self::assertSame('Other\Bar', ScopeFinder::resolveClassName($class->extends));
    }

    public function testGetClassLikeNameReturnsNamespacedName(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Models;

class User {}
PHP;
        $ast = self::parseWithParents($code);
        $namespace = $ast[0];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $class = $namespace->stmts[0];
        self::assertInstanceOf(Stmt\Class_::class, $class);

        self::assertSame('App\Models\User', ScopeFinder::getClassLikeName($class));
    }

    public function testGetClassLikeNameReturnsShortNameWhenNoNamespace(): void
    {
        $code = '<?php class MyClass {}';
        $ast = self::parseWithParents($code);
        $class = $ast[0];
        self::assertInstanceOf(Stmt\Class_::class, $class);

        self::assertSame('MyClass', ScopeFinder::getClassLikeName($class));
    }

    public function testGetClassLikeNameReturnsNullForAnonymousClass(): void
    {
        $code = <<<'PHP'
<?php
$obj = new class {
    public function test(): void {}
};
PHP;
        $ast = self::parseWithParents($code);

        $visitor = new class () extends \PhpParser\NodeVisitorAbstract {
            public ?Stmt\Class_ $found = null;

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Class_ && $node->name === null) {
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
        self::assertNull(ScopeFinder::getClassLikeName($visitor->found));
    }

    public function testGetClassLikeNameWorksWithInterface(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Contracts;

interface Renderable {}
PHP;
        $ast = self::parseWithParents($code);
        $namespace = $ast[0];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $interface = $namespace->stmts[0];
        self::assertInstanceOf(Stmt\Interface_::class, $interface);

        self::assertSame('App\Contracts\Renderable', ScopeFinder::getClassLikeName($interface));
    }

    public function testGetClassLikeNameWorksWithTrait(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Concerns;

trait Loggable {}
PHP;
        $ast = self::parseWithParents($code);
        $namespace = $ast[0];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $trait = $namespace->stmts[0];
        self::assertInstanceOf(Stmt\Trait_::class, $trait);

        self::assertSame('App\Concerns\Loggable', ScopeFinder::getClassLikeName($trait));
    }

    public function testGetClassLikeNameWorksWithEnum(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Enums;

enum Status: string {
    case Active = 'active';
}
PHP;
        $ast = self::parseWithParents($code);
        $namespace = $ast[0];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $enum = $namespace->stmts[0];
        self::assertInstanceOf(Stmt\Enum_::class, $enum);

        self::assertSame('App\Enums\Status', ScopeFinder::getClassLikeName($enum));
    }
}
