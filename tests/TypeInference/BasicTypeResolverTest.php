<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\TypeInference;

use DateTime;
use DateTimeImmutable;
use Exception;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\UnionType;
use Firehed\PhpLsp\Parser\ParserService;
use Throwable;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BasicTypeResolver::class)]
class BasicTypeResolverTest extends TestCase
{
    use LoadsFixturesTrait;

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

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(DateTime::class, $type->fqn);
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

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(DateTime::class, $type->fqn);
    }

    public function testResolveParameterTypeSelf(): void
    {
        $ast = $this->parseFixture('src/Completion/MethodAccess.php');
        $method = $this->findMethodByName($ast, 'withParameter');

        $type = $this->resolver->resolveVariableType('param', $method, $method->getStartLine(), $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('Fixtures\\Completion\\MethodAccess', $type->fqn);
    }

    public function testResolveParameterTypeParent(): void
    {
        $ast = $this->parseFixture('src/Inheritance/ChildClass.php');
        $method = $this->findMethodByName($ast, 'withParentParam');

        $type = $this->resolver->resolveVariableType('obj', $method, $method->getStartLine(), $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('Fixtures\\Inheritance\\ParentClass', $type->fqn);
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

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(DateTime::class, $type->fqn);
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
        self::assertInstanceOf(ClassName::class, $objectType);
        self::assertSame(DateTime::class, $objectType->fqn);
    }

    public function testResolveMethodReturnTypePrimitive(): void
    {
        // Exception::getMessage() has return type string
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

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('string', $type->format());
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

    public function testResolveMethodReturnTypeInt(): void
    {
        // Exception::getLine() returns int
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

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('int', $type->format());
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

    public function testResolveThisInFunctionReturnsNull(): void
    {
        // $this used in a function (not a method) - findEnclosingClassName returns null
        $code = <<<'PHP'
<?php
function test() {
    $x = $this;
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $thisVar = $this->findThisVariable($ast);

        $type = $this->resolver->resolveExpressionType($thisVar, $function, $ast);

        self::assertNull($type);
    }

    public function testResolveMethodCallOnUnresolvedTypeReturnsNull(): void
    {
        // Method call on a variable whose type can't be resolved
        $code = <<<'PHP'
<?php
function test() {
    $result = $unknown->someMethod();
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $methodCall = $this->findFirstExprOfType($ast, Expr\MethodCall::class);

        $type = $this->resolver->resolveExpressionType($methodCall, $function, $ast);

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

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(DateTime::class, $type->fqn);
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

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(DateTime::class, $type->fqn);
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

        // The left side is ?DateTime|null, full UnionType returned
        self::assertInstanceOf(UnionType::class, $type);
        self::assertTrue($type->isNullable());
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

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('MyClass', $type->fqn);
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

        // Returns full UnionType with DateTime|null
        self::assertInstanceOf(UnionType::class, $type);
        self::assertTrue($type->isNullable());
        $classNames = $type->getResolvableClassNames();
        self::assertCount(1, $classNames);
        self::assertSame(DateTime::class, $classNames[0]->fqn);
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

        // Returns full UnionType with both classes
        self::assertInstanceOf(UnionType::class, $type);
        $classNames = $type->getResolvableClassNames();
        self::assertCount(2, $classNames);
        self::assertSame(DateTime::class, $classNames[0]->fqn);
        self::assertSame(DateTimeImmutable::class, $classNames[1]->fqn);
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

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(DateTime::class, $type->fqn);
    }

    public function testResolveArrowFunctionParameter(): void
    {
        $code = '<?php $fn = fn(DateTime $dt) => $dt->format("Y");';
        $ast = $this->parse($code);
        $arrow = $this->findFirstExprOfType($ast, Expr\ArrowFunction::class);

        $type = $this->resolver->resolveVariableType('dt', $arrow, 0, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(DateTime::class, $type->fqn);
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

        // Returns full nullable type
        self::assertInstanceOf(UnionType::class, $type);
        self::assertTrue($type->isNullable());
        $classNames = $type->getResolvableClassNames();
        self::assertCount(1, $classNames);
        self::assertSame(Throwable::class, $classNames[0]->fqn);
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

        // getPrevious() returns ?Throwable as a UnionType
        $prevType = $this->resolver->resolveExpressionType($getPreviousCall, $function, $ast);
        self::assertInstanceOf(UnionType::class, $prevType);
        self::assertTrue($prevType->isNullable());
        $classNames = $prevType->getResolvableClassNames();
        self::assertCount(1, $classNames);
        self::assertSame(Throwable::class, $classNames[0]->fqn);

        // getMessage() returns string as PrimitiveType
        $msgType = $this->resolver->resolveExpressionType($getMessageCall, $function, $ast);
        self::assertInstanceOf(PrimitiveType::class, $msgType);
        self::assertSame('string', $msgType->format());
    }

    /**
     * @return array<Stmt>
     */
    private function parse(string $code): array
    {
        $parser = new ParserService();
        return $parser->parse(new TextDocument('file:///test.php', 'php', 1, $code)) ?? [];
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
     * @template T of Expr
     * @param array<Stmt> $ast
     * @param class-string<T> $type
     * @return list<T>
     */
    private function findAllExprsOfType(array $ast, string $type): array
    {
        $found = [];
        $finder = new class ($type, $found) extends \PhpParser\NodeVisitorAbstract {
            /** @var list<Expr> */
            public array $found = [];
            /** @var class-string<Expr> */
            private string $type;

            /**
             * @param class-string<Expr> $type
             * @param list<Expr> $found
             */
            public function __construct(string $type, array &$found)
            {
                $this->type = $type;
                $this->found = &$found;
            }

            public function enterNode(\PhpParser\Node $node): ?int
            {
                if ($node instanceof $this->type) {
                    $this->found[] = $node;
                }
                return null;
            }
        };

        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        // @phpstan-ignore return.type
        return $finder->found;
    }

    public function testResolveNullsafeMethodCall(): void
    {
        $code = <<<'PHP'
<?php
function test(?Exception $ex) {
    $msg = $ex?->getMessage();
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $methodCall = $this->findFirstExprOfType($ast, Expr\NullsafeMethodCall::class);

        $type = $this->resolver->resolveExpressionType($methodCall, $function, $ast);

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('string', $type->format());
    }

    public function testResolveNullsafePropertyFetch(): void
    {
        $code = <<<'PHP'
<?php
function test(?Exception $ex) {
    $msg = $ex?->message;
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $propertyFetch = $this->findFirstExprOfType($ast, Expr\NullsafePropertyFetch::class);

        // Exception::$message is a protected property - type resolution returns null
        $type = $this->resolver->resolveExpressionType($propertyFetch, $function, $ast);
        self::assertNull($type);
    }

    public function testResolveNullsafeMethodCallChain(): void
    {
        $code = <<<'PHP'
<?php
function test(?Exception $ex) {
    $prev = $ex?->getPrevious();
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFirstStmtOfType($ast, Stmt\Function_::class);
        $methodCall = $this->findFirstExprOfType($ast, Expr\NullsafeMethodCall::class);

        $type = $this->resolver->resolveExpressionType($methodCall, $function, $ast);

        // getPrevious returns ?Throwable
        self::assertInstanceOf(UnionType::class, $type);
        self::assertTrue($type->isNullable());
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

    public function testResolveNamespacedFunctionReturnType(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Config {
    public function get(string $key): mixed { return null; }
}

function getConfig(): Config { return new Config(); }

function test(): void {
    $config = getConfig();
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFunctionByName($ast, 'test');
        $funcCall = $this->findFirstExprOfType($ast, Expr\FuncCall::class);

        $type = $this->resolver->resolveExpressionType($funcCall, $function, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('App\\Config', $type->fqn);
    }

    public function testResolveVariableFromNamespacedFunctionCall(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Config {
    public function get(string $key): mixed { return null; }
}

function getConfig(): Config { return new Config(); }

function test(): void {
    $config = getConfig();
    echo $config;
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFunctionByName($ast, 'test');

        $type = $this->resolver->resolveVariableType('config', $function, 11, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('App\\Config', $type->fqn);
    }

    public function testResolveBuiltinFunctionReturnType(): void
    {
        $code = <<<'PHP'
<?php
function test(): void {
    $len = strlen("hello");
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFunctionByName($ast, 'test');
        $funcCall = $this->findFirstExprOfType($ast, Expr\FuncCall::class);

        $type = $this->resolver->resolveExpressionType($funcCall, $function, $ast);

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('int', $type->format());
    }

    public function testResolveTopLevelFunctionReturnType(): void
    {
        $code = <<<'PHP'
<?php
class Config {
    public function get(): string { return ''; }
}

function getConfig(): Config {
    return new Config();
}

function test(): void {
    $config = getConfig();
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFunctionByName($ast, 'test');
        $funcCall = $this->findFirstExprOfType($ast, Expr\FuncCall::class);

        $type = $this->resolver->resolveExpressionType($funcCall, $function, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('Config', $type->fqn);
    }

    public function testResolveDynamicFunctionCallReturnsNull(): void
    {
        $code = <<<'PHP'
<?php
function test(): void {
    $func = 'strlen';
    $result = $func("hello");
}
PHP;
        $ast = $this->parse($code);
        $function = $this->findFunctionByName($ast, 'test');
        $funcCalls = $this->findAllExprsOfType($ast, Expr\FuncCall::class);
        // Get the second FuncCall ($func("hello")), not $func = 'strlen'
        $dynamicCall = $funcCalls[0];

        $type = $this->resolver->resolveExpressionType($dynamicCall, $function, $ast);

        self::assertNull($type);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findFunctionByName(array $ast, string $name): Stmt\Function_
    {
        foreach ($ast as $node) {
            if ($node instanceof Stmt\Function_ && $node->name->toString() === $name) {
                return $node;
            }
            if ($node instanceof Stmt\Namespace_) {
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Stmt\Function_ && $stmt->name->toString() === $name) {
                        return $stmt;
                    }
                }
            }
        }
        throw new \RuntimeException("Could not find function $name");
    }

    /**
     * @return array<Stmt>
     */
    private function parseFixture(string $fixturePath): array
    {
        return $this->parse($this->loadFixture($fixturePath));
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findMethodByName(array $ast, string $methodName): Stmt\ClassMethod
    {
        $finder = new \PhpParser\NodeFinder();
        $method = $finder->findFirst($ast, fn($node) =>
            $node instanceof Stmt\ClassMethod && $node->name->toString() === $methodName);
        assert($method instanceof Stmt\ClassMethod, "Could not find method $methodName");
        return $method;
    }

    public function testResolveNewSelfInClass(): void
    {
        $ast = $this->parseFixture('src/TypeInference/NewKeywords.php');
        $method = $this->findMethodByName($ast, 'createSelf');
        $finder = new \PhpParser\NodeFinder();
        $newExpr = $finder->findFirstInstanceOf($method, Expr\New_::class);
        assert($newExpr !== null);

        $type = $this->resolver->resolveExpressionType($newExpr, $method, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('Fixtures\\TypeInference\\NewKeywords', $type->fqn);
    }

    public function testResolveNewStaticInClass(): void
    {
        $ast = $this->parseFixture('src/TypeInference/NewKeywords.php');
        $method = $this->findMethodByName($ast, 'createStatic');
        $finder = new \PhpParser\NodeFinder();
        $newExpr = $finder->findFirstInstanceOf($method, Expr\New_::class);
        assert($newExpr !== null);

        $type = $this->resolver->resolveExpressionType($newExpr, $method, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('Fixtures\\TypeInference\\NewKeywords', $type->fqn);
    }

    public function testResolveNewParentInClass(): void
    {
        $ast = $this->parseFixture('src/TypeInference/NewKeywords.php');
        $method = $this->findMethodByName($ast, 'createParent');
        $finder = new \PhpParser\NodeFinder();
        $newExpr = $finder->findFirstInstanceOf($method, Expr\New_::class);
        assert($newExpr !== null);

        $type = $this->resolver->resolveExpressionType($newExpr, $method, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('Fixtures\\Inheritance\\ParentClass', $type->fqn);
    }

    public function testResolveNewParentInTraitReturnsNull(): void
    {
        $ast = $this->parseFixture('src/TypeInference/ParentInTrait.php');
        $method = $this->findMethodByName($ast, 'createFromTrait');
        $finder = new \PhpParser\NodeFinder();
        $newExpr = $finder->findFirstInstanceOf($method, Expr\New_::class);
        assert($newExpr !== null);

        $type = $this->resolver->resolveExpressionType($newExpr, $method, $ast);

        self::assertNull($type);
    }

    public function testResolveNewSelfInAnonymousClassReturnsNull(): void
    {
        $ast = $this->parseFixture('src/TypeInference/AnonymousClass.php');
        $method = $this->findMethodByName($ast, 'createSelf');
        $finder = new \PhpParser\NodeFinder();
        $newExpr = $finder->findFirstInstanceOf($method, Expr\New_::class);
        assert($newExpr !== null);

        $type = $this->resolver->resolveExpressionType($newExpr, $method, $ast);

        self::assertNull($type);
    }

    public function testResolveSelfStaticCall(): void
    {
        $resolver = $this->createResolverWithFixtures();
        $ast = $this->parseFixture('src/TypeInference/NewKeywords.php');
        $method = $this->findMethodByName($ast, 'callSelfStaticMethod');
        $finder = new \PhpParser\NodeFinder();
        $staticCall = $finder->findFirstInstanceOf($method, Expr\StaticCall::class);
        assert($staticCall !== null);

        $type = $resolver->resolveExpressionType($staticCall, $method, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('Fixtures\\TypeInference\\NewKeywords', $type->fqn);
    }

    public function testResolveStaticStaticCall(): void
    {
        $resolver = $this->createResolverWithFixtures();
        $ast = $this->parseFixture('src/TypeInference/NewKeywords.php');
        $method = $this->findMethodByName($ast, 'callStaticStaticMethod');
        $finder = new \PhpParser\NodeFinder();
        $staticCall = $finder->findFirstInstanceOf($method, Expr\StaticCall::class);
        assert($staticCall !== null);

        $type = $resolver->resolveExpressionType($staticCall, $method, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('Fixtures\\TypeInference\\NewKeywords', $type->fqn);
    }

    public function testResolveSelfStaticCallOutsideClassReturnsNull(): void
    {
        $ast = $this->parseFixture('src/TypeInference/StaticCallOutsideClass.php');
        $func = $this->findFunctionByName($ast, 'callSelfOutsideClass');
        $finder = new \PhpParser\NodeFinder();
        $staticCall = $finder->findFirstInstanceOf($func, Expr\StaticCall::class);
        assert($staticCall !== null);

        $type = $this->resolver->resolveExpressionType($staticCall, $func, $ast);

        self::assertNull($type);
    }

    public function testResolveParentStaticCallWithoutExtendsReturnsNull(): void
    {
        $ast = $this->parseFixture('src/TypeInference/ParentWithoutExtends.php');
        $method = $this->findMethodByName($ast, 'callParentMethod');
        $finder = new \PhpParser\NodeFinder();
        $staticCall = $finder->findFirstInstanceOf($method, Expr\StaticCall::class);
        assert($staticCall !== null);

        $type = $this->resolver->resolveExpressionType($staticCall, $method, $ast);

        self::assertNull($type);
    }

    public function testResolveTraitStaticReturnTypeToCallingClass(): void
    {
        $resolver = $this->createResolverWithFixtures();
        $ast = $this->parseFixture('src/TypeInference/TraitStaticReturn.php');
        $method = $this->findMethodByName($ast, 'callTraitStaticMethod');
        $finder = new \PhpParser\NodeFinder();
        $staticCall = $finder->findFirstInstanceOf($method, Expr\StaticCall::class);
        assert($staticCall !== null);

        $type = $resolver->resolveExpressionType($staticCall, $method, $ast);

        // The trait method returns `static`, which should resolve to ConcreteService
        // (the class the method was called on), not SingletonTrait (where it's defined)
        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('Fixtures\\Traits\\ConcreteService', $type->fqn);
    }

    private function createResolverWithFixtures(): BasicTypeResolver
    {
        $classInfoFactory = new DefaultClassInfoFactory();
        $locator = new \Firehed\PhpLsp\Index\ComposerClassLocator(__DIR__ . '/../Fixtures');
        $parser = new ParserService();
        $classRepository = new DefaultClassRepository($classInfoFactory, $locator, $parser);
        $memberResolver = new MemberResolver($classRepository);

        return new BasicTypeResolver($memberResolver);
    }
}
