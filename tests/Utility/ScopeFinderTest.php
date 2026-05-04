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
        $code = $this->loadFixture('src/Utility/ScopePatterns.php');
        $ast = self::parseWithParents($code);
        $namespace = $ast[1];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $classNode = self::findFirstClassLike($namespace->stmts, Stmt\Class_::class);
        self::assertNotNull($classNode);

        // nodeContainsLine uses 0-indexed lines, getStartLine/getEndLine return 1-indexed
        $startLine = $classNode->getStartLine() - 1;
        $endLine = $classNode->getEndLine() - 1;
        self::assertTrue(ScopeFinder::nodeContainsLine($classNode, $startLine));
        self::assertTrue(ScopeFinder::nodeContainsLine($classNode, $endLine));
        self::assertTrue(ScopeFinder::nodeContainsLine($classNode, (int)(($startLine + $endLine) / 2)));
    }

    public function testNodeContainsLineReturnsFalseWhenLineOutsideRange(): void
    {
        $code = $this->loadFixture('src/Utility/ScopePatterns.php');
        $ast = self::parseWithParents($code);
        $namespace = $ast[1];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $classNode = self::findFirstClassLike($namespace->stmts, Stmt\Class_::class);
        self::assertNotNull($classNode);

        // nodeContainsLine uses 0-indexed lines
        $startLine = $classNode->getStartLine() - 1;
        $endLine = $classNode->getEndLine() - 1;
        self::assertFalse(ScopeFinder::nodeContainsLine($classNode, $startLine - 2));
        self::assertFalse(ScopeFinder::nodeContainsLine($classNode, $endLine + 10));
    }

    public function testFindClassAtLineReturnsClassContainingLine(): void
    {
        $code = $this->loadFixture('src/Mixed/MultipleClasses.php');
        $ast = self::parseWithParents($code);
        $namespace = $ast[1];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $secondClass = $namespace->stmts[1];
        self::assertInstanceOf(Stmt\Class_::class, $secondClass);

        $midLine = (int)(($secondClass->getStartLine() + $secondClass->getEndLine()) / 2);
        $class = ScopeFinder::findClassAtLine($ast, $midLine);
        self::assertNotNull($class);
        self::assertSame('Second', $class->name?->toString());
    }

    public function testFindClassAtLineReturnsNullWhenNotInClass(): void
    {
        $code = $this->loadFixture('src/Utility/GlobalScope.php');
        $ast = self::parseWithParents($code);

        $class = ScopeFinder::findClassAtLine($ast, 1000);
        self::assertNull($class);
    }

    public function testResolveExtendsNameReturnsNullWhenNoExtends(): void
    {
        $code = $this->loadFixture('src/Utility/ScopePatterns.php');
        $ast = self::parseWithParents($code);
        $namespace = $ast[1];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $class = self::findFirstClassLike($namespace->stmts, Stmt\Class_::class);
        self::assertNotNull($class);

        self::assertNull(ScopeFinder::resolveExtendsName($class));
    }

    public function testResolveExtendsNameReturnsParentName(): void
    {
        $code = $this->loadFixture('Inheritance/NoNamespaceChild.php');
        $ast = self::parseWithParents($code);
        $class = self::findFirstClassLike($ast, Stmt\Class_::class);
        self::assertNotNull($class);

        self::assertSame('NoNamespaceParent', ScopeFinder::resolveExtendsName($class));
    }

    public function testResolveExtendsNameUsesResolvedNameWhenAvailable(): void
    {
        $code = $this->loadFixture('src/Utility/ImportedExtends.php');
        $ast = self::parseWithParents($code);
        $namespace = $ast[1];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $class = self::findFirstClassLike($namespace->stmts, Stmt\Class_::class);
        self::assertNotNull($class);

        self::assertSame('Fixtures\Inheritance\ParentClass', ScopeFinder::resolveExtendsName($class));
    }

    public function testResolveNameReturnsRawNameWhenNoResolvedAttribute(): void
    {
        $code = $this->loadFixture('src/Inheritance/ParentClass.php');
        $ast = self::parseWithParents($code);
        $namespace = $ast[1];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $class = self::findFirstClassLike($namespace->stmts, Stmt\Class_::class);
        self::assertNotNull($class);
        self::assertNotNull($class->extends);

        self::assertSame('Fixtures\Inheritance\Grandparent', ScopeFinder::resolveName($class->extends));
    }

    public function testResolveNameUsesResolvedNameWhenAvailable(): void
    {
        $code = $this->loadFixture('src/Utility/ImportedExtends.php');
        $ast = self::parseWithParents($code);
        $namespace = $ast[1];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $class = self::findFirstClassLike($namespace->stmts, Stmt\Class_::class);
        self::assertNotNull($class);
        self::assertNotNull($class->extends);

        self::assertSame('Fixtures\Inheritance\ParentClass', ScopeFinder::resolveName($class->extends));
    }

    public function testIterateTopLevelStatementsYieldsStatementsDirectly(): void
    {
        $code = $this->loadFixture('TypeInference/GlobalFunction.php');
        $ast = self::parseWithParents($code);

        $statements = iterator_to_array(ScopeFinder::iterateTopLevelStatements($ast));
        $classLikes = array_filter($statements, fn($s) => $s instanceof Stmt\ClassLike);
        $functions = array_filter($statements, fn($s) => $s instanceof Stmt\Function_);

        self::assertCount(1, $classLikes);
        self::assertCount(2, $functions);
        $class = reset($classLikes);
        self::assertInstanceOf(Stmt\Class_::class, $class);
        self::assertSame('GlobalConfig', $class->name?->toString());
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
        $code = $this->loadFixture('TypeInference/GlobalFunction.php');
        $ast = self::parseWithParents($code);

        $found = ScopeFinder::findFunction('testGlobalFunction', $ast);

        self::assertNotNull($found);
        self::assertSame('testGlobalFunction', $found->name->toString());
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
        $code = $this->loadFixture('src/Utility/ImportedExtends.php');
        $ast = self::parseWithParents($code);
        $namespace = $ast[1];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $class = self::findFirstClassLike($namespace->stmts, Stmt\Class_::class);
        self::assertNotNull($class);
        self::assertNotNull($class->extends);

        self::assertSame('Fixtures\Inheritance\ParentClass', ScopeFinder::resolveClassName($class->extends));
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
        $code = $this->loadFixture('src/TypeInference/NewKeywords.php');
        $ast = self::parseWithParents($code);
        $selfName = self::findNameNode('self', $ast);

        self::assertNotNull($selfName);
        $resolved = ScopeFinder::resolveClassNameInContext($selfName, $selfName);

        self::assertSame('Fixtures\TypeInference\NewKeywords', $resolved);
    }

    public function testResolveClassNameInContextResolvesStatic(): void
    {
        $code = $this->loadFixture('src/TypeInference/NewKeywords.php');
        $ast = self::parseWithParents($code);
        $staticName = self::findNameNode('static', $ast);

        self::assertNotNull($staticName);
        $resolved = ScopeFinder::resolveClassNameInContext($staticName, $staticName);

        self::assertSame('Fixtures\TypeInference\NewKeywords', $resolved);
    }

    public function testResolveClassNameInContextResolvesParent(): void
    {
        $code = $this->loadFixture('src/TypeInference/NewKeywords.php');
        $ast = self::parseWithParents($code);
        $parentName = self::findNameNode('parent', $ast);

        self::assertNotNull($parentName);
        $resolved = ScopeFinder::resolveClassNameInContext($parentName, $parentName);

        self::assertSame('Fixtures\Inheritance\ParentClass', $resolved);
    }

    public function testResolveClassNameInContextResolvesRegularClass(): void
    {
        $code = $this->loadFixture('src/TypeInference/NewKeywords.php');
        $ast = self::parseWithParents($code);
        $staticCall = self::findStaticCallNode('anotherStaticMethod', $ast);
        self::assertNotNull($staticCall);
        self::assertInstanceOf(Node\Name::class, $staticCall->class);

        $resolved = ScopeFinder::resolveClassNameInContext($staticCall->class, $staticCall);

        self::assertSame('Fixtures\Inheritance\ParentClass', $resolved);
    }

    public function testResolveClassNameInContextReturnsNullForSelfOutsideClass(): void
    {
        $code = $this->loadFixture('src/TypeInference/StaticCallOutsideClass.php');
        $ast = self::parseWithParents($code);
        $selfName = self::findNameNode('self', $ast);

        self::assertNotNull($selfName);
        $resolved = ScopeFinder::resolveClassNameInContext($selfName, $selfName);

        self::assertNull($resolved);
    }

    public function testResolveClassNameInContextReturnsNullForParentWithNoExtends(): void
    {
        $code = $this->loadFixture('src/TypeInference/ParentWithoutExtends.php');
        $ast = self::parseWithParents($code);
        $parentName = self::findNameNode('parent', $ast);

        self::assertNotNull($parentName);
        $resolved = ScopeFinder::resolveClassNameInContext($parentName, $parentName);

        self::assertNull($resolved);
    }

    public function testResolveClassNameInContextReturnsNullForParentInInterface(): void
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
