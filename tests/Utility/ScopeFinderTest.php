<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
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
    use LoadsFixturesTrait;

    public function testFindEnclosingScopeReturnsMethodForThis(): void
    {
        $code = $this->loadFixture('src/Utility/ScopePatterns.php');
        $ast = self::parseWithParents($code);
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $scope = ScopeFinder::findEnclosingScope($thisNode);

        self::assertInstanceOf(Stmt\ClassMethod::class, $scope);
        self::assertSame('methodWithThis', $scope->name->toString());
    }

    public function testFindEnclosingScopeReturnsNullOutsideFunction(): void
    {
        $code = $this->loadFixture('src/Utility/GlobalScope.php');
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('globalVar', $ast);

        self::assertNotNull($varNode);
        $scope = ScopeFinder::findEnclosingScope($varNode);

        self::assertNull($scope);
    }

    public function testFindEnclosingScopeReturnsFunction(): void
    {
        $code = $this->loadFixture('src/Utility/GlobalScope.php');
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('functionVar', $ast);

        self::assertNotNull($varNode);
        $scope = ScopeFinder::findEnclosingScope($varNode);

        self::assertInstanceOf(Stmt\Function_::class, $scope);
        self::assertSame('utilityFunction', $scope->name->toString());
    }

    public function testFindEnclosingScopeReturnsClosure(): void
    {
        $code = $this->loadFixture('src/Utility/ScopePatterns.php');
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('closureVar', $ast);

        self::assertNotNull($varNode);
        $scope = ScopeFinder::findEnclosingScope($varNode);

        self::assertInstanceOf(Closure::class, $scope);
    }

    public function testFindEnclosingScopeReturnsArrowFunction(): void
    {
        $code = $this->loadFixture('src/Utility/ScopePatterns.php');
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('arrowVar', $ast);

        self::assertNotNull($varNode);
        $scope = ScopeFinder::findEnclosingScope($varNode);

        self::assertInstanceOf(ArrowFunction::class, $scope);
    }

    public function testFindEnclosingClassNodeReturnsClass(): void
    {
        $code = $this->loadFixture('src/Utility/ScopePatterns.php');
        $ast = self::parseWithParents($code);
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $classNode = ScopeFinder::findEnclosingClassNode($thisNode);

        self::assertInstanceOf(Stmt\Class_::class, $classNode);
        self::assertNotNull($classNode->name);
        self::assertSame('ScopePatterns', $classNode->name->toString());
    }

    public function testFindEnclosingClassNodeReturnsInterface(): void
    {
        $code = $this->loadFixture('src/Domain/Entity.php');
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
        $code = $this->loadFixture('src/Traits/HasTimestamps.php');
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('this', $ast);

        self::assertNotNull($varNode);
        $classNode = ScopeFinder::findEnclosingClassNode($varNode);

        self::assertInstanceOf(Stmt\Trait_::class, $classNode);
    }

    public function testFindEnclosingClassNodeReturnsEnum(): void
    {
        $code = $this->loadFixture('src/Enum/Status.php');
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('this', $ast);

        self::assertNotNull($varNode);
        $classNode = ScopeFinder::findEnclosingClassNode($varNode);

        self::assertInstanceOf(Stmt\Enum_::class, $classNode);
    }

    public function testFindEnclosingClassNodeReturnsNullOutsideClass(): void
    {
        $code = $this->loadFixture('src/Utility/GlobalScope.php');
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('functionVar', $ast);

        self::assertNotNull($varNode);
        $classNode = ScopeFinder::findEnclosingClassNode($varNode);

        self::assertNull($classNode);
    }

    public function testFindEnclosingClassNameReturnsShortName(): void
    {
        $code = $this->loadFixture('TypeInference/GlobalFunction.php');
        $ast = self::parseWithParents($code);
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $className = ScopeFinder::findEnclosingClassName($thisNode);

        self::assertSame('GlobalConfig', $className);
    }

    public function testFindEnclosingClassNameReturnsFqn(): void
    {
        $code = $this->loadFixture('src/Domain/User.php');
        $ast = self::parseWithParents($code);
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $className = ScopeFinder::findEnclosingClassName($thisNode);

        self::assertSame('Fixtures\Domain\User', $className);
    }

    public function testFindEnclosingClassNameReturnsNullOutsideClass(): void
    {
        $code = $this->loadFixture('src/Utility/GlobalScope.php');
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('functionVar', $ast);

        self::assertNotNull($varNode);
        $className = ScopeFinder::findEnclosingClassName($varNode);

        self::assertNull($className);
    }

    public function testFindEnclosingClassNameReturnsNullForAnonymousClass(): void
    {
        $code = $this->loadFixture('src/Utility/AnonymousClassScope.php');
        $ast = self::parseWithParents($code);
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $className = ScopeFinder::findEnclosingClassName($thisNode);

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
        $code = $this->loadFixture('src/Mixed/MultipleClasses.php');
        $ast = self::parseWithParents($code);

        $statements = iterator_to_array(ScopeFinder::iterateTopLevelStatements($ast));

        self::assertGreaterThanOrEqual(2, count($statements));
        self::assertInstanceOf(Stmt\Class_::class, $statements[0]);
        self::assertSame('First', $statements[0]->name?->toString());
        self::assertInstanceOf(Stmt\Class_::class, $statements[1]);
        self::assertSame('Second', $statements[1]->name?->toString());
    }

    public function testFindFunctionReturnsNullWhenNotFound(): void
    {
        $code = $this->loadFixture('src/Utility/GlobalScope.php');
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
        $code = $this->loadFixture('src/Utility/GlobalScope.php');
        $ast = self::parseWithParents($code);

        $found = ScopeFinder::findFunction('utilityFunction', $ast);

        self::assertNotNull($found);
        self::assertSame('utilityFunction', $found->name->toString());
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
        $code = $this->loadFixture('src/Domain/User.php');
        $ast = self::parseWithParents($code);
        $namespace = $ast[1];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $class = self::findFirstClassLike($namespace->stmts, Stmt\Class_::class);
        self::assertNotNull($class);

        self::assertSame('Fixtures\Domain\User', ScopeFinder::getClassLikeName($class));
    }

    public function testGetClassLikeNameReturnsShortNameWhenNoNamespace(): void
    {
        $code = $this->loadFixture('TypeInference/GlobalFunction.php');
        $ast = self::parseWithParents($code);
        $class = self::findFirstClassLike($ast, Stmt\Class_::class);
        self::assertNotNull($class);

        self::assertSame('GlobalConfig', ScopeFinder::getClassLikeName($class));
    }

    public function testGetClassLikeNameReturnsNullForAnonymousClass(): void
    {
        $code = $this->loadFixture('src/Utility/AnonymousClassScope.php');
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
        $code = $this->loadFixture('src/Domain/Entity.php');
        $ast = self::parseWithParents($code);
        $namespace = $ast[1];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $interface = self::findFirstClassLike($namespace->stmts, Stmt\Interface_::class);
        self::assertNotNull($interface);

        self::assertSame('Fixtures\Domain\Entity', ScopeFinder::getClassLikeName($interface));
    }

    public function testGetClassLikeNameWorksWithTrait(): void
    {
        $code = $this->loadFixture('src/Traits/HasTimestamps.php');
        $ast = self::parseWithParents($code);
        $namespace = $ast[1];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $trait = self::findFirstClassLike($namespace->stmts, Stmt\Trait_::class);
        self::assertNotNull($trait);

        self::assertSame('Fixtures\Traits\HasTimestamps', ScopeFinder::getClassLikeName($trait));
    }

    public function testGetClassLikeNameWorksWithEnum(): void
    {
        $code = $this->loadFixture('src/Enum/Status.php');
        $ast = self::parseWithParents($code);
        $namespace = $ast[1];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $enum = self::findFirstClassLike($namespace->stmts, Stmt\Enum_::class);
        self::assertNotNull($enum);

        self::assertSame('Fixtures\Enum\Status', ScopeFinder::getClassLikeName($enum));
    }

    public function testResolveClassNameInContextResolvesSelf(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass {
    public static function test(): void {
        self::method();
    }
}
PHP;
        $ast = self::parseWithParents($code);
        $selfName = self::findNameNode('self', $ast);

        self::assertNotNull($selfName);
        $resolved = ScopeFinder::resolveClassNameInContext($selfName, $selfName);

        self::assertSame('App\MyClass', $resolved);
    }

    public function testResolveClassNameInContextResolvesStatic(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass {
    public static function test(): void {
        static::method();
    }
}
PHP;
        $ast = self::parseWithParents($code);
        $staticName = self::findNameNode('static', $ast);

        self::assertNotNull($staticName);
        $resolved = ScopeFinder::resolveClassNameInContext($staticName, $staticName);

        self::assertSame('App\MyClass', $resolved);
    }

    public function testResolveClassNameInContextResolvesParent(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Other\BaseClass;

class MyClass extends BaseClass {
    public static function test(): void {
        parent::method();
    }
}
PHP;
        $ast = self::parseWithParents($code);
        $parentName = self::findNameNode('parent', $ast);

        self::assertNotNull($parentName);
        $resolved = ScopeFinder::resolveClassNameInContext($parentName, $parentName);

        self::assertSame('Other\BaseClass', $resolved);
    }

    public function testResolveClassNameInContextResolvesRegularClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass {
    public static function test(): void {
        \Other\SomeClass::method();
    }
}
PHP;
        $ast = self::parseWithParents($code);
        // Find the StaticCall and get its class Name node
        $staticCall = self::findStaticCallNode('method', $ast);
        self::assertNotNull($staticCall);
        self::assertInstanceOf(Node\Name::class, $staticCall->class);

        $resolved = ScopeFinder::resolveClassNameInContext($staticCall->class, $staticCall);

        self::assertSame('Other\SomeClass', $resolved);
    }

    public function testResolveClassNameInContextReturnsNullForSelfOutsideClass(): void
    {
        $code = <<<'PHP'
<?php
function test(): void {
    self::method();
}
PHP;
        $ast = self::parseWithParents($code);
        $selfName = self::findNameNode('self', $ast);

        self::assertNotNull($selfName);
        $resolved = ScopeFinder::resolveClassNameInContext($selfName, $selfName);

        self::assertNull($resolved);
    }

    public function testResolveClassNameInContextReturnsNullForParentWithNoExtends(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public static function test(): void {
        parent::method();
    }
}
PHP;
        $ast = self::parseWithParents($code);
        $parentName = self::findNameNode('parent', $ast);

        self::assertNotNull($parentName);
        $resolved = ScopeFinder::resolveClassNameInContext($parentName, $parentName);

        self::assertNull($resolved);
    }

    public function testResolveClassNameInContextReturnsNullForParentInInterface(): void
    {
        $code = <<<'PHP'
<?php
interface MyInterface {
    public static function test(): void;
}
PHP;
        $ast = self::parseWithParents($code);
        // Create a fake parent Name node to test this edge case
        $name = new Node\Name('parent');

        // Find the interface method to get a context node
        $methodNode = self::findMethodNode('test', $ast);
        self::assertNotNull($methodNode);

        // Manually set parent attribute
        $name->setAttribute('parent', $methodNode);

        $resolved = ScopeFinder::resolveClassNameInContext($name, $name);

        self::assertNull($resolved);
    }

    /**
     * @template T of Stmt\ClassLike
     * @param array<Stmt> $stmts
     * @param class-string<T> $type
     * @return T|null
     */
    private static function findFirstClassLike(array $stmts, string $type): ?Stmt\ClassLike
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof $type) {
                return $stmt;
            }
        }
        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function findNameNode(string $name, array $ast): ?Node\Name
    {
        $visitor = new class ($name) extends \PhpParser\NodeVisitorAbstract {
            public ?Node\Name $found = null;

            public function __construct(private readonly string $name)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Name && $node->toString() === $this->name) {
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

    /**
     * @param array<Stmt> $ast
     */
    private static function findMethodNode(string $name, array $ast): ?Stmt\ClassMethod
    {
        $visitor = new class ($name) extends \PhpParser\NodeVisitorAbstract {
            public ?Stmt\ClassMethod $found = null;

            public function __construct(private readonly string $name)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\ClassMethod && $node->name->toString() === $this->name) {
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

    /**
     * @param array<Stmt> $ast
     */
    private static function findStaticCallNode(string $methodName, array $ast): ?Node\Expr\StaticCall
    {
        $visitor = new class ($methodName) extends \PhpParser\NodeVisitorAbstract {
            public ?Node\Expr\StaticCall $found = null;

            public function __construct(private readonly string $methodName)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if (
                    $node instanceof Node\Expr\StaticCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === $this->methodName
                ) {
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
}
