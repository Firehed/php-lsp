<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Resolution\ParameterTyping;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParameterTyping::class)]
final class ParameterTypingTest extends TestCase
{
    public function testClassMethodRoutesThroughForMethodParameter(): void
    {
        $ast = self::parseWithParents('<?php class C { public function m(string $x): void {} }');
        $method = self::findNode($ast, Stmt\ClassMethod::class);
        $param = self::findNode($ast, Param::class);
        $expected = new PrimitiveType('string');

        $typeSource = self::createStub(TypeSourceInterface::class);
        $typeSource
            ->method('forMethodParameter')
            ->willReturn($expected);

        $type = ParameterTyping::resolve(
            $typeSource,
            $param,
            'x',
            $method,
            new ClassName('C'),
            'C',
            null,
        );

        self::assertSame($expected, $type, 'ClassMethod scope routes through TypeSourceInterface::forMethodParameter');
    }

    public function testFunctionRoutesThroughForFunctionParameter(): void
    {
        $ast = self::parseWithParents('<?php function fn1(string $x): void {}');
        $fn = self::findNode($ast, Stmt\Function_::class);
        $param = self::findNode($ast, Param::class);
        $expected = new PrimitiveType('string');

        $typeSource = self::createStub(TypeSourceInterface::class);
        $typeSource
            ->method('forFunctionParameter')
            ->willReturn($expected);

        $type = ParameterTyping::resolve(
            $typeSource,
            $param,
            'x',
            $fn,
            null,
            null,
            null,
        );

        self::assertSame($expected, $type, 'Function_ scope routes through TypeSourceInterface::forFunctionParameter');
    }

    public function testClosureFallsThroughToTypeFactory(): void
    {
        $ast = self::parseWithParents('<?php $c = function (string $x) {};');
        $closure = self::findNode($ast, Closure::class);
        $param = self::findNode($ast, Param::class);

        $typeSource = self::createStub(TypeSourceInterface::class);

        $type = ParameterTyping::resolve(
            $typeSource,
            $param,
            'x',
            $closure,
            null,
            null,
            null,
        );

        self::assertInstanceOf(
            PrimitiveType::class,
            $type,
            'closure scope falls through to TypeFactory::fromNode, preserving the declared type',
        );
        self::assertSame('string', $type->format());
    }

    public function testArrowFunctionFallsThroughToTypeFactory(): void
    {
        $ast = self::parseWithParents('<?php $c = fn (int $x): int => $x;');
        $arrow = self::findNode($ast, ArrowFunction::class);
        $param = self::findNode($ast, Param::class);

        $typeSource = self::createStub(TypeSourceInterface::class);

        $type = ParameterTyping::resolve(
            $typeSource,
            $param,
            'x',
            $arrow,
            null,
            null,
            null,
        );

        self::assertInstanceOf(
            PrimitiveType::class,
            $type,
            'arrow-function scope falls through to TypeFactory::fromNode',
        );
        self::assertSame('int', $type->format());
    }

    public function testClassMethodWithoutEnclosingClassFallsThrough(): void
    {
        $ast = self::parseWithParents('<?php class C { public function m(string $x): void {} }');
        $method = self::findNode($ast, Stmt\ClassMethod::class);
        $param = self::findNode($ast, Param::class);

        $typeSource = self::createStub(TypeSourceInterface::class);

        $type = ParameterTyping::resolve(
            $typeSource,
            $param,
            'x',
            $method,
            null,
            null,
            null,
        );

        self::assertInstanceOf(
            PrimitiveType::class,
            $type,
            'ClassMethod without a resolved enclosing class falls through rather than passing null identity',
        );
        self::assertSame('string', $type->format());
    }

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
     * @template T of \PhpParser\Node
     * @param array<Stmt> $ast
     * @param class-string<T> $class
     * @return T
     */
    private static function findNode(array $ast, string $class)
    {
        $found = (new NodeFinder())->findFirstInstanceOf($ast, $class);
        assert($found instanceof $class, 'fixture must contain a ' . $class);
        return $found;
    }
}
