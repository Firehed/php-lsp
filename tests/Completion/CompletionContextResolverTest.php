<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionContext;
use Firehed\PhpLsp\Completion\CompletionContextResolver;
use PhpParser\ErrorHandler;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionContextResolver::class)]
#[CoversClass(CompletionContext::class)]
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

        self::assertNotNull($result);
        self::assertSame(CompletionContext::ThisMember, $result['context']);
        self::assertInstanceOf(Variable::class, $result['var']);
        self::assertSame('', $result['prefix']);
    }

    public function testThisMemberAccessNullsafe(): void
    {
        $code = '<?php $this?->';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNotNull($result);
        self::assertSame(CompletionContext::ThisMember, $result['context']);
        self::assertInstanceOf(Variable::class, $result['var']);
        self::assertSame('', $result['prefix']);
    }

    public function testThisMemberAccessWithPrefix(): void
    {
        $code = '<?php $this->get';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNotNull($result);
        self::assertSame(CompletionContext::ThisMember, $result['context']);
        self::assertSame('get', $result['prefix']);
    }

    public function testThisMemberAccessNullsafeWithPrefix(): void
    {
        $code = '<?php $this?->get';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNotNull($result);
        self::assertSame(CompletionContext::ThisMember, $result['context']);
        self::assertSame('get', $result['prefix']);
    }

    public function testVariableMemberAccessArrow(): void
    {
        $code = '<?php $obj->';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNotNull($result);
        self::assertSame(CompletionContext::VariableMember, $result['context']);
        self::assertInstanceOf(Variable::class, $result['var']);
        self::assertSame('obj', $result['var']->name);
        self::assertSame('', $result['prefix']);
    }

    public function testVariableMemberAccessNullsafe(): void
    {
        $code = '<?php $obj?->';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNotNull($result);
        self::assertSame(CompletionContext::VariableMember, $result['context']);
        self::assertInstanceOf(Variable::class, $result['var']);
        self::assertSame('obj', $result['var']->name);
        self::assertSame('', $result['prefix']);
    }

    public function testVariableMemberAccessWithPrefix(): void
    {
        $code = '<?php $user->getName';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNotNull($result);
        self::assertSame(CompletionContext::VariableMember, $result['context']);
        self::assertSame('user', $result['var']->name);
        self::assertSame('getName', $result['prefix']);
    }

    public function testStaticAccessSelf(): void
    {
        $code = '<?php self::';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNotNull($result);
        self::assertSame(CompletionContext::StaticMember, $result['context']);
        self::assertInstanceOf(Name::class, $result['class']);
        self::assertSame('self', $result['class']->toString());
    }

    public function testStaticAccessStatic(): void
    {
        $code = '<?php static::';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNotNull($result);
        self::assertSame(CompletionContext::StaticMember, $result['context']);
        self::assertSame('static', $result['class']->toString());
    }

    public function testStaticAccessParent(): void
    {
        $code = '<?php parent::';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNotNull($result);
        self::assertSame(CompletionContext::ParentMember, $result['context']);
        self::assertSame('parent', $result['class']->toString());
    }

    public function testStaticAccessClassName(): void
    {
        $code = '<?php DateTime::';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNotNull($result);
        self::assertSame(CompletionContext::StaticMember, $result['context']);
        self::assertSame('DateTime', $result['class']->toString());
    }

    public function testStaticAccessWithPrefix(): void
    {
        $code = '<?php DateTime::create';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNotNull($result);
        self::assertSame(CompletionContext::StaticMember, $result['context']);
        self::assertSame('create', $result['prefix']);
    }

    public function testNoContextForPlainCode(): void
    {
        $code = '<?php $x = 1;';
        $ast = $this->parse($code);
        $offset = strlen($code);

        $result = $this->resolver->resolve($ast, $offset);

        self::assertNull($result);
    }

    /**
     * @return array<\PhpParser\Node\Stmt>
     */
    private function parse(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $errorHandler = new ErrorHandler\Collecting();
        $ast = $parser->parse($code, $errorHandler) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        return $traverser->traverse($ast);
    }
}
