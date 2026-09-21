<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution\TypeSource;

use Firehed\PhpLsp\Domain\ClasslikeConstantName;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\UnionType;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\TypeSource\NativeTypeSource;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NativeTypeSource::class)]
final class NativeTypeSourceTest extends TestCase
{
    private NativeTypeSource $source;

    protected function setUp(): void
    {
        $production = ProductionSyntaxSource::create();
        $fixturesRoot = dirname(__DIR__, 2) . '/Fixtures';
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($fixturesRoot),
            $fixturesRoot . '/vendor',
            $production->source,
            $production->reader,
        );
        $this->source = new NativeTypeSource(
            $knowledge->source,
            new MemberResolver($knowledge->source),
        );
    }

    public function testMethodReturnPrimitive(): void
    {
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User');
        $type = $this->source->forMethodReturn($class, new MethodName($class, 'getName'));

        self::assertNotNull($type, 'getName returns a declared string');
        self::assertSame('string', $type->format());
    }

    public function testMethodReturnClasslikeName(): void
    {
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User');
        $type = $this->source->forMethodReturn($class, new MethodName($class, 'getStatus'));

        self::assertInstanceOf(ClasslikeType::class, $type, 'getStatus returns the Status enum');
        self::assertSame('Fixtures\\Enum\\Status', $type->name->qualifiedName->fullyQualifiedName());
    }

    public function testMethodReturnNullableIsUnion(): void
    {
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User');
        $type = $this->source->forMethodReturn($class, new MethodName($class, 'getTeam'));

        self::assertInstanceOf(UnionType::class, $type, '?Team is stored as Team|null');
        self::assertSame('?Fixtures\\Domain\\Team', $type->format());
    }

    public function testMethodReturnInheritedFromTrait(): void
    {
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User');
        $type = $this->source->forMethodReturn($class, new MethodName($class, 'markCreated'));

        self::assertInstanceOf(PrimitiveType::class, $type, 'trait method markCreated is reached via member walk');
        self::assertSame('void', $type->format());
    }

    public function testMethodReturnOnTraitAliasedName(): void
    {
        // The class exposes the trait's method under an alias. Querying the
        // using class must find it via MemberResolver's alias walk; querying
        // the trait with the alias name is blind (the trait doesn't declare
        // the alias). resolveLateBoundReturn depends on the former.
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Hierarchy\\TraitAliasSelfReturnUser');
        $type = $this->source->forMethodReturn($class, new MethodName($class, 'aliasedFluent'));

        self::assertNotNull(
            $type,
            'aliased trait method resolves through MemberResolver.findMethod on the using class',
        );
    }

    public function testMethodReturnLookupOnTraitByAliasedNameIsBlind(): void
    {
        $trait = ClasslikeName::fromFullyQualified('Fixtures\\Hierarchy\\AliasedSelfReturnTrait');
        $type = $this->source->forMethodReturn($trait, new MethodName($trait, 'aliasedFluent'));

        self::assertNull(
            $type,
            'the trait itself does not declare the alias; late-binding rewrite must query the using class instead',
        );
    }

    public function testMethodReturnUnknownClass(): void
    {
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Does\\NotExist');
        $type = $this->source->forMethodReturn($class, new MethodName($class, 'anything'));

        self::assertNull($type, 'unknown class yields no type');
    }

    public function testMethodReturnUnknownMethod(): void
    {
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User');
        $type = $this->source->forMethodReturn($class, new MethodName($class, 'nonexistent'));

        self::assertNull($type, 'unknown method on a known class yields no type');
    }

    public function testMethodParameterPrimitive(): void
    {
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User');
        $type = $this->source->forMethodParameter($class, new MethodName($class, '__construct'), 'age');

        self::assertInstanceOf(PrimitiveType::class, $type, '$age is declared as int');
        self::assertSame('int', $type->format());
    }

    public function testMethodParameterClass(): void
    {
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User');
        $type = $this->source->forMethodParameter($class, new MethodName($class, '__construct'), 'status');

        self::assertInstanceOf(ClasslikeType::class, $type, '$status is declared as Status');
        self::assertSame('Fixtures\\Enum\\Status', $type->name->qualifiedName->fullyQualifiedName());
    }

    public function testMethodParameterUnknownName(): void
    {
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User');
        $type = $this->source->forMethodParameter($class, new MethodName($class, '__construct'), 'unknown');

        self::assertNull($type, 'unknown parameter name yields no type');
    }

    public function testMethodParameterUnknownMethod(): void
    {
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User');
        $type = $this->source->forMethodParameter($class, new MethodName($class, 'nonexistent'), 'anything');

        self::assertNull($type, 'unknown method short-circuits before the parameter loop');
    }

    public function testPropertyPromoted(): void
    {
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User');
        $type = $this->source->forProperty($class, new PropertyName($class, 'name'));

        self::assertInstanceOf(PrimitiveType::class, $type, 'promoted-property $name has an int type');
        self::assertSame('string', $type->format());
    }

    public function testPropertyStatic(): void
    {
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User');
        $type = $this->source->forProperty($class, new PropertyName($class, 'instanceCount'));

        self::assertInstanceOf(PrimitiveType::class, $type, 'static $instanceCount is int');
        self::assertSame('int', $type->format());
    }

    public function testPropertyInheritedFromTrait(): void
    {
        $class = ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User');
        $type = $this->source->forProperty($class, new PropertyName($class, 'displayName'));

        self::assertInstanceOf(PrimitiveType::class, $type, 'trait property is reached via member walk');
        self::assertSame('string', $type->format());
    }

    public function testClassConstantUntypedReturnsNull(): void
    {
        $type = $this->source->forClassConstant(
            ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User'),
            new ClasslikeConstantName('DEFAULT_ROLE'),
        );

        self::assertNull($type, 'untyped class constants have no declared type');
    }

    public function testGlobalConstantUserDeclared(): void
    {
        $type = $this->source->forGlobalConstant(
            ConstantName::fromFullyQualified('Fixtures\\Helpers\\HELPER_LIMIT'),
        );

        self::assertNull($type, 'untyped global const declaration has no declared type');
    }

    public function testGlobalConstantUnknown(): void
    {
        $type = $this->source->forGlobalConstant(
            ConstantName::fromFullyQualified('Some\\Never\\Declared\\THING'),
        );

        self::assertNull($type, 'unknown global constant yields no type');
    }

    public function testFunctionReturnUserDeclared(): void
    {
        $type = $this->source->forFunctionReturn(
            FunctionName::fromFullyQualified('Fixtures\\Helpers\\helperFormat'),
        );

        self::assertInstanceOf(PrimitiveType::class, $type, 'user-declared helperFormat returns string');
        self::assertSame('string', $type->format());
    }

    public function testFunctionReturnBuiltin(): void
    {
        $type = $this->source->forFunctionReturn(
            FunctionName::fromFullyQualified('strlen'),
        );

        self::assertInstanceOf(PrimitiveType::class, $type, 'strlen returns int (via reflection)');
        self::assertSame('int', $type->format());
    }

    public function testFunctionReturnUnknown(): void
    {
        $type = $this->source->forFunctionReturn(
            FunctionName::fromFullyQualified('nothing_defines_this'),
        );

        self::assertNull($type, 'unknown function yields no type');
    }

    public function testFunctionParameterUserDeclared(): void
    {
        $type = $this->source->forFunctionParameter(
            FunctionName::fromFullyQualified('Fixtures\\Helpers\\helperFormat'),
            'value',
        );

        self::assertInstanceOf(PrimitiveType::class, $type, 'helperFormat($value) is string');
        self::assertSame('string', $type->format());
    }

    public function testFunctionParameterBuiltin(): void
    {
        $type = $this->source->forFunctionParameter(
            FunctionName::fromFullyQualified('strlen'),
            'string',
        );

        self::assertInstanceOf(PrimitiveType::class, $type, 'strlen($string) is string (via reflection)');
        self::assertSame('string', $type->format());
    }

    public function testFunctionParameterUnknownName(): void
    {
        $type = $this->source->forFunctionParameter(
            FunctionName::fromFullyQualified('strlen'),
            'not_this',
        );

        self::assertNull($type, 'unknown parameter on a known function yields no type');
    }

    public function testFunctionParameterUnknownFunction(): void
    {
        $type = $this->source->forFunctionParameter(
            FunctionName::fromFullyQualified('nothing_defines_this'),
            'anything',
        );

        self::assertNull($type, 'unknown function short-circuits before the parameter loop');
    }
}
