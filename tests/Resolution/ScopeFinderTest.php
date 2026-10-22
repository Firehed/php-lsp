<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Resolution\ScopeFinder;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
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
                    return NodeVisitor::STOP_TRAVERSAL;
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

    public function testFindEnclosingClasslikeNameReturnsShortName(): void
    {
        $code = $this->loadFixture('TypeInference/GlobalFunction.php');
        $ast = self::parseWithParents($code);
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $className = ScopeFinder::findEnclosingClasslikeName($thisNode);

        self::assertSame('GlobalConfig', $className);
    }

    public function testFindEnclosingClasslikeNameReturnsFqn(): void
    {
        $code = $this->loadFixture('src/Domain/User.php');
        $ast = self::parseWithParents($code);
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $className = ScopeFinder::findEnclosingClasslikeName($thisNode);

        self::assertSame('Fixtures\Domain\User', $className);
    }

    public function testFindEnclosingClasslikeNameReturnsNullOutsideClass(): void
    {
        $code = $this->loadFixture('src/Utility/GlobalScope.php');
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('functionVar', $ast);

        self::assertNotNull($varNode);
        $className = ScopeFinder::findEnclosingClasslikeName($varNode);

        self::assertNull($className);
    }

    public function testFindEnclosingClasslikeNameReturnsNullForAnonymousClass(): void
    {
        $code = $this->loadFixture('src/Utility/AnonymousClassScope.php');
        $ast = self::parseWithParents($code);
        $thisNode = self::findVariableNode('this', $ast);

        self::assertNotNull($thisNode);
        $className = ScopeFinder::findEnclosingClasslikeName($thisNode);

        self::assertNull($className);
    }

    public function testResolveClasslikeNameInContextResolvesSelf(): void
    {
        $code = $this->loadFixture('src/TypeInference/NewKeywords.php');
        $ast = self::parseWithParents($code);
        $selfName = self::findNameNode('self', $ast);

        self::assertNotNull($selfName);
        $resolved = ScopeFinder::resolveClasslikeNameInContext($selfName, $selfName);

        self::assertSame('Fixtures\TypeInference\NewKeywords', $resolved);
    }

    public function testResolveClasslikeNameInContextResolvesStatic(): void
    {
        $code = $this->loadFixture('src/TypeInference/NewKeywords.php');
        $ast = self::parseWithParents($code);
        $staticName = self::findNameNode('static', $ast);

        self::assertNotNull($staticName);
        $resolved = ScopeFinder::resolveClasslikeNameInContext($staticName, $staticName);

        self::assertSame('Fixtures\TypeInference\NewKeywords', $resolved);
    }

    public function testResolveClasslikeNameInContextResolvesParent(): void
    {
        $code = $this->loadFixture('src/TypeInference/NewKeywords.php');
        $ast = self::parseWithParents($code);
        $parentName = self::findNameNode('parent', $ast);

        self::assertNotNull($parentName);
        $resolved = ScopeFinder::resolveClasslikeNameInContext($parentName, $parentName);

        self::assertSame('Fixtures\Inheritance\ParentClass', $resolved);
    }

    public function testResolveClasslikeNameInContextResolvesRegularClass(): void
    {
        $code = $this->loadFixture('src/TypeInference/NewKeywords.php');
        $ast = self::parseWithParents($code);
        $staticCall = self::findStaticCallNode('anotherStaticMethod', $ast);
        self::assertNotNull($staticCall);
        self::assertInstanceOf(Node\Name::class, $staticCall->class);

        $resolved = ScopeFinder::resolveClasslikeNameInContext($staticCall->class, $staticCall);

        self::assertSame('Fixtures\Inheritance\ParentClass', $resolved);
    }

    public function testResolveClasslikeNameInContextReturnsNullForSelfOutsideClass(): void
    {
        $code = $this->loadFixture('src/TypeInference/StaticCallOutsideClass.php');
        $ast = self::parseWithParents($code);
        $selfName = self::findNameNode('self', $ast);

        self::assertNotNull($selfName);
        $resolved = ScopeFinder::resolveClasslikeNameInContext($selfName, $selfName);

        self::assertNull($resolved);
    }

    public function testResolveClasslikeNameInContextReturnsNullForParentWithNoExtends(): void
    {
        $code = $this->loadFixture('src/TypeInference/ParentWithoutExtends.php');
        $ast = self::parseWithParents($code);
        $parentName = self::findNameNode('parent', $ast);

        self::assertNotNull($parentName);
        $resolved = ScopeFinder::resolveClasslikeNameInContext($parentName, $parentName);

        self::assertNull($resolved);
    }

    public function testResolveClasslikeNameInContextReturnsNullForParentInInterface(): void
    {
        $code = $this->loadFixture('src/Domain/Entity.php');
        $ast = self::parseWithParents($code);
        // Create a fake parent Name node to test this edge case
        $name = new Node\Name('parent');

        // Find the interface method to get a context node
        $methodNode = self::findMethodNode('getId', $ast);
        self::assertNotNull($methodNode);

        // Manually set parent attribute
        $name->setAttribute('parent', $methodNode);

        $resolved = ScopeFinder::resolveClasslikeNameInContext($name, $name);

        self::assertNull($resolved);
    }

    public function testFindEnclosingNamespaceReturnsNamespace(): void
    {
        $code = $this->loadFixture('src/Domain/User.php');
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('this', $ast);

        self::assertNotNull($varNode);
        $namespace = ScopeFinder::findEnclosingNamespace($varNode);

        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        self::assertNotNull($namespace->name);
        self::assertSame('Fixtures\Domain', $namespace->name->toString());
    }

    public function testFindEnclosingNamespaceReturnsNullOutsideNamespace(): void
    {
        $code = $this->loadFixture('TypeInference/GlobalFunction.php');
        $ast = self::parseWithParents($code);
        $varNode = self::findVariableNode('this', $ast);

        self::assertNotNull($varNode);
        $namespace = ScopeFinder::findEnclosingNamespace($varNode);

        self::assertNull($namespace);
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
                    return NodeVisitor::STOP_TRAVERSAL;
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
                    return NodeVisitor::STOP_TRAVERSAL;
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
                    return NodeVisitor::STOP_TRAVERSAL;
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
