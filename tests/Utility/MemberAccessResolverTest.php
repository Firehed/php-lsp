<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberAccessResolver::class)]
class MemberAccessResolverTest extends TestCase
{
    use LoadsFixturesTrait;

    private MemberAccessResolver $resolver;

    protected function setUp(): void
    {
        $classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $parser = new ParserService();
        $classRepository = new DefaultClassRepository($classInfoFactory, $locator, $parser);
        $memberResolver = new MemberResolver($classRepository);
        $typeResolver = new BasicTypeResolver($memberResolver);

        $this->resolver = new MemberAccessResolver($typeResolver, $memberResolver);
    }

    public function testResolveObjectClassName(): void
    {
        $code = $this->loadFixture('src/TypeInference/BuiltinTypes.php');
        $ast = $this->parse($code);
        $methodCall = $this->findFirst($ast, MethodCall::class);

        $result = $this->resolver->resolveObjectClassName($methodCall->var, $ast);

        self::assertNotNull($result);
        self::assertSame('DateTime', $result->fqn);
    }

    public function testResolveObjectClassNameReturnsNullForUnknownVariable(): void
    {
        $code = $this->loadFixture('src/TypeInference/StaticCallOutsideClass.php');
        $ast = $this->parse($code);
        $methodCall = $this->findFirst($ast, MethodCall::class);

        $result = $this->resolver->resolveObjectClassName($methodCall->var, $ast);

        self::assertNull($result);
    }

    public function testIsMethodCall(): void
    {
        self::assertTrue(MemberAccessResolver::isMethodCall(new MethodCall(
            new Variable('x'),
            'foo'
        )));
        self::assertTrue(MemberAccessResolver::isMethodCall(new NullsafeMethodCall(
            new Variable('x'),
            'foo'
        )));
        self::assertFalse(MemberAccessResolver::isMethodCall(new PropertyFetch(
            new Variable('x'),
            'foo'
        )));
    }

    public function testIsPropertyFetch(): void
    {
        self::assertTrue(MemberAccessResolver::isPropertyFetch(new PropertyFetch(
            new Variable('x'),
            'foo'
        )));
        self::assertTrue(MemberAccessResolver::isPropertyFetch(new NullsafePropertyFetch(
            new Variable('x'),
            'foo'
        )));
        self::assertFalse(MemberAccessResolver::isPropertyFetch(new MethodCall(
            new Variable('x'),
            'foo'
        )));
    }

    public function testResolvePropertyFetch(): void
    {
        $code = $this->loadFixture('src/TypeInference/BuiltinTypes.php');
        $ast = $this->parse($code);
        $propertyFetch = $this->findFirst($ast, NullsafePropertyFetch::class);

        $result = $this->resolver->resolvePropertyFetch($propertyFetch, $ast);

        self::assertNotNull($result);
        self::assertSame('message', $result->name->name);
    }

    public function testResolvePropertyFetchReturnsNullForUnknownType(): void
    {
        $code = $this->loadFixture('EdgeCases/UnknownTypeProperty.php');
        $ast = $this->parse($code);
        $propertyFetch = $this->findFirst($ast, PropertyFetch::class);

        $result = $this->resolver->resolvePropertyFetch($propertyFetch, $ast);

        self::assertNull($result);
    }

    public function testResolvePropertyFetchReturnsNullForDynamicPropertyName(): void
    {
        $code = $this->loadFixture('EdgeCases/DynamicAccess.php');
        $ast = $this->parse($code);
        $propertyFetches = $this->findAll($ast, PropertyFetch::class);
        $dynamicFetch = $propertyFetches[count($propertyFetches) - 1];

        $result = $this->resolver->resolvePropertyFetch($dynamicFetch, $ast);

        self::assertNull($result);
    }

    public function testResolveStaticPropertyFetchReturnsNullForDynamicClassName(): void
    {
        $code = $this->loadFixture('EdgeCases/DynamicAccess.php');
        $ast = $this->parse($code);
        $staticFetches = $this->findAll($ast, StaticPropertyFetch::class);
        $dynamicFetch = $staticFetches[count($staticFetches) - 1];

        $result = $this->resolver->resolveStaticPropertyFetch($dynamicFetch);

        self::assertNull($result);
    }

    /**
     * @return array<Stmt>
     */
    private function parse(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        /** @var array<Stmt> */
        return $traverser->traverse($ast);
    }

    /**
     * @template T of \PhpParser\Node
     * @param array<Stmt> $ast
     * @param class-string<T> $type
     * @return T
     */
    private function findFirst(array $ast, string $type): \PhpParser\Node
    {
        $all = $this->findAll($ast, $type);
        if (count($all) === 0) {
            throw new \RuntimeException("Could not find node of type $type");
        }
        return $all[0];
    }

    /**
     * @template T of \PhpParser\Node
     * @param array<Stmt> $ast
     * @param class-string<T> $type
     * @return list<T>
     */
    private function findAll(array $ast, string $type): array
    {
        $finder = new \PhpParser\NodeFinder();
        /** @var list<T> */
        return $finder->findInstanceOf($ast, $type);
    }
}
