<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberAccessResolver::class)]
class MemberAccessResolverTest extends TestCase
{
    private MemberAccessResolver $resolver;

    protected function setUp(): void
    {
        $classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $parser = new ParserService();
        $classRepository = new DefaultClassRepository($classInfoFactory, $locator, $parser);
        $memberResolver = new MemberResolver($classRepository);
        $typeResolver = new BasicTypeResolver($memberResolver);

        $this->resolver = new MemberAccessResolver($memberResolver, $typeResolver);
    }

    public function testResolveMethodCall(): void
    {
        $code = <<<'PHP'
<?php
function test(\Exception $e) {
    $e->getMessage();
}
PHP;
        $ast = $this->parse($code);
        $call = $this->findFirst($ast, MethodCall::class);

        $result = $this->resolver->resolveMethodCall($call, $ast);

        self::assertInstanceOf(MethodInfo::class, $result);
        self::assertSame('getMessage', $result->name->name);
    }

    public function testResolveNullsafeMethodCall(): void
    {
        $code = <<<'PHP'
<?php
function test(?\Exception $e) {
    $e?->getMessage();
}
PHP;
        $ast = $this->parse($code);
        $call = $this->findFirst($ast, NullsafeMethodCall::class);

        $result = $this->resolver->resolveMethodCall($call, $ast);

        self::assertInstanceOf(MethodInfo::class, $result);
        self::assertSame('getMessage', $result->name->name);
    }

    public function testResolvePropertyFetch(): void
    {
        $code = <<<'PHP'
<?php
function test(\Exception $e) {
    $e->message;
}
PHP;
        $ast = $this->parse($code);
        $fetch = $this->findFirst($ast, PropertyFetch::class);

        // Exception::$message is protected, so null expected with Public visibility
        $result = $this->resolver->resolvePropertyFetch($fetch, $ast);

        self::assertNull($result);
    }

    public function testResolveNullsafePropertyFetch(): void
    {
        $code = <<<'PHP'
<?php
function test(?\Exception $e) {
    $e?->message;
}
PHP;
        $ast = $this->parse($code);
        $fetch = $this->findFirst($ast, NullsafePropertyFetch::class);

        // Exception::$message is protected, so null expected with Public visibility
        $result = $this->resolver->resolvePropertyFetch($fetch, $ast);

        self::assertNull($result);
    }

    public function testIsMethodCall(): void
    {
        self::assertTrue(MemberAccessResolver::isMethodCall(new MethodCall(
            new \PhpParser\Node\Expr\Variable('x'),
            'foo'
        )));
        self::assertTrue(MemberAccessResolver::isMethodCall(new NullsafeMethodCall(
            new \PhpParser\Node\Expr\Variable('x'),
            'foo'
        )));
        self::assertFalse(MemberAccessResolver::isMethodCall(new PropertyFetch(
            new \PhpParser\Node\Expr\Variable('x'),
            'foo'
        )));
    }

    public function testIsPropertyFetch(): void
    {
        self::assertTrue(MemberAccessResolver::isPropertyFetch(new PropertyFetch(
            new \PhpParser\Node\Expr\Variable('x'),
            'foo'
        )));
        self::assertTrue(MemberAccessResolver::isPropertyFetch(new NullsafePropertyFetch(
            new \PhpParser\Node\Expr\Variable('x'),
            'foo'
        )));
        self::assertFalse(MemberAccessResolver::isPropertyFetch(new MethodCall(
            new \PhpParser\Node\Expr\Variable('x'),
            'foo'
        )));
    }

    public function testDynamicMethodNameReturnsNull(): void
    {
        $code = <<<'PHP'
<?php
function test(\Exception $e, string $method) {
    $e->$method();
}
PHP;
        $ast = $this->parse($code);
        $call = $this->findFirst($ast, MethodCall::class);

        $result = $this->resolver->resolveMethodCall($call, $ast);

        self::assertNull($result);
    }

    public function testDynamicPropertyNameReturnsNull(): void
    {
        $code = <<<'PHP'
<?php
function test(\Exception $e, string $prop) {
    $e->$prop;
}
PHP;
        $ast = $this->parse($code);
        $fetch = $this->findFirst($ast, PropertyFetch::class);

        $result = $this->resolver->resolvePropertyFetch($fetch, $ast);

        self::assertNull($result);
    }

    public function testPrimitiveTypeReturnsNull(): void
    {
        $code = <<<'PHP'
<?php
function test(string $str) {
    $str->foo;
}
PHP;
        $ast = $this->parse($code);
        $fetch = $this->findFirst($ast, PropertyFetch::class);

        $result = $this->resolver->resolvePropertyFetch($fetch, $ast);

        self::assertNull($result);
    }

    public function testMethodCallOnPrimitiveTypeReturnsNull(): void
    {
        $code = <<<'PHP'
<?php
function test(string $str) {
    $str->foo();
}
PHP;
        $ast = $this->parse($code);
        $call = $this->findFirst($ast, MethodCall::class);

        $result = $this->resolver->resolveMethodCall($call, $ast);

        self::assertNull($result);
    }

    public function testUnresolvedVariableTypeReturnsNull(): void
    {
        $code = <<<'PHP'
<?php
$unknown->foo();
PHP;
        $ast = $this->parse($code);
        $call = $this->findFirst($ast, MethodCall::class);

        $result = $this->resolver->resolveMethodCall($call, $ast);

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
        $finder = new class ($type) extends NodeVisitorAbstract {
            public ?\PhpParser\Node $found = null;
            /** @var class-string */
            private string $type;

            /**
             * @param class-string $type
             */
            public function __construct(string $type)
            {
                $this->type = $type;
            }

            public function enterNode(\PhpParser\Node $node): ?int
            {
                if ($node instanceof $this->type && $this->found === null) {
                    $this->found = $node;
                    return NodeTraverser::STOP_TRAVERSAL;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        if ($finder->found === null) {
            throw new \RuntimeException("Could not find node of type $type");
        }
        assert($finder->found instanceof $type);
        return $finder->found;
    }
}
