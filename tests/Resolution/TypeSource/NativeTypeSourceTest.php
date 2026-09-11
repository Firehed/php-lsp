<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution\TypeSource;

use Firehed\PhpLsp\Domain\ClassName;
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
        $type = $this->source->forMethodReturn(
            new ClassName('Fixtures\\Domain\\User'),
            new MethodName('getName'),
        );

        self::assertNotNull($type, 'getName returns a declared string');
        self::assertSame('string', $type->format());
    }

    public function testMethodReturnClassName(): void
    {
        $type = $this->source->forMethodReturn(
            new ClassName('Fixtures\\Domain\\User'),
            new MethodName('getStatus'),
        );

        self::assertInstanceOf(ClassName::class, $type, 'getStatus returns the Status enum');
        self::assertSame('Fixtures\\Enum\\Status', $type->fqn);
    }

    public function testMethodReturnNullableIsUnion(): void
    {
        $type = $this->source->forMethodReturn(
            new ClassName('Fixtures\\Domain\\User'),
            new MethodName('getTeam'),
        );

        self::assertInstanceOf(UnionType::class, $type, '?Team is stored as Team|null');
        self::assertSame('?Fixtures\\Domain\\Team', $type->format());
    }

    public function testMethodReturnInheritedFromTrait(): void
    {
        $type = $this->source->forMethodReturn(
            new ClassName('Fixtures\\Domain\\User'),
            new MethodName('markCreated'),
        );

        self::assertInstanceOf(PrimitiveType::class, $type, 'trait method markCreated is reached via member walk');
        self::assertSame('void', $type->format());
    }

    public function testMethodReturnOnTraitAliasedName(): void
    {
        // The class exposes the trait's method under an alias. Querying the
        // using class must find it via MemberResolver's alias walk; querying
        // the trait with the alias name is blind (the trait doesn't declare
        // the alias). resolveLateBoundReturn depends on the former.
        $type = $this->source->forMethodReturn(
            new ClassName('Fixtures\\Hierarchy\\TraitAliasSelfReturnUser'),
            new MethodName('aliasedFluent'),
        );

        self::assertNotNull(
            $type,
            'aliased trait method resolves through MemberResolver.findMethod on the using class',
        );
    }

    public function testMethodReturnLookupOnTraitByAliasedNameIsBlind(): void
    {
        $type = $this->source->forMethodReturn(
            new ClassName('Fixtures\\Hierarchy\\AliasedSelfReturnTrait'),
            new MethodName('aliasedFluent'),
        );

        self::assertNull(
            $type,
            'the trait itself does not declare the alias; late-binding rewrite must query the using class instead',
        );
    }

    public function testMethodReturnUnknownClass(): void
    {
        $type = $this->source->forMethodReturn(
            new ClassName('Fixtures\\Does\\NotExist'),
            new MethodName('anything'),
        );

        self::assertNull($type, 'unknown class yields no type');
    }

    public function testMethodReturnUnknownMethod(): void
    {
        $type = $this->source->forMethodReturn(
            new ClassName('Fixtures\\Domain\\User'),
            new MethodName('nonexistent'),
        );

        self::assertNull($type, 'unknown method on a known class yields no type');
    }

    public function testMethodParameterPrimitive(): void
    {
        $type = $this->source->forMethodParameter(
            new ClassName('Fixtures\\Domain\\User'),
            new MethodName('__construct'),
            'age',
        );

        self::assertInstanceOf(PrimitiveType::class, $type, '$age is declared as int');
        self::assertSame('int', $type->format());
    }

    public function testMethodParameterClass(): void
    {
        $type = $this->source->forMethodParameter(
            new ClassName('Fixtures\\Domain\\User'),
            new MethodName('__construct'),
            'status',
        );

        self::assertInstanceOf(ClassName::class, $type, '$status is declared as Status');
        self::assertSame('Fixtures\\Enum\\Status', $type->fqn);
    }

    public function testMethodParameterUnknownName(): void
    {
        $type = $this->source->forMethodParameter(
            new ClassName('Fixtures\\Domain\\User'),
            new MethodName('__construct'),
            'unknown',
        );

        self::assertNull($type, 'unknown parameter name yields no type');
    }

    public function testMethodParameterUnknownMethod(): void
    {
        $type = $this->source->forMethodParameter(
            new ClassName('Fixtures\\Domain\\User'),
            new MethodName('nonexistent'),
            'anything',
        );

        self::assertNull($type, 'unknown method short-circuits before the parameter loop');
    }

    public function testPropertyPromoted(): void
    {
        $type = $this->source->forProperty(
            new ClassName('Fixtures\\Domain\\User'),
            new PropertyName('name'),
        );

        self::assertInstanceOf(PrimitiveType::class, $type, 'promoted-property $name has an int type');
        self::assertSame('string', $type->format());
    }

    public function testPropertyStatic(): void
    {
        $type = $this->source->forProperty(
            new ClassName('Fixtures\\Domain\\User'),
            new PropertyName('instanceCount'),
        );

        self::assertInstanceOf(PrimitiveType::class, $type, 'static $instanceCount is int');
        self::assertSame('int', $type->format());
    }

    public function testPropertyInheritedFromTrait(): void
    {
        $type = $this->source->forProperty(
            new ClassName('Fixtures\\Domain\\User'),
            new PropertyName('displayName'),
        );

        self::assertInstanceOf(PrimitiveType::class, $type, 'trait property is reached via member walk');
        self::assertSame('string', $type->format());
    }

    public function testConstantOnClassIsUntypedReturnsNull(): void
    {
        $type = $this->source->forConstant(
            new ConstantName('DEFAULT_ROLE'),
            new ClassName('Fixtures\\Domain\\User'),
        );

        self::assertNull($type, 'untyped class constants have no declared type');
    }

    public function testConstantGlobalUserDeclared(): void
    {
        $type = $this->source->forConstant(
            new ConstantName('Fixtures\\Helpers\\HELPER_LIMIT'),
            null,
        );

        self::assertNull($type, 'untyped global const declaration has no declared type');
    }

    public function testConstantGlobalUnknown(): void
    {
        $type = $this->source->forConstant(
            new ConstantName('Some\\Never\\Declared\\THING'),
            null,
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
