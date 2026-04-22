<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Repository;

use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(DefaultClassInfoFactory::class)]
final class DefaultClassInfoFactoryTest extends TestCase
{
    private DefaultClassInfoFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new DefaultClassInfoFactory();
    }

    public function testFromAstNodeExtractsClassName(): void
    {
        $node = $this->parseClass('<?php namespace App; class MyClass {}');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertSame('App\\MyClass', $info->name->fqn);
        self::assertSame(ClassKind::Class_, $info->kind);
    }

    public function testFromAstNodeExtractsInterface(): void
    {
        $node = $this->parseClass('<?php interface MyInterface {}');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertSame(ClassKind::Interface_, $info->kind);
    }

    public function testFromAstNodeExtractsTrait(): void
    {
        $node = $this->parseClass('<?php trait MyTrait {}');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertSame(ClassKind::Trait_, $info->kind);
    }

    public function testFromAstNodeExtractsEnum(): void
    {
        $node = $this->parseClass('<?php enum Status { case Active; }');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertSame(ClassKind::Enum_, $info->kind);
        self::assertCount(1, $info->enumCases);
        self::assertArrayHasKey('Active', $info->enumCases);
    }

    public function testFromAstNodeExtractsParentClass(): void
    {
        $node = $this->parseClass('<?php namespace App; class Child extends Parent_ {}');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertNotNull($info->parent);
        self::assertSame('App\\Parent_', $info->parent->fqn);
    }

    public function testFromAstNodeExtractsAbstractClass(): void
    {
        $node = $this->parseClass('<?php abstract class Base {}');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertTrue($info->isAbstract);
    }

    public function testFromAstNodeExtractsFinalClass(): void
    {
        $node = $this->parseClass('<?php final class Sealed {}');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertTrue($info->isFinal);
    }

    public function testFromAstNodeExtractsReadonlyClass(): void
    {
        $node = $this->parseClass('<?php readonly class Immutable {}');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertTrue($info->isReadonly);
    }

    public function testFromAstNodeExtractsInterfaces(): void
    {
        $node = $this->parseClass('<?php class MyClass implements \Stringable, \Countable {
            public function __toString(): string { return ""; }
            public function count(): int { return 0; }
        }');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertCount(2, $info->interfaces);
        self::assertSame('Stringable', $info->interfaces[0]->fqn);
        self::assertSame('Countable', $info->interfaces[1]->fqn);
    }

    public function testFromAstNodeExtractsInterfaceExtends(): void
    {
        $node = $this->parseClass('<?php interface MyInterface extends \Countable {}');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertSame(ClassKind::Interface_, $info->kind);
        self::assertCount(1, $info->interfaces);
        self::assertSame('Countable', $info->interfaces[0]->fqn);
    }

    public function testFromAstNodeExtractsEnumImplements(): void
    {
        $node = $this->parseClass('<?php enum Status: string implements \JsonSerializable {
            case Active = "active";
            public function jsonSerialize(): string { return $this->value; }
        }');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertSame(ClassKind::Enum_, $info->kind);
        self::assertCount(1, $info->interfaces);
        self::assertSame('JsonSerializable', $info->interfaces[0]->fqn);
        self::assertCount(1, $info->enumCases);
        self::assertArrayHasKey('Active', $info->enumCases);
        self::assertSame('active', $info->enumCases['Active']->backingValue);
    }

    public function testFromAstNodeExtractsIntBackedEnumCases(): void
    {
        $node = $this->parseClass('<?php enum Priority: int {
            case Low = 1;
            case High = 10;
        }');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertCount(2, $info->enumCases);
        self::assertArrayHasKey('Low', $info->enumCases);
        self::assertArrayHasKey('High', $info->enumCases);
        self::assertSame(1, $info->enumCases['Low']->backingValue);
        self::assertSame(10, $info->enumCases['High']->backingValue);
    }

    public function testFromAstNodeExtractsPureEnumCasesWithNullBackingValue(): void
    {
        $node = $this->parseClass('<?php enum Status { case Active; case Inactive; }');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertCount(2, $info->enumCases);
        self::assertNull($info->enumCases['Active']->backingValue);
        self::assertNull($info->enumCases['Inactive']->backingValue);
    }

    public function testFromAstNodeSynthesizesEnumBuiltinMethods(): void
    {
        $node = $this->parseClass('<?php enum Status { case Active; }');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertArrayHasKey('cases', $info->methods);
        self::assertTrue($info->methods['cases']->isStatic);
        self::assertSame('array', $info->methods['cases']->returnType);
    }

    public function testFromAstNodeSynthesizesBackedEnumMethods(): void
    {
        $node = $this->parseClass('<?php enum Priority: int {
            case Low = 1;
        }');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertArrayHasKey('cases', $info->methods);
        self::assertArrayHasKey('from', $info->methods);
        self::assertArrayHasKey('tryFrom', $info->methods);

        self::assertTrue($info->methods['from']->isStatic);
        self::assertSame('static', $info->methods['from']->returnType);
        self::assertCount(1, $info->methods['from']->parameters);
        self::assertSame('int', $info->methods['from']->parameters[0]->type);

        self::assertSame('?static', $info->methods['tryFrom']->returnType);
    }

    public function testFromAstNodeExtractsTraits(): void
    {
        $node = $this->parseClass('<?php
            trait MyTrait {}
            class MyClass { use MyTrait; }
        ', 'MyClass');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertCount(1, $info->traits);
        self::assertSame('MyTrait', $info->traits[0]->fqn);
    }

    public function testFromAstNodeExtractsMethods(): void
    {
        $node = $this->parseClass('<?php class MyClass {
            public function publicMethod(): void {}
            protected function protectedMethod(): string { return ""; }
            private static function privateStaticMethod(): int { return 0; }
        }');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertCount(3, $info->methods);
        self::assertArrayHasKey('publicMethod', $info->methods);
        self::assertArrayHasKey('protectedMethod', $info->methods);
        self::assertArrayHasKey('privateStaticMethod', $info->methods);

        self::assertSame(Visibility::Public, $info->methods['publicMethod']->visibility);
        self::assertSame(Visibility::Protected, $info->methods['protectedMethod']->visibility);
        self::assertSame(Visibility::Private, $info->methods['privateStaticMethod']->visibility);
        self::assertTrue($info->methods['privateStaticMethod']->isStatic);
        self::assertSame('void', $info->methods['publicMethod']->returnType);
        self::assertSame('string', $info->methods['protectedMethod']->returnType);
    }

    public function testFromAstNodeExtractsMethodParameters(): void
    {
        $node = $this->parseClass('<?php class MyClass {
            public function withParams(string $name, int $count = 0, string ...$items): void {}
        }');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');
        $method = $info->methods['withParams'];

        self::assertCount(3, $method->parameters);
        self::assertSame('name', $method->parameters[0]->name);
        self::assertSame('string', $method->parameters[0]->type);
        self::assertFalse($method->parameters[0]->hasDefault);
        self::assertFalse($method->parameters[0]->isVariadic);

        self::assertSame('count', $method->parameters[1]->name);
        self::assertTrue($method->parameters[1]->hasDefault);

        self::assertSame('items', $method->parameters[2]->name);
        self::assertTrue($method->parameters[2]->isVariadic);
    }

    public function testFromAstNodeExtractsProperties(): void
    {
        $node = $this->parseClass('<?php class MyClass {
            public string $publicProp;
            protected int $protectedProp;
            private static bool $privateStaticProp;
            public readonly string $readonlyProp;
        }');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertCount(4, $info->properties);
        self::assertArrayHasKey('publicProp', $info->properties);
        self::assertSame(Visibility::Public, $info->properties['publicProp']->visibility);
        self::assertSame('string', $info->properties['publicProp']->type);
        self::assertFalse($info->properties['publicProp']->isPromoted);

        self::assertTrue($info->properties['privateStaticProp']->isStatic);
        self::assertTrue($info->properties['readonlyProp']->isReadonly);
    }

    public function testFromAstNodeExtractsPromotedProperties(): void
    {
        $node = $this->parseClass('<?php class MyClass {
            public function __construct(
                public string $name,
                private readonly int $id,
            ) {}
        }');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertCount(2, $info->properties);
        self::assertArrayHasKey('name', $info->properties);
        self::assertArrayHasKey('id', $info->properties);

        self::assertTrue($info->properties['name']->isPromoted);
        self::assertSame(Visibility::Public, $info->properties['name']->visibility);

        self::assertTrue($info->properties['id']->isPromoted);
        self::assertTrue($info->properties['id']->isReadonly);
        self::assertSame(Visibility::Private, $info->properties['id']->visibility);
    }

    public function testFromAstNodeExtractsConstants(): void
    {
        $node = $this->parseClass('<?php class MyClass {
            public const string PUBLIC_CONST = "value";
            protected const PROTECTED_CONST = 123;
            private const PRIVATE_CONST = true;
        }');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertCount(3, $info->constants);
        self::assertArrayHasKey('PUBLIC_CONST', $info->constants);
        self::assertSame(Visibility::Public, $info->constants['PUBLIC_CONST']->visibility);
        self::assertSame('string', $info->constants['PUBLIC_CONST']->type);

        self::assertSame(Visibility::Protected, $info->constants['PROTECTED_CONST']->visibility);
        self::assertSame(Visibility::Private, $info->constants['PRIVATE_CONST']->visibility);
    }

    public function testFromAstNodeExtractsDocblock(): void
    {
        $node = $this->parseClass('<?php
        /** This is a docblock */
        class MyClass {}');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertStringContainsString('This is a docblock', $info->docblock ?? '');
    }

    public function testFromAstNodeExtractsFileInfo(): void
    {
        $node = $this->parseClass('<?php class MyClass {}');

        $info = $this->factory->fromAstNode($node, 'file:///path/to/test.php');

        self::assertSame('/path/to/test.php', $info->file);
        self::assertNotNull($info->line);
    }

    public function testFromAstNodeThrowsForAnonymousClass(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php $x = new class {};');
        assert($ast !== null);

        $expr = $ast[0];
        assert($expr instanceof \PhpParser\Node\Stmt\Expression);
        $assign = $expr->expr;
        assert($assign instanceof \PhpParser\Node\Expr\Assign);
        $new = $assign->expr;
        assert($new instanceof \PhpParser\Node\Expr\New_);
        $node = $new->class;
        assert($node instanceof Stmt\Class_);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('anonymous class');

        $this->factory->fromAstNode($node, 'file:///test.php');
    }

    public function testFromReflectionExtractsBasicInfo(): void
    {
        $reflection = new ReflectionClass(\stdClass::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertSame(\stdClass::class, $info->name->fqn);
        self::assertSame(ClassKind::Class_, $info->kind);
    }

    public function testFromReflectionExtractsInterface(): void
    {
        $reflection = new ReflectionClass(\Iterator::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertSame(ClassKind::Interface_, $info->kind);
    }

    public function testFromReflectionExtractsTrait(): void
    {
        $reflection = new ReflectionClass(TestTrait::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertSame(ClassKind::Trait_, $info->kind);
    }

    public function testFromReflectionExtractsEnum(): void
    {
        $reflection = new ReflectionClass(TestEnum::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertSame(ClassKind::Enum_, $info->kind);
        self::assertCount(2, $info->enumCases);
        self::assertArrayHasKey('Foo', $info->enumCases);
        self::assertArrayHasKey('Bar', $info->enumCases);
        self::assertNull($info->enumCases['Foo']->backingValue);
    }

    public function testFromReflectionExtractsBackedEnum(): void
    {
        $reflection = new ReflectionClass(TestBackedEnum::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertSame(ClassKind::Enum_, $info->kind);
        self::assertCount(2, $info->enumCases);
        self::assertSame(1, $info->enumCases['Low']->backingValue);
        self::assertSame(10, $info->enumCases['High']->backingValue);
    }

    public function testFromReflectionExtractsEnumBuiltinMethods(): void
    {
        $reflection = new ReflectionClass(TestBackedEnum::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertArrayHasKey('cases', $info->methods);
        self::assertArrayHasKey('from', $info->methods);
        self::assertArrayHasKey('tryFrom', $info->methods);
        self::assertTrue($info->methods['cases']->isStatic);
    }

    public function testFromReflectionExtractsMethods(): void
    {
        $reflection = new ReflectionClass(TestClass::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertArrayHasKey('publicMethod', $info->methods);
        self::assertSame(Visibility::Public, $info->methods['publicMethod']->visibility);
        self::assertSame('void', $info->methods['publicMethod']->returnType);
    }

    public function testFromReflectionExtractsProperties(): void
    {
        $reflection = new ReflectionClass(TestClass::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertArrayHasKey('publicProp', $info->properties);
        self::assertSame(Visibility::Public, $info->properties['publicProp']->visibility);
        self::assertSame('string', $info->properties['publicProp']->type);
    }

    public function testFromReflectionExtractsConstants(): void
    {
        $reflection = new ReflectionClass(TestClass::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertArrayHasKey('TEST_CONST', $info->constants);
        self::assertSame(Visibility::Public, $info->constants['TEST_CONST']->visibility);
    }

    public function testFromReflectionExtractsInterfaces(): void
    {
        $reflection = new ReflectionClass(ClassWithInterface::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertCount(1, $info->interfaces);
        self::assertSame(\Countable::class, $info->interfaces[0]->fqn);
    }

    public function testFromReflectionExtractsTraits(): void
    {
        $reflection = new ReflectionClass(TestClass::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertCount(1, $info->traits);
        self::assertSame(TestTrait::class, $info->traits[0]->fqn);
    }

    private function parseClass(string $code, ?string $className = null): Stmt\ClassLike
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        assert($ast !== null);

        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());
        $ast = $traverser->traverse($ast);

        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                foreach ($stmt->stmts as $nsStmt) {
                    if ($nsStmt instanceof Stmt\ClassLike) {
                        if ($className === null || $nsStmt->name?->toString() === $className) {
                            return $nsStmt;
                        }
                    }
                }
            }
            if ($stmt instanceof Stmt\ClassLike) {
                if ($className === null || $stmt->name?->toString() === $className) {
                    return $stmt;
                }
            }
        }

        throw new \RuntimeException('No class found in code');
    }
}
