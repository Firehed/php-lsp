<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\TypeInference;

use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BasicTypeResolver::class)]
class BasicTypeResolverTest extends TestCase
{
    private BasicTypeResolver $resolver;

    protected function setUp(): void
    {
        $classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $parser = new ParserService();
        $classRepository = new DefaultClassRepository($classInfoFactory, $locator, $parser);
        $memberResolver = new MemberResolver($classRepository);

        $this->resolver = new BasicTypeResolver($memberResolver);
    }

    public function testResolveNewExpression(): void
    {
        $code = '<?php $x = new DateTime();';
        $ast = $this->parse($code);
        $expr = $this->findFirstExprOfType($ast, Expr\New_::class);

        $type = $this->resolver->resolveExpressionType($expr, null, $ast);

        self::assertSame('DateTime', $type);
    }

    public function testResolveParameterType(): void
    {
        $code = <<<'PHP'
<?php
function test(DateTime $dt) {
    $x = $dt;
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);

        $type = $this->resolver->resolveVariableType('dt', $function, 2, $ast);

        self::assertSame('DateTime', $type);
    }

    public function testResolveAssignmentFromNew(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    $x = new DateTime();
    $y = $x;
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);

        $type = $this->resolver->resolveVariableType('x', $function, 3, $ast);

        self::assertSame('DateTime', $type);
    }

    public function testResolveMethodReturnTypeResolvesObjectType(): void
    {
        // Note: Many built-in PHP methods don't have return type declarations
        // in reflection. This test verifies we can at least resolve the object type
        // that the method is called on.
        $code = <<<'PHP'
<?php
function test() {
    $dt = new DateTime();
    $result = $dt->format('Y');
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $methodCall = $this->findFirstExprOfType($ast, Expr\MethodCall::class);

        // The object type is resolved, even if method return type isn't
        $objectType = $this->resolver->resolveExpressionType($methodCall->var, $function, $ast);
        self::assertSame('DateTime', $objectType);
    }

    public function testResolveMethodReturnTypePrimitiveReturnsNull(): void
    {
        // Exception::getMessage() has return type string - not a class, so null
        $code = <<<'PHP'
<?php
function test() {
    $ex = new Exception();
    $result = $ex->getMessage();
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $methodCall = $this->findFirstExprOfType($ast, Expr\MethodCall::class);

        $type = $this->resolver->resolveExpressionType($methodCall, $function, $ast);

        self::assertNull($type);
    }

    public function testResolveMethodReturnTypeReturnsNullForUnknownMethod(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    $ex = new Exception();
    $result = $ex->nonExistentMethod();
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $methodCall = $this->findFirstExprOfType($ast, Expr\MethodCall::class);

        $type = $this->resolver->resolveExpressionType($methodCall, $function, $ast);

        self::assertNull($type);
    }

    public function testResolveMethodReturnTypeIntReturnsNull(): void
    {
        // Exception::getLine() returns int - not a class, so null
        $code = <<<'PHP'
<?php
function test() {
    $ex = new Exception();
    $result = $ex->getLine();
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $methodCall = $this->findFirstExprOfType($ast, Expr\MethodCall::class);

        $type = $this->resolver->resolveExpressionType($methodCall, $function, $ast);

        self::assertNull($type);
    }

    public function testResolvePropertyTypeReturnsNullForUnknownClass(): void
    {
        $code = <<<'PHP'
<?php
function test(UnknownClass $obj) {
    $result = $obj->name;
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $propertyFetch = $this->findFirstExprOfType($ast, Expr\PropertyFetch::class);

        $type = $this->resolver->resolveExpressionType($propertyFetch, $function, $ast);

        self::assertNull($type);
    }

    public function testResolveCloneExpression(): void
    {
        $code = <<<'PHP'
<?php
function test(DateTime $dt) {
    $cloned = clone $dt;
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $clone = $this->findFirstExprOfType($ast, Expr\Clone_::class);

        $type = $this->resolver->resolveExpressionType($clone, $function, $ast);

        self::assertSame('DateTime', $type);
    }

    public function testResolveTernaryExpression(): void
    {
        $code = <<<'PHP'
<?php
function test(DateTime $dt, bool $cond) {
    $result = $cond ? $dt : null;
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $ternary = $this->findFirstExprOfType($ast, Expr\Ternary::class);

        $type = $this->resolver->resolveExpressionType($ternary, $function, $ast);

        self::assertSame('DateTime', $type);
    }

    public function testResolveNullCoalesceExpression(): void
    {
        $code = <<<'PHP'
<?php
function test(?DateTime $dt) {
    $result = $dt ?? new DateTimeImmutable();
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $coalesce = $this->findFirstExprOfType($ast, Expr\BinaryOp\Coalesce::class);

        $type = $this->resolver->resolveExpressionType($coalesce, $function, $ast);

        // The left side is ?DateTime parameter, returns DateTime class
        self::assertSame('DateTime', $type);
    }

    public function testResolveThisInClassMethod(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {
    public function test() {
        $x = $this;
    }
}
PHP;
        $ast = $this->parse($code);
        $method = $this->findFirstStmtOfType($ast, Stmt\ClassMethod::class);
        $thisVar = $this->findThisVariable($ast);

        $type = $this->resolver->resolveExpressionType($thisVar, $method, $ast);

        self::assertSame('MyClass', $type);
    }

    public function testResolveNullableParameterType(): void
    {
        $code = <<<'PHP'
<?php
function test(?DateTime $dt) {
    $x = $dt;
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);

        $type = $this->resolver->resolveVariableType('dt', $function, 2, $ast);

        // Returns the class name, stripped of nullable
        self::assertSame('DateTime', $type);
    }

    public function testResolveUnionParameterType(): void
    {
        $code = <<<'PHP'
<?php
function test(DateTime|DateTimeImmutable $dt) {
    $x = $dt;
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);

        $type = $this->resolver->resolveVariableType('dt', $function, 2, $ast);

        // Returns the first class from the union
        self::assertSame('DateTime', $type);
    }

    public function testResolveUnknownVariableReturnsNull(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    $x = unknown_function();
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);

        $type = $this->resolver->resolveVariableType('x', $function, 2, $ast);

        self::assertNull($type);
    }

    public function testResolveClosureParameter(): void
    {
        $code = <<<'PHP'
<?php
$fn = function (DateTime $dt) {
    $x = $dt;
};
PHP;
        $ast = $this->parse($code);
        $closure = $this->findFirstExprOfType($ast, Expr\Closure::class);

        $type = $this->resolver->resolveVariableType('dt', $closure, 2, $ast);

        self::assertSame('DateTime', $type);
    }

    public function testResolveArrowFunctionParameter(): void
    {
        $code = '<?php $fn = fn(DateTime $dt) => $dt->format("Y");';
        $ast = $this->parse($code);
        $arrow = $this->findFirstExprOfType($ast, Expr\ArrowFunction::class);

        $type = $this->resolver->resolveVariableType('dt', $arrow, 0, $ast);

        self::assertSame('DateTime', $type);
    }

    public function testResolveNullableMethodReturnType(): void
    {
        // Exception::getPrevious() returns ?Throwable
        $code = <<<'PHP'
<?php
function test() {
    $ex = new Exception();
    $prev = $ex->getPrevious();
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $methodCall = $this->findFirstExprOfType($ast, Expr\MethodCall::class);

        $type = $this->resolver->resolveExpressionType($methodCall, $function, $ast);

        // Returns the class name, stripped of nullable
        self::assertSame('Throwable', $type);
    }

    public function testChainWithNullableIntermediate(): void
    {
        // Exception::getPrevious() returns ?Throwable
        // Throwable::getMessage() returns string
        $code = <<<'PHP'
<?php
function test() {
    $ex = new Exception();
    $msg = $ex->getPrevious()->getMessage();
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);

        // Find method calls - traversal is top-down, so outer call first
        $methodCalls = [];
        $finder = new class ($methodCalls) extends \PhpParser\NodeVisitorAbstract {
            /** @var list<Expr\MethodCall> */
            public array $methodCalls = [];

            /**
             * @param list<Expr\MethodCall> $methodCalls
             */
            public function __construct(array &$methodCalls)
            {
                $this->methodCalls = &$methodCalls;
            }

            public function enterNode(\PhpParser\Node $node): ?int
            {
                if ($node instanceof Expr\MethodCall) {
                    $this->methodCalls[] = $node;
                }
                return null;
            }
        };
        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        // methodCalls[0] is getMessage (outer), methodCalls[1] is getPrevious (inner)
        $getMessageCall = $finder->methodCalls[0];
        $getPreviousCall = $finder->methodCalls[1];

        // Verify getPrevious() returns Throwable (class extracted from ?Throwable)
        $prevType = $this->resolver->resolveExpressionType($getPreviousCall, $function, $ast);
        self::assertSame('Throwable', $prevType);

        // getMessage() returns string (null for class-based resolution)
        // The chain works because getPrevious() resolved to Throwable
        $msgType = $this->resolver->resolveExpressionType($getMessageCall, $function, $ast);
        self::assertNull($msgType);
    }

    /**
     * @return array<Stmt>
     */
    private function parse(string $code): array
    {
        $parser = (new ParserFactory())->createForHostVersion();
        return $parser->parse($code) ?? [];
    }

    /**
     * @template T of Stmt
     * @param array<Stmt> $ast
     * @param class-string<T> $type
     * @return T
     */
    private function findFirstStmtOfType(array $ast, string $type): Stmt
    {
        foreach ($ast as $node) {
            if ($node instanceof $type) {
                return $node;
            }
            if ($node instanceof Stmt\Class_) {
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof $type) {
                        return $stmt;
                    }
                }
            }
            if ($node instanceof Stmt\Namespace_) {
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof $type) {
                        return $stmt;
                    }
                }
            }
        }
        throw new \RuntimeException("Could not find statement of type $type");
    }

    /**
     * @template T of Expr
     * @param array<Stmt> $ast
     * @param class-string<T> $type
     * @return T
     */
    private function findFirstExprOfType(array $ast, string $type): Expr
    {
        $found = null;
        $finder = new class ($type, $found) extends \PhpParser\NodeVisitorAbstract {
            public ?Expr $found = null;
            /** @var class-string<Expr> */
            private string $type;

            /**
             * @param class-string<Expr> $type
             */
            public function __construct(string $type, ?Expr &$found)
            {
                $this->type = $type;
                $this->found = &$found;
            }

            public function enterNode(\PhpParser\Node $node): ?int
            {
                if ($node instanceof $this->type && $this->found === null) {
                    $this->found = $node;
                    return \PhpParser\NodeTraverser::STOP_TRAVERSAL;
                }
                return null;
            }
        };

        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        if ($finder->found === null) {
            throw new \RuntimeException("Could not find expression of type $type");
        }
        assert($finder->found instanceof $type);
        return $finder->found;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findThisVariable(array $ast): Expr\Variable
    {
        $found = null;
        $finder = new class ($found) extends \PhpParser\NodeVisitorAbstract {
            public ?Expr\Variable $found = null;

            public function __construct(?Expr\Variable &$found)
            {
                $this->found = &$found;
            }

            public function enterNode(\PhpParser\Node $node): ?int
            {
                if (
                    $node instanceof Expr\Variable
                    && $node->name === 'this'
                    && $this->found === null
                ) {
                    $this->found = $node;
                    return \PhpParser\NodeTraverser::STOP_TRAVERSAL;
                }
                return null;
            }
        };

        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        if ($finder->found === null) {
            throw new \RuntimeException('Could not find $this variable');
        }
        return $finder->found;
    }
}
