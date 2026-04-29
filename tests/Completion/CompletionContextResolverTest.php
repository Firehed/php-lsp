<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionContext;
use Firehed\PhpLsp\Completion\CompletionContextResolver;
use Firehed\PhpLsp\Completion\MemberAccessContext;
use Firehed\PhpLsp\Completion\StaticAccessContext;
use PhpParser\ErrorHandler;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionContextResolver::class)]
#[CoversClass(CompletionContext::class)]
#[CoversClass(MemberAccessContext::class)]
#[CoversClass(StaticAccessContext::class)]
class CompletionContextResolverTest extends TestCase
{
    private CompletionContextResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CompletionContextResolver();
    }

    public function testThisMemberAccessArrow(): void
    {
        $code = '<?php $this->';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame(CompletionContext::ThisMember, $result->context);
        self::assertInstanceOf(Variable::class, $result->var);
        self::assertSame('', $result->prefix);
    }

    public function testThisMemberAccessNullsafe(): void
    {
        $code = '<?php $this?->';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame(CompletionContext::ThisMember, $result->context);
        self::assertInstanceOf(Variable::class, $result->var);
        self::assertSame('', $result->prefix);
    }

    public function testThisMemberAccessWithPrefix(): void
    {
        $code = '<?php $this->get';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame(CompletionContext::ThisMember, $result->context);
        self::assertSame('get', $result->prefix);
    }

    public function testThisMemberAccessNullsafeWithPrefix(): void
    {
        $code = '<?php $this?->get';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame(CompletionContext::ThisMember, $result->context);
        self::assertSame('get', $result->prefix);
    }

    public function testVariableMemberAccessArrow(): void
    {
        $code = '<?php $obj->';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame(CompletionContext::VariableMember, $result->context);
        self::assertInstanceOf(Variable::class, $result->var);
        self::assertSame('obj', $result->var->name);
        self::assertSame('', $result->prefix);
    }

    public function testVariableMemberAccessNullsafe(): void
    {
        $code = '<?php $obj?->';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame(CompletionContext::VariableMember, $result->context);
        self::assertInstanceOf(Variable::class, $result->var);
        self::assertSame('obj', $result->var->name);
        self::assertSame('', $result->prefix);
    }

    public function testVariableMemberAccessWithPrefix(): void
    {
        $code = '<?php $user->getName';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame(CompletionContext::VariableMember, $result->context);
        self::assertInstanceOf(Variable::class, $result->var);
        self::assertSame('user', $result->var->name);
        self::assertSame('getName', $result->prefix);
    }

    public function testStaticAccessSelf(): void
    {
        $code = '<?php self::';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(StaticAccessContext::class, $result);
        self::assertSame(CompletionContext::StaticMember, $result->context);
        self::assertSame('self', $result->class->toString());
    }

    public function testStaticAccessStatic(): void
    {
        $code = '<?php static::';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(StaticAccessContext::class, $result);
        self::assertSame(CompletionContext::StaticMember, $result->context);
        self::assertSame('static', $result->class->toString());
    }

    public function testStaticAccessParent(): void
    {
        $code = '<?php parent::';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(StaticAccessContext::class, $result);
        self::assertSame(CompletionContext::ParentMember, $result->context);
        self::assertSame('parent', $result->class->toString());
    }

    public function testStaticAccessClassName(): void
    {
        $code = '<?php DateTime::';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(StaticAccessContext::class, $result);
        self::assertSame(CompletionContext::StaticMember, $result->context);
        self::assertSame('DateTime', $result->class->toString());
    }

    public function testStaticAccessWithPrefix(): void
    {
        $code = '<?php DateTime::create';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(StaticAccessContext::class, $result);
        self::assertSame(CompletionContext::StaticMember, $result->context);
        self::assertSame('create', $result->prefix);
    }

    public function testNoContextForPlainCode(): void
    {
        $code = '<?php $x = 1;';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNull($result);
    }

    public function testStaticPropertyFetch(): void
    {
        $code = '<?php self::$staticProp';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(StaticAccessContext::class, $result);
        self::assertSame(CompletionContext::StaticMember, $result->context);
        self::assertSame('staticProp', $result->prefix);
    }

    public function testClassConstFetch(): void
    {
        $code = '<?php DateTime::ATOM';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertInstanceOf(StaticAccessContext::class, $result);
        self::assertSame(CompletionContext::StaticMember, $result->context);
        self::assertSame('ATOM', $result->prefix);
    }

    public function testDynamicMemberNameReturnsNull(): void
    {
        $code = '<?php $obj->$prop';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNull($result);
    }

    public function testChainedMethodCallReturnsNull(): void
    {
        $code = '<?php $obj->method()->';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        // Returns null because the var is a MethodCall, not a Variable
        self::assertNull($result);
    }

    public function testDynamicClassReturnsNull(): void
    {
        $code = '<?php $class::method()';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNull($result);
    }

    /**
     * @return array<Stmt>
     */
    private function parse(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $errorHandler = new ErrorHandler\Collecting();
        $ast = $parser->parse($code, $errorHandler) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        /** @var array<Stmt> */
        return $traverser->traverse($ast);
    }
}
