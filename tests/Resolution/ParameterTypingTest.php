<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\UnionType;
use Firehed\PhpLsp\Resolution\ParameterTyping;
use Firehed\PhpLsp\Resolution\Scope;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParameterTyping::class)]
final class ParameterTypingTest extends TestCase
{
    use LoadsFixturesTrait;

    public function testClassMethodRoutesThroughForMethodParameter(): void
    {
        $ast = self::parseWithParents('<?php class C { public function m(string $x): void {} }');
        $param = self::findNode($ast, Param::class);
        $expected = PrimitiveType::String;

        $typeSource = self::createStub(TypeSourceInterface::class);
        $typeSource
            ->method('forMethodParameter')
            ->willReturn($expected);

        $type = ParameterTyping::resolve($typeSource, $param, 'x', Scope::at($param, $ast));

        self::assertSame($expected, $type, 'ClassMethod scope routes through TypeSourceInterface::forMethodParameter');
    }

    public function testFunctionRoutesThroughForFunctionParameter(): void
    {
        $ast = self::parseWithParents('<?php function fn1(string $x): void {}');
        $param = self::findNode($ast, Param::class);
        $expected = PrimitiveType::String;

        $typeSource = self::createStub(TypeSourceInterface::class);
        $typeSource
            ->method('forFunctionParameter')
            ->willReturn($expected);

        $type = ParameterTyping::resolve($typeSource, $param, 'x', Scope::at($param, $ast));

        self::assertSame($expected, $type, 'Function_ scope routes through TypeSourceInterface::forFunctionParameter');
    }

    public function testClosureFallsThroughToTypeFactory(): void
    {
        $ast = self::parseWithParents('<?php $c = function (string $x) {};');
        $param = self::findNode($ast, Param::class);

        $type = ParameterTyping::resolve(
            self::createStub(TypeSourceInterface::class),
            $param,
            'x',
            Scope::at($param, $ast),
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
        $param = self::findNode($ast, Param::class);

        $type = ParameterTyping::resolve(
            self::createStub(TypeSourceInterface::class),
            $param,
            'x',
            Scope::at($param, $ast),
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
        $ast = self::parseWithParents($this->loadFixture('src/Resolution/AnonymousParent.php'));
        $param = self::parameterNamed($ast, 'anonymousTyped');

        $type = ParameterTyping::resolve(
            self::createStub(TypeSourceInterface::class),
            $param,
            'anonymousTyped',
            Scope::at($param, $ast),
        );

        self::assertInstanceOf(
            PrimitiveType::class,
            $type,
            'ClassMethod without a resolved enclosing class falls through rather than passing null identity',
        );
        self::assertSame('string', $type->format());
    }

    /**
     * @return iterable<string, array{string, string, TypeInterface}>
     */
    public static function lateBoundParameters(): iterable
    {
        $closures = 'src/Resolution/ClosureLateBound.php';
        $parentClass = new ClasslikeType(ClasslikeName::fromFullyQualified('Fixtures\Inheritance\ParentClass'));
        yield 'self in a closure' => [
            $closures,
            'own',
            new ClasslikeType(ClasslikeName::fromFullyQualified('Fixtures\Resolution\ClosureLateBound')),
        ];
        yield 'nullable parent in an arrow function' => [
            $closures,
            'inherited',
            new UnionType([$parentClass, PrimitiveType::Null]),
        ];
        yield 'parent in an anonymous class' => [
            'src/Resolution/AnonymousParent.php',
            'anonymousInherited',
            $parentClass,
        ];
    }

    #[DataProvider('lateBoundParameters')]
    public function testAKeywordInAClosureParameterNamesWhatTheScopeBindsItTo(
        string $fixture,
        string $name,
        TypeInterface $expected,
    ): void {
        $ast = self::parseWithParents($this->loadFixture($fixture));
        $param = self::parameterNamed($ast, $name);

        self::assertEquals(
            $expected,
            ParameterTyping::resolve(
                self::createStub(TypeSourceInterface::class),
                $param,
                $name,
                Scope::at($param, $ast),
            ),
            'the keyword names the class the scope binds it to',
        );
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
     * @param array<Stmt> $ast
     */
    private static function parameterNamed(array $ast, string $name): Param
    {
        $param = (new NodeFinder())->findFirst(
            $ast,
            static fn ($node): bool => $node instanceof Param
                && $node->var instanceof Variable
                && $node->var->name === $name,
        );
        self::assertInstanceOf(Param::class, $param, "the fixture declares \${$name}");
        return $param;
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
