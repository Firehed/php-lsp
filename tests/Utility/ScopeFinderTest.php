<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScopeFinder::class)]
class ScopeFinderTest extends TestCase
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
}
