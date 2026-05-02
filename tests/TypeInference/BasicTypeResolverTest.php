<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\TypeInference;

use DateTime;
use DateTimeImmutable;
use Exception;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\IntersectionType;
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
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'newDateTime');
        $finder = new \PhpParser\NodeFinder();
        $expr = $finder->findFirstInstanceOf($method, Expr\New_::class);
        assert($expr instanceof Expr\New_);

        $type = $this->resolver->resolveExpressionType($expr, $method, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(DateTime::class, $type->fqn);
    }

    public function testResolveParameterType(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'parameterType');

        $type = $this->resolver->resolveVariableType('dt', $method, $method->getStartLine(), $ast);

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
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'assignmentFromNew');

        $type = $this->resolver->resolveVariableType('x', $method, $method->getStartLine() + 1, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(DateTime::class, $type->fqn);
    }

    public function testResolveMethodReturnTypeResolvesObjectType(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'methodCallOnBuiltin');
        $finder = new \PhpParser\NodeFinder();
        $methodCall = $finder->findFirstInstanceOf($method, Expr\MethodCall::class);
        assert($methodCall instanceof Expr\MethodCall);

        $objectType = $this->resolver->resolveExpressionType($methodCall->var, $method, $ast);
        self::assertInstanceOf(ClassName::class, $objectType);
        self::assertSame(DateTime::class, $objectType->fqn);
    }

    public function testResolveMethodReturnTypePrimitive(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'exceptionGetMessage');
        $finder = new \PhpParser\NodeFinder();
        $methodCall = $finder->findFirstInstanceOf($method, Expr\MethodCall::class);
        assert($methodCall instanceof Expr\MethodCall);

        $type = $this->resolver->resolveExpressionType($methodCall, $method, $ast);

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('string', $type->format());
    }

    public function testResolveMethodReturnTypeReturnsNullForUnknownMethod(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'exceptionNonExistentMethod');
        $finder = new \PhpParser\NodeFinder();
        $methodCall = $finder->findFirstInstanceOf($method, Expr\MethodCall::class);
        assert($methodCall instanceof Expr\MethodCall);

        $type = $this->resolver->resolveExpressionType($methodCall, $method, $ast);

        self::assertNull($type);
    }

    public function testResolveMethodReturnTypeInt(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'exceptionGetLine');
        $finder = new \PhpParser\NodeFinder();
        $methodCall = $finder->findFirstInstanceOf($method, Expr\MethodCall::class);
        assert($methodCall instanceof Expr\MethodCall);

        $type = $this->resolver->resolveExpressionType($methodCall, $method, $ast);

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('int', $type->format());
    }

    public function testResolvePropertyTypeReturnsNullForUnknownClass(): void
    {
        $ast = $this->parseFixture('src/TypeInference/StaticCallOutsideClass.php');
        $function = $this->findFunctionByName($ast, 'unknownClassParameter');
        $finder = new \PhpParser\NodeFinder();
        $propertyFetch = $finder->findFirstInstanceOf($function, Expr\PropertyFetch::class);
        assert($propertyFetch instanceof Expr\PropertyFetch);

        $type = $this->resolver->resolveExpressionType($propertyFetch, $function, $ast);

        self::assertNull($type);
    }

    public function testResolveThisInFunctionReturnsNull(): void
    {
        $ast = $this->parseFixture('src/TypeInference/StaticCallOutsideClass.php');
        $function = $this->findFunctionByName($ast, 'thisInFunction');
        $thisVar = $this->findThisVariable([$function]);

        $type = $this->resolver->resolveExpressionType($thisVar, $function, $ast);

        self::assertNull($type);
    }

    public function testResolveMethodCallOnUnresolvedTypeReturnsNull(): void
    {
        $ast = $this->parseFixture('src/TypeInference/StaticCallOutsideClass.php');
        $function = $this->findFunctionByName($ast, 'methodCallOnUnresolvedType');
        $finder = new \PhpParser\NodeFinder();
        $methodCall = $finder->findFirstInstanceOf($function, Expr\MethodCall::class);
        assert($methodCall instanceof Expr\MethodCall);

        $type = $this->resolver->resolveExpressionType($methodCall, $function, $ast);

        self::assertNull($type);
    }

    public function testResolveCloneExpression(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'cloneExpression');
        $finder = new \PhpParser\NodeFinder();
        $clone = $finder->findFirstInstanceOf($method, Expr\Clone_::class);
        assert($clone instanceof Expr\Clone_);

        $type = $this->resolver->resolveExpressionType($clone, $method, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(DateTime::class, $type->fqn);
    }

    public function testResolveTernaryExpression(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'ternaryExpression');
        $finder = new \PhpParser\NodeFinder();
        $ternary = $finder->findFirstInstanceOf($method, Expr\Ternary::class);
        assert($ternary instanceof Expr\Ternary);

        $type = $this->resolver->resolveExpressionType($ternary, $method, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(DateTime::class, $type->fqn);
    }

    public function testResolveNullCoalesceExpression(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'nullCoalesceExpression');
        $finder = new \PhpParser\NodeFinder();
        $coalesce = $finder->findFirstInstanceOf($method, Expr\BinaryOp\Coalesce::class);
        assert($coalesce instanceof Expr\BinaryOp\Coalesce);

        $type = $this->resolver->resolveExpressionType($coalesce, $method, $ast);

        self::assertInstanceOf(UnionType::class, $type);
        self::assertTrue($type->isNullable());
    }

    public function testResolveThisInClassMethod(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'thisReference');
        $thisVar = $this->findThisVariable([$method]);

        $type = $this->resolver->resolveExpressionType($thisVar, $method, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('Fixtures\\TypeInference\\BuiltinTypes', $type->fqn);
    }

    public function testResolveNullableParameterType(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'nullableParameterType');

        $type = $this->resolver->resolveVariableType('dt', $method, $method->getStartLine(), $ast);

        self::assertInstanceOf(UnionType::class, $type);
        self::assertTrue($type->isNullable());
        $classNames = $type->getResolvableClassNames();
        self::assertCount(1, $classNames);
        self::assertSame(DateTime::class, $classNames[0]->fqn);
    }

    public function testResolveUnionParameterType(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'unionParameterType');

        $type = $this->resolver->resolveVariableType('dt', $method, $method->getStartLine(), $ast);

        self::assertInstanceOf(UnionType::class, $type);
        $classNames = $type->getResolvableClassNames();
        self::assertCount(2, $classNames);
        self::assertSame(DateTime::class, $classNames[0]->fqn);
        self::assertSame(DateTimeImmutable::class, $classNames[1]->fqn);
    }

    public function testResolveUnknownVariableReturnsNull(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'unknownVariableCall');

        $type = $this->resolver->resolveVariableType('x', $method, $method->getStartLine() + 1, $ast);

        self::assertNull($type);
    }

    public function testResolveClosureParameter(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'closureParameter');
        $finder = new \PhpParser\NodeFinder();
        $closure = $finder->findFirstInstanceOf($method, Expr\Closure::class);
        assert($closure instanceof Expr\Closure);

        $type = $this->resolver->resolveVariableType('dt', $closure, $closure->getStartLine(), $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(DateTime::class, $type->fqn);
    }

    public function testResolveArrowFunctionParameter(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'arrowFunctionParameter');
        $finder = new \PhpParser\NodeFinder();
        $arrow = $finder->findFirstInstanceOf($method, Expr\ArrowFunction::class);
        assert($arrow instanceof Expr\ArrowFunction);

        $type = $this->resolver->resolveVariableType('dt', $arrow, $arrow->getStartLine(), $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(DateTime::class, $type->fqn);
    }

    public function testResolveNullableMethodReturnType(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'nullableMethodReturnType');
        $finder = new \PhpParser\NodeFinder();
        $methodCall = $finder->findFirstInstanceOf($method, Expr\MethodCall::class);
        assert($methodCall instanceof Expr\MethodCall);

        $type = $this->resolver->resolveExpressionType($methodCall, $method, $ast);

        self::assertInstanceOf(UnionType::class, $type);
        self::assertTrue($type->isNullable());
        $classNames = $type->getResolvableClassNames();
        self::assertCount(1, $classNames);
        self::assertSame(Throwable::class, $classNames[0]->fqn);
    }

    public function testChainWithNullableIntermediate(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'chainWithNullableIntermediate');
        $methodCalls = $this->findAllExprsOfType([$method], Expr\MethodCall::class);

        // methodCalls[0] is getMessage (outer), methodCalls[1] is getPrevious (inner)
        $getMessageCall = $methodCalls[0];
        $getPreviousCall = $methodCalls[1];

        // getPrevious() returns ?Throwable as a UnionType
        $prevType = $this->resolver->resolveExpressionType($getPreviousCall, $method, $ast);
        self::assertInstanceOf(UnionType::class, $prevType);
        self::assertTrue($prevType->isNullable());
        $classNames = $prevType->getResolvableClassNames();
        self::assertCount(1, $classNames);
        self::assertSame(Throwable::class, $classNames[0]->fqn);

        // getMessage() returns string as PrimitiveType
        $msgType = $this->resolver->resolveExpressionType($getMessageCall, $method, $ast);
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
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'nullsafeMethodCall');
        $finder = new \PhpParser\NodeFinder();
        $methodCall = $finder->findFirstInstanceOf($method, Expr\NullsafeMethodCall::class);
        assert($methodCall instanceof Expr\NullsafeMethodCall);

        $type = $this->resolver->resolveExpressionType($methodCall, $method, $ast);

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('string', $type->format());
    }

    public function testResolveNullsafePropertyFetch(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'nullsafePropertyFetch');
        $finder = new \PhpParser\NodeFinder();
        $propertyFetch = $finder->findFirstInstanceOf($method, Expr\NullsafePropertyFetch::class);
        assert($propertyFetch instanceof Expr\NullsafePropertyFetch);

        $type = $this->resolver->resolveExpressionType($propertyFetch, $method, $ast);
        self::assertNull($type);
    }

    public function testResolveNullsafeMethodCallChain(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'nullsafeMethodCallChain');
        $finder = new \PhpParser\NodeFinder();
        $methodCall = $finder->findFirstInstanceOf($method, Expr\NullsafeMethodCall::class);
        assert($methodCall instanceof Expr\NullsafeMethodCall);

        $type = $this->resolver->resolveExpressionType($methodCall, $method, $ast);

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
        $ast = $this->parseFixture('src/TypeInference/FunctionTypes.php');
        $function = $this->findFunctionByName($ast, 'testNamespacedFunction');
        $finder = new \PhpParser\NodeFinder();
        $funcCall = $finder->findFirstInstanceOf($function, Expr\FuncCall::class);
        assert($funcCall instanceof Expr\FuncCall);

        $type = $this->resolver->resolveExpressionType($funcCall, $function, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('Fixtures\\TypeInference\\Config', $type->fqn);
    }

    public function testResolveVariableFromNamespacedFunctionCall(): void
    {
        $ast = $this->parseFixture('src/TypeInference/FunctionTypes.php');
        $function = $this->findFunctionByName($ast, 'testNamespacedFunctionUsage');

        $type = $this->resolver->resolveVariableType('config', $function, $function->getStartLine() + 1, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('Fixtures\\TypeInference\\Config', $type->fqn);
    }

    public function testResolveBuiltinFunctionReturnType(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'builtinFunctionCall');
        $finder = new \PhpParser\NodeFinder();
        $funcCall = $finder->findFirstInstanceOf($method, Expr\FuncCall::class);
        assert($funcCall instanceof Expr\FuncCall);

        $type = $this->resolver->resolveExpressionType($funcCall, $method, $ast);

        self::assertInstanceOf(PrimitiveType::class, $type);
        self::assertSame('int', $type->format());
    }

    public function testResolveTopLevelFunctionReturnType(): void
    {
        $ast = $this->parseFixture('TypeInference/GlobalFunction.php');
        $function = $this->findFunctionByName($ast, 'testGlobalFunction');
        $finder = new \PhpParser\NodeFinder();
        $funcCall = $finder->findFirstInstanceOf($function, Expr\FuncCall::class);
        assert($funcCall instanceof Expr\FuncCall);

        $type = $this->resolver->resolveExpressionType($funcCall, $function, $ast);

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('GlobalConfig', $type->fqn);
    }

    public function testResolveDynamicFunctionCallReturnsNull(): void
    {
        $ast = $this->parseFixture('src/TypeInference/BuiltinTypes.php');
        $method = $this->findMethodByName($ast, 'dynamicFunctionCall');
        $funcCalls = $this->findAllExprsOfType([$method], Expr\FuncCall::class);
        $dynamicCall = $funcCalls[0];

        $type = $this->resolver->resolveExpressionType($dynamicCall, $method, $ast);

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

    public function testResolveNullableTraitStaticReturnTypeToCallingClass(): void
    {
        $resolver = $this->createResolverWithFixtures();
        $ast = $this->parseFixture('src/TypeInference/TraitStaticReturn.php');
        $method = $this->findMethodByName($ast, 'callNullableTraitStaticMethod');
        $finder = new \PhpParser\NodeFinder();
        $staticCall = $finder->findFirstInstanceOf($method, Expr\StaticCall::class);
        assert($staticCall !== null);

        $type = $resolver->resolveExpressionType($staticCall, $method, $ast);

        // The trait method returns `?static`, which should resolve to ?ConcreteService
        self::assertInstanceOf(UnionType::class, $type);
        self::assertTrue($type->isNullable());
        $classNames = $type->getResolvableClassNames();
        self::assertCount(1, $classNames);
        self::assertSame('Fixtures\\Traits\\ConcreteService', $classNames[0]->fqn);
    }

    public function testResolveTraitSelfReturnTypeToCallingClass(): void
    {
        $resolver = $this->createResolverWithFixtures();
        $ast = $this->parseFixture('src/TypeInference/TraitStaticReturn.php');
        // getInstance() returns `self`, testing that self in traits resolves to the using class
        $method = $this->findMethodByName($ast, 'callTraitStaticMethod');

        $type = $resolver->resolveExpressionType(
            new Expr\StaticCall(
                new Name\FullyQualified('Fixtures\\Traits\\ConcreteService'),
                'getInstance',
            ),
            $method,
            $ast,
        );

        // `self` in trait resolves to the using class
        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame('Fixtures\\Traits\\ConcreteService', $type->fqn);
    }

    public function testResolveIntersectionReturnType(): void
    {
        $resolver = $this->createResolverWithFixtures();
        $ast = $this->parseFixture('src/TypeInference/IntersectionReturn.php');
        $method = $this->findMethodByName($ast, 'getIterableCounter');

        $type = $resolver->resolveExpressionType(
            new Expr\StaticCall(
                new Name\FullyQualified('Fixtures\\TypeInference\\IntersectionReturn'),
                'getIterableCounter',
            ),
            $method,
            $ast,
        );

        self::assertInstanceOf(IntersectionType::class, $type);
        $classNames = $type->getResolvableClassNames();
        self::assertCount(2, $classNames);
        self::assertSame('Iterator', $classNames[0]->fqn);
        self::assertSame('Countable', $classNames[1]->fqn);
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
