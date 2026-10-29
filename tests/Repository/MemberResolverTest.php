<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Repository;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClasslikeConstantInfo;
use Firehed\PhpLsp\Domain\ClasslikeConstantName;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\LateStaticType;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\TraitAlias;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Tests\ProvidesMemberKindsTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberResolver::class)]
final class MemberResolverTest extends TestCase
{
    use ProvidesMemberKindsTrait;

    public function testFindMethodReturnsNullForUnknownClass(): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $result = $resolver->findMethod($className, 'foo', null);

        self::assertNull($result);
    }

    public function testFindPropertyReturnsNullForUnknownClass(): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findProperty(
            ClasslikeName::fromFullyQualified(self::fakeClass()),
            'foo',
            null,
        );

        self::assertNull($result);
    }

    public function testFindConstantReturnsNullForUnknownClass(): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findConstant(
            ClasslikeName::fromFullyQualified(self::fakeClass()),
            'FOO',
            null,
        );

        self::assertNull($result);
    }

    public function testFindEnumCaseReturnsNullForUnknownClass(): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findEnumCase(
            ClasslikeName::fromFullyQualified(self::fakeClass()),
            'Foo',
        );

        self::assertNull($result);
    }

    public function testFindMethodReturnsMethodFromClass(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $methodInfo = $this->createMethodInfo('doSomething', Visibility::Public, $className);
        $classInfo = $this->createClassInfo($className, methods: ['doSomething' => $methodInfo]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnMap([
            [$className, $classInfo],
        ]);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($className, 'doSomething', null);

        self::assertSame($methodInfo, $result);
    }

    public function testFindMethodReturnsMethodFromParent(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $methodInfo = $this->createMethodInfo('parentMethod', Visibility::Public, $parentName);

        $parentInfo = $this->createClassInfo($parentName, methods: ['parentMethod' => $methodInfo]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($childName, 'parentMethod', null);

        self::assertSame($methodInfo, $result);
    }

    public function testFindMethodReturnsMethodFromTrait(): void
    {
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $methodInfo = $this->createMethodInfo('traitMethod', Visibility::Public, $traitName);

        $traitInfo = $this->createClassInfo(
            $traitName,
            kind: ClassKind::Trait_,
            methods: ['traitMethod' => $methodInfo],
        );
        $classInfo = $this->createClassInfo($className, traits: [$traitName]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $traitName->qualifiedName->fullyQualifiedName() => $traitInfo,
                $className->qualifiedName->fullyQualifiedName() => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($className, 'traitMethod', null);

        self::assertSame($methodInfo, $result);
    }

    public function testFindMethodFiltersVisibility(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $privateMethod = $this->createMethodInfo('privateMethod', Visibility::Private, $className);
        $classInfo = $this->createClassInfo($className, methods: ['privateMethod' => $privateMethod]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($className, 'privateMethod', null);

        self::assertNull($result);
    }

    public function testFindMethodExcludesParentPrivateMethods(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $privateMethod = $this->createMethodInfo('privateMethod', Visibility::Private, $parentName);

        $parentInfo = $this->createClassInfo($parentName, methods: ['privateMethod' => $privateMethod]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($childName, 'privateMethod', $childName);

        self::assertNull($result);
    }

    public function testFindMethodIncludesTraitPrivateMethods(): void
    {
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $privateMethod = $this->createMethodInfo('privateMethod', Visibility::Private, $traitName);

        $traitInfo = $this->createClassInfo(
            $traitName,
            kind: ClassKind::Trait_,
            methods: ['privateMethod' => $privateMethod],
        );
        $classInfo = $this->createClassInfo($className, traits: [$traitName]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $traitName->qualifiedName->fullyQualifiedName() => $traitInfo,
                $className->qualifiedName->fullyQualifiedName() => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($className, 'privateMethod', $className);

        self::assertSame($privateMethod, $result);
    }

    public function testFindPropertyReturnsPropertyFromClass(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $propInfo = $this->createPropertyInfo('myProp', Visibility::Public, $className);
        $classInfo = $this->createClassInfo($className, properties: ['myProp' => $propInfo]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findProperty($className, 'myProp', null);

        self::assertSame($propInfo, $result);
    }

    public function testFindPropertyReturnsPropertyFromParent(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $propInfo = $this->createPropertyInfo('parentProp', Visibility::Public, $parentName);

        $parentInfo = $this->createClassInfo($parentName, properties: ['parentProp' => $propInfo]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findProperty($childName, 'parentProp', null);

        self::assertSame($propInfo, $result);
    }

    public function testFindPropertyReturnsPropertyFromTrait(): void
    {
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $propInfo = $this->createPropertyInfo('traitProp', Visibility::Public, $traitName);

        $traitInfo = $this->createClassInfo(
            $traitName,
            kind: ClassKind::Trait_,
            properties: ['traitProp' => $propInfo],
        );
        $classInfo = $this->createClassInfo($className, traits: [$traitName]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $traitName->qualifiedName->fullyQualifiedName() => $traitInfo,
                $className->qualifiedName->fullyQualifiedName() => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findProperty($className, 'traitProp', null);

        self::assertSame($propInfo, $result);
    }

    public function testFindPropertyReturnsNullWhenNotFound(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $classInfo = $this->createClassInfo($className);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findProperty($className, 'nonexistent', null);

        self::assertNull($result);
    }

    public function testFindConstantReturnsConstantFromClass(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $constInfo = $this->createConstantInfo('MY_CONST', Visibility::Public, $className);
        $classInfo = $this->createClassInfo($className, constants: ['MY_CONST' => $constInfo]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findConstant($className, 'MY_CONST', null);

        self::assertSame($constInfo, $result);
    }

    public function testFindConstantReturnsConstantFromParent(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $constInfo = $this->createConstantInfo('PARENT_CONST', Visibility::Public, $parentName);

        $parentInfo = $this->createClassInfo($parentName, constants: ['PARENT_CONST' => $constInfo]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findConstant($childName, 'PARENT_CONST', null);

        self::assertSame($constInfo, $result);
    }

    public function testFindConstantReturnsConstantFromTrait(): void
    {
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $constInfo = $this->createConstantInfo('TRAIT_CONST', Visibility::Public, $traitName);

        $traitInfo = $this->createClassInfo(
            $traitName,
            kind: ClassKind::Trait_,
            constants: ['TRAIT_CONST' => $constInfo],
        );
        $classInfo = $this->createClassInfo($className, traits: [$traitName]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $traitName->qualifiedName->fullyQualifiedName() => $traitInfo,
                $className->qualifiedName->fullyQualifiedName() => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findConstant($className, 'TRAIT_CONST', null);

        self::assertSame($constInfo, $result);
    }

    public function testFindConstantReturnsNullWhenNotFound(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $classInfo = $this->createClassInfo($className);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findConstant($className, 'NONEXISTENT', null);

        self::assertNull($result);
    }

    public function testListedMethodsReturnsAllAccessibleMethods(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $parentPublic = $this->createMethodInfo('parentPublic', Visibility::Public, $parentName);
        $parentProtected = $this->createMethodInfo('parentProtected', Visibility::Protected, $parentName);
        $parentPrivate = $this->createMethodInfo('parentPrivate', Visibility::Private, $parentName);

        $childMethod = $this->createMethodInfo('childMethod', Visibility::Public, $childName);

        $parentInfo = $this->createClassInfo($parentName, methods: [
            'parentPublic' => $parentPublic,
            'parentProtected' => $parentProtected,
            'parentPrivate' => $parentPrivate,
        ]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName, methods: [
            'childMethod' => $childMethod,
        ]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($childName, MemberKind::Method, $childName);

        self::assertCount(3, $result);
        self::assertContains($childMethod, $result);
        self::assertContains($parentPublic, $result);
        self::assertContains($parentProtected, $result);
        self::assertNotContains($parentPrivate, $result);
    }

    public function testListedMethodsIncludesMethodsOfExtendedInterfaces(): void
    {
        $messageName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $requestName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $getHeaders = $this->createMethodInfo('getHeaders', Visibility::Public, $messageName);
        $getMethod = $this->createMethodInfo('getMethod', Visibility::Public, $requestName);

        $messageInfo = $this->createClassInfo($messageName, ClassKind::Interface_, methods: [
            'getHeaders' => $getHeaders,
        ]);
        $requestInfo = $this->createClassInfo($requestName, ClassKind::Interface_, methods: [
            'getMethod' => $getMethod,
        ], interfaces: [$messageName]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $messageName->qualifiedName->fullyQualifiedName() => $messageInfo,
                $requestName->qualifiedName->fullyQualifiedName() => $requestInfo,
                default => null,
            },
        );

        self::assertSame(
            [$getMethod, $getHeaders],
            (new MemberResolver($repo))->getMembersOfKind($requestName, MemberKind::Method, null),
            'an interface offers its own methods and those of every interface it extends',
        );
    }

    public function testListedMethodsFiltersStatic(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $instanceMethod = $this->createMethodInfo('instance', Visibility::Public, $className, isStatic: false);
        $staticMethod = $this->createMethodInfo('static', Visibility::Public, $className, isStatic: true);

        $classInfo = $this->createClassInfo($className, methods: [
            'instance' => $instanceMethod,
            'static' => $staticMethod,
        ]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $staticOnly = $resolver->getMembersOfKind(
            $className,
            MemberKind::Method,
            null,
            MemberFilter::Static,
        );
        $instanceOnly = $resolver->getMembersOfKind(
            $className,
            MemberKind::Method,
            null,
            MemberFilter::Instance,
        );

        self::assertSame([$staticMethod], $staticOnly);
        self::assertSame([$instanceMethod], $instanceOnly);
    }

    public function testListedPropertiesReturnsAllAccessibleProperties(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $prop1 = $this->createPropertyInfo('prop1', Visibility::Public, $className);
        $prop2 = $this->createPropertyInfo('prop2', Visibility::Protected, $className);

        $classInfo = $this->createClassInfo($className, properties: [
            'prop1' => $prop1,
            'prop2' => $prop2,
        ]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($className, MemberKind::Property, $className);

        self::assertCount(2, $result);
    }

    public function testListedPropertiesIncludesParentProperties(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $parentPublic = $this->createPropertyInfo('parentPublic', Visibility::Public, $parentName);
        $parentProtected = $this->createPropertyInfo('parentProtected', Visibility::Protected, $parentName);
        $parentPrivate = $this->createPropertyInfo('parentPrivate', Visibility::Private, $parentName);

        $childProp = $this->createPropertyInfo('childProp', Visibility::Public, $childName);

        $parentInfo = $this->createClassInfo($parentName, properties: [
            'parentPublic' => $parentPublic,
            'parentProtected' => $parentProtected,
            'parentPrivate' => $parentPrivate,
        ]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName, properties: [
            'childProp' => $childProp,
        ]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($childName, MemberKind::Property, $childName);

        self::assertCount(3, $result);
        self::assertContains($childProp, $result);
        self::assertContains($parentPublic, $result);
        self::assertContains($parentProtected, $result);
        self::assertNotContains($parentPrivate, $result);
    }

    public function testListedPropertiesFiltersStatic(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $instanceProp = $this->createPropertyInfo('instance', Visibility::Public, $className, isStatic: false);
        $staticProp = $this->createPropertyInfo('static', Visibility::Public, $className, isStatic: true);

        $classInfo = $this->createClassInfo($className, properties: [
            'instance' => $instanceProp,
            'static' => $staticProp,
        ]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $staticOnly = $resolver->getMembersOfKind(
            $className,
            MemberKind::Property,
            null,
            MemberFilter::Static,
        );
        $instanceOnly = $resolver->getMembersOfKind(
            $className,
            MemberKind::Property,
            null,
            MemberFilter::Instance,
        );

        self::assertSame([$staticProp], $staticOnly);
        self::assertSame([$instanceProp], $instanceOnly);
    }

    public function testListedPropertiesIncludesTraitProperties(): void
    {
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());

        $traitProp = $this->createPropertyInfo('traitProp', Visibility::Public, $traitName);
        $classProp = $this->createPropertyInfo('classProp', Visibility::Public, $className);

        $traitInfo = $this->createClassInfo(
            $traitName,
            kind: ClassKind::Trait_,
            properties: ['traitProp' => $traitProp],
        );
        $classInfo = $this->createClassInfo($className, traits: [$traitName], properties: [
            'classProp' => $classProp,
        ]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $traitName->qualifiedName->fullyQualifiedName() => $traitInfo,
                $className->qualifiedName->fullyQualifiedName() => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($className, MemberKind::Property, null);

        self::assertCount(2, $result);
        self::assertContains($classProp, $result);
        self::assertContains($traitProp, $result);
    }

    public function testListedConstantsReturnsAllAccessibleConstants(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $const1 = $this->createConstantInfo('CONST1', Visibility::Public, $className);

        $classInfo = $this->createClassInfo($className, constants: ['CONST1' => $const1]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($className, MemberKind::Constant, null);

        self::assertSame([$const1], $result);
    }

    public function testListedConstantsIncludesParentConstants(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $parentConst = $this->createConstantInfo('PARENT_CONST', Visibility::Public, $parentName);
        $childConst = $this->createConstantInfo('CHILD_CONST', Visibility::Public, $childName);

        $parentInfo = $this->createClassInfo($parentName, constants: ['PARENT_CONST' => $parentConst]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName, constants: [
            'CHILD_CONST' => $childConst,
        ]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($childName, MemberKind::Constant, null);

        self::assertCount(2, $result);
        self::assertContains($childConst, $result);
        self::assertContains($parentConst, $result);
    }

    public function testListedConstantsIncludesTraitConstants(): void
    {
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());

        $traitConst = $this->createConstantInfo('TRAIT_CONST', Visibility::Public, $traitName);
        $classConst = $this->createConstantInfo('CLASS_CONST', Visibility::Public, $className);

        $traitInfo = $this->createClassInfo(
            $traitName,
            kind: ClassKind::Trait_,
            constants: ['TRAIT_CONST' => $traitConst],
        );
        $classInfo = $this->createClassInfo($className, traits: [$traitName], constants: [
            'CLASS_CONST' => $classConst,
        ]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $traitName->qualifiedName->fullyQualifiedName() => $traitInfo,
                $className->qualifiedName->fullyQualifiedName() => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($className, MemberKind::Constant, null);

        self::assertCount(2, $result);
        self::assertContains($classConst, $result);
        self::assertContains($traitConst, $result);
    }

    public function testListedEnumCasesReturnsAllCases(): void
    {
        $enumName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $case1 = $this->createEnumCaseInfo('Case1', $enumName);
        $case2 = $this->createEnumCaseInfo('Case2', $enumName);

        $enumInfo = $this->createClassInfo($enumName, kind: ClassKind::Enum_, enumCases: [
            'Case1' => $case1,
            'Case2' => $case2,
        ]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($enumInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($enumName, MemberKind::EnumCase, null);

        self::assertCount(2, $result);
        self::assertContains($case1, $result);
        self::assertContains($case2, $result);
    }

    public function testFindEnumCaseReturnsCase(): void
    {
        $enumName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $case1 = $this->createEnumCaseInfo('Case1', $enumName);
        $case2 = $this->createEnumCaseInfo('Case2', $enumName);

        $enumInfo = $this->createClassInfo($enumName, kind: ClassKind::Enum_, enumCases: [
            'Case1' => $case1,
            'Case2' => $case2,
        ]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($enumInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findEnumCase($enumName, 'Case2');

        self::assertSame($case2, $result);
    }

    public function testFindEnumCaseReturnsNullWhenNotFound(): void
    {
        $enumName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $enumInfo = $this->createClassInfo($enumName, kind: ClassKind::Enum_);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($enumInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findEnumCase($enumName, 'NonExistent');

        self::assertNull($result);
    }

    public function testDiamondInheritanceNoDuplicates(): void
    {
        // Diamond: Child uses Trait1 and Trait2, both use BaseTrait
        $baseTrait = ClasslikeName::fromFullyQualified(self::fakeClass());
        $trait1 = ClasslikeName::fromFullyQualified(self::fakeClass());
        $trait2 = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $sharedMethod = $this->createMethodInfo('sharedMethod', Visibility::Public, $baseTrait);

        $baseTraitInfo = $this->createClassInfo($baseTrait, kind: ClassKind::Trait_, methods: [
            'sharedMethod' => $sharedMethod,
        ]);
        $trait1Info = $this->createClassInfo($trait1, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $trait2Info = $this->createClassInfo($trait2, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $childInfo = $this->createClassInfo($childName, traits: [$trait1, $trait2]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $baseTrait->qualifiedName->fullyQualifiedName() => $baseTraitInfo,
                $trait1->qualifiedName->fullyQualifiedName() => $trait1Info,
                $trait2->qualifiedName->fullyQualifiedName() => $trait2Info,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($childName, MemberKind::Method, null);

        self::assertCount(1, $result);
    }

    public function testFindMethodWithDiamondInheritanceHitsSeenCheck(): void
    {
        // Diamond where the method doesn't exist anywhere, forcing full traversal
        // This hits the $seen check when trait2 tries to traverse baseTrait (already seen via trait1)
        $baseTrait = ClasslikeName::fromFullyQualified(self::fakeClass());
        $trait1 = ClasslikeName::fromFullyQualified(self::fakeClass());
        $trait2 = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $baseTraitInfo = $this->createClassInfo($baseTrait, kind: ClassKind::Trait_);
        $trait1Info = $this->createClassInfo($trait1, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $trait2Info = $this->createClassInfo($trait2, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $childInfo = $this->createClassInfo($childName, traits: [$trait1, $trait2]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $baseTrait->qualifiedName->fullyQualifiedName() => $baseTraitInfo,
                $trait1->qualifiedName->fullyQualifiedName() => $trait1Info,
                $trait2->qualifiedName->fullyQualifiedName() => $trait2Info,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($childName, 'nonexistent', null);

        self::assertNull($result);
    }

    public function testFindPropertyWithDiamondInheritanceHitsSeenCheck(): void
    {
        $baseTrait = ClasslikeName::fromFullyQualified(self::fakeClass());
        $trait1 = ClasslikeName::fromFullyQualified(self::fakeClass());
        $trait2 = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $baseTraitInfo = $this->createClassInfo($baseTrait, kind: ClassKind::Trait_);
        $trait1Info = $this->createClassInfo($trait1, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $trait2Info = $this->createClassInfo($trait2, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $childInfo = $this->createClassInfo($childName, traits: [$trait1, $trait2]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $baseTrait->qualifiedName->fullyQualifiedName() => $baseTraitInfo,
                $trait1->qualifiedName->fullyQualifiedName() => $trait1Info,
                $trait2->qualifiedName->fullyQualifiedName() => $trait2Info,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findProperty($childName, 'nonexistent', null);

        self::assertNull($result);
    }

    public function testFindConstantWithDiamondInheritanceHitsSeenCheck(): void
    {
        $baseTrait = ClasslikeName::fromFullyQualified(self::fakeClass());
        $trait1 = ClasslikeName::fromFullyQualified(self::fakeClass());
        $trait2 = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $baseTraitInfo = $this->createClassInfo($baseTrait, kind: ClassKind::Trait_);
        $trait1Info = $this->createClassInfo($trait1, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $trait2Info = $this->createClassInfo($trait2, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $childInfo = $this->createClassInfo($childName, traits: [$trait1, $trait2]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $baseTrait->qualifiedName->fullyQualifiedName() => $baseTraitInfo,
                $trait1->qualifiedName->fullyQualifiedName() => $trait1Info,
                $trait2->qualifiedName->fullyQualifiedName() => $trait2Info,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findConstant($childName, 'NONEXISTENT', null);

        self::assertNull($result);
    }

    public function testFindMethodSkipsNonMatchingMethods(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $method1 = $this->createMethodInfo('method1', Visibility::Public, $className);
        $method2 = $this->createMethodInfo('method2', Visibility::Public, $className);

        $classInfo = $this->createClassInfo($className, methods: [
            'method1' => $method1,
            'method2' => $method2,
        ]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($className, 'method2', null);

        self::assertSame($method2, $result);
    }

    public function testListedConstantsFiltersInaccessibleConstants(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $publicConst = $this->createConstantInfo('PUBLIC', Visibility::Public, $className);
        $privateConst = $this->createConstantInfo('PRIVATE', Visibility::Private, $className);

        $classInfo = $this->createClassInfo($className, constants: [
            'PUBLIC' => $publicConst,
            'PRIVATE' => $privateConst,
        ]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($className, MemberKind::Constant, null);

        self::assertSame([$publicConst], $result);
    }

    public function testListedMethodsChildOverridesParent(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $parentMethod = $this->createMethodInfo('method', Visibility::Public, $parentName);
        $childMethod = $this->createMethodInfo('method', Visibility::Public, $childName);

        $parentInfo = $this->createClassInfo($parentName, methods: ['method' => $parentMethod]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName, methods: ['method' => $childMethod]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($childName, MemberKind::Method, null);

        self::assertCount(1, $result);
        self::assertSame($childMethod, $result[0]);
    }

    public function testListedPropertiesChildOverridesParent(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $parentProp = $this->createPropertyInfo('prop', Visibility::Public, $parentName);
        $childProp = $this->createPropertyInfo('prop', Visibility::Public, $childName);

        $parentInfo = $this->createClassInfo($parentName, properties: ['prop' => $parentProp]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName, properties: ['prop' => $childProp]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($childName, MemberKind::Property, null);

        self::assertCount(1, $result);
        self::assertSame($childProp, $result[0]);
    }

    public function testListedConstantsChildOverridesParent(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $parentConst = $this->createConstantInfo('CONST', Visibility::Public, $parentName);
        $childConst = $this->createConstantInfo('CONST', Visibility::Public, $childName);

        $parentInfo = $this->createClassInfo($parentName, constants: ['CONST' => $parentConst]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName, constants: ['CONST' => $childConst]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($childName, MemberKind::Constant, null);

        self::assertCount(1, $result);
        self::assertSame($childConst, $result[0]);
    }

    public function testListedPropertiesDiamondInheritance(): void
    {
        $baseTrait = ClasslikeName::fromFullyQualified(self::fakeClass());
        $trait1 = ClasslikeName::fromFullyQualified(self::fakeClass());
        $trait2 = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $sharedProp = $this->createPropertyInfo('sharedProp', Visibility::Public, $baseTrait);

        $baseTraitInfo = $this->createClassInfo($baseTrait, kind: ClassKind::Trait_, properties: [
            'sharedProp' => $sharedProp,
        ]);
        $trait1Info = $this->createClassInfo($trait1, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $trait2Info = $this->createClassInfo($trait2, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $childInfo = $this->createClassInfo($childName, traits: [$trait1, $trait2]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $baseTrait->qualifiedName->fullyQualifiedName() => $baseTraitInfo,
                $trait1->qualifiedName->fullyQualifiedName() => $trait1Info,
                $trait2->qualifiedName->fullyQualifiedName() => $trait2Info,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($childName, MemberKind::Property, null);

        self::assertCount(1, $result);
    }

    public function testListedConstantsDiamondInheritance(): void
    {
        $baseTrait = ClasslikeName::fromFullyQualified(self::fakeClass());
        $trait1 = ClasslikeName::fromFullyQualified(self::fakeClass());
        $trait2 = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $sharedConst = $this->createConstantInfo('SHARED', Visibility::Public, $baseTrait);

        $baseTraitInfo = $this->createClassInfo($baseTrait, kind: ClassKind::Trait_, constants: [
            'SHARED' => $sharedConst,
        ]);
        $trait1Info = $this->createClassInfo($trait1, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $trait2Info = $this->createClassInfo($trait2, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $childInfo = $this->createClassInfo($childName, traits: [$trait1, $trait2]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $baseTrait->qualifiedName->fullyQualifiedName() => $baseTraitInfo,
                $trait1->qualifiedName->fullyQualifiedName() => $trait1Info,
                $trait2->qualifiedName->fullyQualifiedName() => $trait2Info,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($childName, MemberKind::Constant, null);

        self::assertCount(1, $result);
    }

    public function testChildMethodOverridesParent(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $parentMethod = $this->createMethodInfo('method', Visibility::Public, $parentName);
        $childMethod = $this->createMethodInfo('method', Visibility::Public, $childName);

        $parentInfo = $this->createClassInfo($parentName, methods: ['method' => $parentMethod]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName, methods: ['method' => $childMethod]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($childName, 'method', null);

        self::assertSame($childMethod, $result);
    }

    public function testFindMethodFromGrandparent(): void
    {
        $grandparentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());

        $grandparentMethod = $this->createMethodInfo('deepMethod', Visibility::Public, $grandparentName);

        $grandparentInfo = $this->createClassInfo(
            $grandparentName,
            methods: ['deepMethod' => $grandparentMethod],
        );
        $parentInfo = $this->createClassInfo($parentName, parent: $grandparentName);
        $childInfo = $this->createClassInfo($childName, parent: $parentName);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $grandparentName->qualifiedName->fullyQualifiedName() => $grandparentInfo,
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($childName, 'deepMethod', null);

        self::assertSame($grandparentMethod, $result);
    }

    public function testFindConstantFromInterface(): void
    {
        $interfaceName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());

        $interfaceConst = $this->createConstantInfo('INTERFACE_CONST', Visibility::Public, $interfaceName);

        $interfaceInfo = $this->createClassInfo(
            $interfaceName,
            kind: ClassKind::Interface_,
            constants: ['INTERFACE_CONST' => $interfaceConst],
        );
        $classInfo = $this->createClassInfo($className, interfaces: [$interfaceName]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $interfaceName->qualifiedName->fullyQualifiedName() => $interfaceInfo,
                $className->qualifiedName->fullyQualifiedName() => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findConstant($className, 'INTERFACE_CONST', null);

        self::assertSame($interfaceConst, $result);
    }

    public function testListedConstantsIncludesInterfaceConstants(): void
    {
        $interfaceName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());

        $classConst = $this->createConstantInfo('CLASS_CONST', Visibility::Public, $className);
        $interfaceConst = $this->createConstantInfo('INTERFACE_CONST', Visibility::Public, $interfaceName);

        $interfaceInfo = $this->createClassInfo(
            $interfaceName,
            kind: ClassKind::Interface_,
            constants: ['INTERFACE_CONST' => $interfaceConst],
        );
        $classInfo = $this->createClassInfo(
            $className,
            constants: ['CLASS_CONST' => $classConst],
            interfaces: [$interfaceName],
        );

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $interfaceName->qualifiedName->fullyQualifiedName() => $interfaceInfo,
                $className->qualifiedName->fullyQualifiedName() => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($className, MemberKind::Constant, null);

        self::assertCount(2, $result);
        self::assertContains($classConst, $result);
        self::assertContains($interfaceConst, $result);
    }

    public function testFindMethodMatchesNameCaseInsensitively(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $methodInfo = $this->createMethodInfo('overriddenMethod', Visibility::Public, $className);
        $classInfo = $this->createClassInfo($className, methods: ['overriddenMethod' => $methodInfo]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($className, 'OVERRIDDENMETHOD', null);

        self::assertSame($methodInfo, $result);
    }

    public function testListedMethodsTreatsCaseVariedOverrideAsOneMethod(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $parentMethod = $this->createMethodInfo('overriddenMethod', Visibility::Public, $parentName);
        $childMethod = $this->createMethodInfo('OVERRIDDENMETHOD', Visibility::Public, $childName);

        $parentInfo = $this->createClassInfo($parentName, methods: ['overriddenMethod' => $parentMethod]);
        $childInfo = $this->createClassInfo(
            $childName,
            parent: $parentName,
            methods: ['OVERRIDDENMETHOD' => $childMethod],
        );

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($childName, MemberKind::Method, null);

        self::assertSame([$childMethod], $result);
    }

    public function testFindPropertyMatchesNameCaseSensitively(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $propertyInfo = $this->createPropertyInfo('value', Visibility::Public, $className);
        $classInfo = $this->createClassInfo($className, properties: ['value' => $propertyInfo]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findProperty($className, 'VALUE', null);

        self::assertNull($result);
    }

    public function testFindConstantMatchesNameCaseSensitively(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $constantInfo = $this->createConstantInfo('VALUE', Visibility::Public, $className);
        $classInfo = $this->createClassInfo($className, constants: ['VALUE' => $constantInfo]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findConstant($className, 'Value', null);

        self::assertNull($result);
    }

    public function testFindEnumCaseMatchesNameCaseSensitively(): void
    {
        $enumName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $caseInfo = $this->createEnumCaseInfo('Draft', $enumName);
        $enumInfo = $this->createClassInfo($enumName, ClassKind::Enum_, enumCases: ['Draft' => $caseInfo]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($enumInfo);

        $resolver = new MemberResolver($repo);

        self::assertNull($resolver->findEnumCase($enumName, 'DRAFT'));
    }

    public function testListedConstantsKeepsCaseVariedNamesApart(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $childName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $parentConstant = $this->createConstantInfo('VALUE', Visibility::Public, $parentName);
        $childConstant = $this->createConstantInfo('Value', Visibility::Public, $childName);

        $parentInfo = $this->createClassInfo($parentName, constants: ['VALUE' => $parentConstant]);
        $childInfo = $this->createClassInfo(
            $childName,
            parent: $parentName,
            constants: ['Value' => $childConstant],
        );

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $parentName->qualifiedName->fullyQualifiedName() => $parentInfo,
                $childName->qualifiedName->fullyQualifiedName() => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMembersOfKind($childName, MemberKind::Constant, null);

        self::assertCount(2, $result);
        self::assertContains($parentConstant, $result);
        self::assertContains($childConstant, $result);
    }

    public function testIsInterfaceReturnsTrueForInterface(): void
    {
        $interfaceName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $interfaceInfo = $this->createClassInfo($interfaceName, ClassKind::Interface_);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($interfaceInfo);

        $resolver = new MemberResolver($repo);

        self::assertTrue($resolver->isInterface($interfaceName));
    }

    public function testIsInterfaceReturnsFalseForClass(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $classInfo = $this->createClassInfo($className, ClassKind::Class_);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        self::assertFalse($resolver->isInterface($className));
    }

    public function testIsInterfaceReturnsFalseForUnknownClass(): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        self::assertFalse($resolver->isInterface(ClasslikeName::fromFullyQualified(self::fakeClass())));
    }

    public function testIsTraitReturnsTrueForTrait(): void
    {
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $traitInfo = $this->createClassInfo($traitName, ClassKind::Trait_);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($traitInfo);

        $resolver = new MemberResolver($repo);

        self::assertTrue($resolver->isTrait($traitName));
    }

    public function testIsTraitReturnsFalseForClass(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $classInfo = $this->createClassInfo($className, ClassKind::Class_);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        self::assertFalse($resolver->isTrait($className));
    }

    public function testIsTraitReturnsFalseForUnknownClass(): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        self::assertFalse($resolver->isTrait(ClasslikeName::fromFullyQualified(self::fakeClass())));
    }

    public function testAliasNamingAnUnknownTraitIsInvisible(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $unknown = ClasslikeName::fromFullyQualified(self::fakeClass());
        $resolver = $this->resolverOverClasses($this->createClassInfo(
            $className,
            traits: [$unknown],
            traitAliases: [
                new TraitAlias(trait: $unknown, method: 'helper', newName: 'exposedName', newVisibility: null),
            ],
        ));

        self::assertNull(
            $resolver->findMethod($className, 'exposedName', null),
            'an alias of a trait no source knows must not surface a method',
        );
    }

    public function testAliasNamingAnUnknownMethodOnANamedTraitIsInvisible(): void
    {
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $traitInfo = $this->createClassInfo($traitName, ClassKind::Trait_);
        $classInfo = $this->createClassInfo(
            $className,
            traits: [$traitName],
            traitAliases: [new TraitAlias(
                trait: $traitName,
                method: 'missing',
                newName: 'exposedName',
                newVisibility: null,
            )],
        );

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $traitName->qualifiedName->fullyQualifiedName() => $traitInfo,
                $className->qualifiedName->fullyQualifiedName() => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        self::assertNull(
            $resolver->findMethod($className, 'exposedName', null),
            'an alias whose source method is missing must not surface a method',
        );
        self::assertSame(
            [],
            $resolver->getMembersOfKind($className, MemberKind::Method, null),
            'the missing-source alias must not appear in the enumerated methods either',
        );
    }

    public function testNamedAliasResolvesOnTheUsingClassOnly(): void
    {
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $traitInfo = $this->createClassInfo(
            $traitName,
            ClassKind::Trait_,
            methods: ['helper' => $this->createMethodInfo('helper', Visibility::Public, $traitName)],
        );
        $classInfo = $this->createClassInfo(
            $className,
            traits: [$traitName],
            traitAliases: [new TraitAlias(
                trait: $traitName,
                method: 'helper',
                newName: 'exposedName',
                newVisibility: null,
            )],
        );

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $traitName->qualifiedName->fullyQualifiedName() => $traitInfo,
                $className->qualifiedName->fullyQualifiedName() => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $resolved = $resolver->findMethod($className, 'exposedName', null);
        self::assertNotNull($resolved, 'the using class exposes the trait method under its alias');
        self::assertSame(
            $traitName->qualifiedName->fullyQualifiedName(),
            $resolved->aliasedFrom?->owner->qualifiedName->fullyQualifiedName(),
            'the alias points back at the named trait',
        );
        self::assertNull(
            $resolver->findMethod($traitName, 'exposedName', null),
            'only the using class declares the alias, so the trait itself has no such method',
        );
    }

    public function testListedMethodsIncludeANamedAlias(): void
    {
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $helper = $this->createMethodInfo('helper', Visibility::Public, $traitName);
        $resolver = new MemberResolver($this->sourceOf(
            $this->createClassInfo($traitName, ClassKind::Trait_, methods: ['helper' => $helper]),
            $this->createClassInfo(
                $className,
                traits: [$traitName],
                traitAliases: [new TraitAlias(
                    trait: $traitName,
                    method: 'helper',
                    newName: 'exposedName',
                    newVisibility: null,
                )],
            ),
        ));

        $listed = $resolver->getMembersOfKind($className, MemberKind::Method, null);

        self::assertCount(2, $listed, 'the trait method is listed under its own name and its alias');
        self::assertSame($helper, $listed[0], 'the trait method keeps its own name');
        self::assertInstanceOf(MethodInfo::class, $listed[1]);
        self::assertSame('exposedName', $listed[1]->getName()->name, 'the alias is listed under its new name');
        self::assertSame(
            $traitName->qualifiedName->fullyQualifiedName(),
            $listed[1]->aliasedFrom?->owner->qualifiedName->fullyQualifiedName(),
            'the listed alias points back at the trait',
        );
    }

    public function testListedAliasReplacesAnInheritedMethodOfTheSameName(): void
    {
        $parentName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $resolver = new MemberResolver($this->sourceOf(
            $this->createClassInfo(
                $parentName,
                methods: ['exposedName' => $this->createMethodInfo('exposedName', Visibility::Public, $parentName)],
            ),
            $this->createClassInfo(
                $traitName,
                ClassKind::Trait_,
                methods: ['helper' => $this->createMethodInfo('helper', Visibility::Public, $traitName)],
            ),
            $this->createClassInfo(
                $className,
                parent: $parentName,
                traits: [$traitName],
                traitAliases: [new TraitAlias(
                    trait: $traitName,
                    method: 'helper',
                    newName: 'exposedName',
                    newVisibility: null,
                )],
            ),
        ));

        $listed = $resolver->getMembersOfKind($className, MemberKind::Method, null);
        $exposed = array_values(array_filter(
            $listed,
            fn ($method) => $method->getName()->name === 'exposedName',
        ));

        self::assertCount(1, $exposed, 'the alias and the inherited method are one method');
        self::assertInstanceOf(MethodInfo::class, $exposed[0]);
        self::assertSame(
            $traitName->qualifiedName->fullyQualifiedName(),
            $exposed[0]->aliasedFrom?->owner->qualifiedName->fullyQualifiedName(),
            'the alias, not the inherited method, is listed',
        );
    }

    public function testNamelessAliasResolvesThroughUsedTraits(): void
    {
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $sourceMethod = $this->createMethodInfo('helper', Visibility::Public, $traitName);
        $traitInfo = $this->createClassInfo(
            $traitName,
            ClassKind::Trait_,
            methods: ['helper' => $sourceMethod],
        );
        $classInfo = $this->createClassInfo(
            $className,
            traits: [$traitName],
            traitAliases: [new TraitAlias(
                trait: null,
                method: 'helper',
                newName: 'exposedName',
                newVisibility: null,
            )],
        );

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $traitName->qualifiedName->fullyQualifiedName() => $traitInfo,
                $className->qualifiedName->fullyQualifiedName() => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $resolved = $resolver->findMethod($className, 'exposedName', null);

        self::assertNotNull($resolved, 'the alias with no explicit trait must resolve through the used-trait scan');
        self::assertSame(
            $className->qualifiedName->fullyQualifiedName(),
            $resolved->getName()->owner->qualifiedName->fullyQualifiedName(),
            'the aliasing class owns the aliased-method identity',
        );
        self::assertNotNull($resolved->aliasedFrom, 'aliasedFrom points at the trait the used-trait scan located');
        self::assertSame(
            $traitName->qualifiedName->fullyQualifiedName(),
            $resolved->aliasedFrom->owner->qualifiedName->fullyQualifiedName(),
        );
    }

    public function testNamelessAliasWithNoOwningTraitIsInvisible(): void
    {
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $traitInfo = $this->createClassInfo($traitName, ClassKind::Trait_);
        $classInfo = $this->createClassInfo(
            $className,
            traits: [$traitName],
            traitAliases: [new TraitAlias(
                trait: null,
                method: 'missing',
                newName: 'exposedName',
                newVisibility: null,
            )],
        );

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => match ($name->qualifiedName->fullyQualifiedName()) {
                $traitName->qualifiedName->fullyQualifiedName() => $traitInfo,
                $className->qualifiedName->fullyQualifiedName() => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        self::assertNull(
            $resolver->findMethod($className, 'exposedName', null),
            'a nameless alias whose method is in no used trait must not surface',
        );
    }

    public function testIsSubclassOfReturnsFalseForUnknownClass(): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        self::assertFalse(
            $resolver->isSubclassOf(
                ClasslikeName::fromFullyQualified(self::fakeClass()),
                ClasslikeName::fromFullyQualified(self::fakeClass()),
            ),
            'a class no backend declares cannot be a subclass of anything',
        );
    }

    public function testIsSubclassOfIsNotReflexive(): void
    {
        $name = ClasslikeName::fromFullyQualified(self::fakeClass());
        $info = $this->createClassInfo($name);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $q) => $q->qualifiedName->fullyQualifiedName()
                === $name->qualifiedName->fullyQualifiedName() ? $info : null,
        );

        $resolver = new MemberResolver($repo);

        self::assertFalse(
            $resolver->isSubclassOf($name, $name),
            'a class is never a subclass of itself, matching PHP is_subclass_of',
        );
    }

    public function testIsSubclassOfReturnsFalseWhenTargetIsAUsedTrait(): void
    {
        $traitName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $traitInfo = $this->createClassInfo($traitName, ClassKind::Trait_);
        $classInfo = $this->createClassInfo($className, traits: [$traitName]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $q) => match ($q->qualifiedName->fullyQualifiedName()) {
                $traitName->qualifiedName->fullyQualifiedName() => $traitInfo,
                $className->qualifiedName->fullyQualifiedName() => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        self::assertFalse(
            $resolver->isSubclassOf($className, $traitName),
            'is_subclass_of never treats a used trait as a parent',
        );
    }

    /**
     * @return iterable<string, array{
     *     list<array{0: string, 1: ClassKind, 2: ?string, 3: list<string>}>,
     *     string,
     *     string,
     *     bool,
     * }>
     */
    public static function isSubclassOfCases(): iterable
    {
        yield 'direct parent' => [
            [
                ['App\\Child', ClassKind::Class_, 'App\\ParentClass', []],
                ['App\\ParentClass', ClassKind::Class_, null, []],
            ],
            'App\\Child',
            'App\\ParentClass',
            true,
        ];
        yield 'grandparent through the parent chain' => [
            [
                ['App\\Child', ClassKind::Class_, 'App\\ParentClass', []],
                ['App\\ParentClass', ClassKind::Class_, 'App\\Grandparent', []],
                ['App\\Grandparent', ClassKind::Class_, null, []],
            ],
            'App\\Child',
            'App\\Grandparent',
            true,
        ];
        yield 'directly implemented interface' => [
            [
                ['App\\Child', ClassKind::Class_, null, ['App\\IfaceA']],
                ['App\\IfaceA', ClassKind::Interface_, null, []],
            ],
            'App\\Child',
            'App\\IfaceA',
            true,
        ];
        yield 'interface reached through an interface' => [
            [
                ['App\\Child', ClassKind::Class_, null, ['App\\IfaceA']],
                ['App\\IfaceA', ClassKind::Interface_, null, ['App\\IfaceBase']],
                ['App\\IfaceBase', ClassKind::Interface_, null, []],
            ],
            'App\\Child',
            'App\\IfaceBase',
            true,
        ];
        yield 'interface reached through the parent' => [
            [
                ['App\\Child', ClassKind::Class_, 'App\\ParentClass', []],
                ['App\\ParentClass', ClassKind::Class_, null, ['App\\IfaceA']],
                ['App\\IfaceA', ClassKind::Interface_, null, []],
            ],
            'App\\Child',
            'App\\IfaceA',
            true,
        ];
        yield 'unrelated type' => [
            [
                ['App\\Child', ClassKind::Class_, 'App\\ParentClass', []],
                ['App\\ParentClass', ClassKind::Class_, null, []],
                ['App\\Unrelated', ClassKind::Class_, null, []],
            ],
            'App\\Child',
            'App\\Unrelated',
            false,
        ];
        yield 'unresolvable supertypes' => [
            [
                ['App\\Orphan', ClassKind::Class_, 'App\\Missing', ['App\\AlsoMissing']],
            ],
            'App\\Orphan',
            'App\\ParentClass',
            false,
        ];
        yield 'cyclic parent chain terminates' => [
            [
                ['App\\CycleA', ClassKind::Class_, 'App\\CycleB', []],
                ['App\\CycleB', ClassKind::Class_, 'App\\CycleA', []],
            ],
            'App\\CycleA',
            'App\\Unrelated',
            false,
        ];
        yield 'diamond interface graph terminates' => [
            [
                ['App\\Diamond', ClassKind::Class_, null, ['App\\IfaceA', 'App\\IfaceB']],
                ['App\\IfaceA', ClassKind::Interface_, null, ['App\\IfaceBase']],
                ['App\\IfaceB', ClassKind::Interface_, null, ['App\\IfaceBase']],
                ['App\\IfaceBase', ClassKind::Interface_, null, []],
            ],
            'App\\Diamond',
            'App\\Unrelated',
            false,
        ];
        yield 'case-divergent parent spelling still matches' => [
            [
                ['App\\Child', ClassKind::Class_, 'APP\\PARENTCLASS', []],
                ['App\\ParentClass', ClassKind::Class_, null, []],
            ],
            'App\\Child',
            'App\\ParentClass',
            true,
        ];
    }

    /**
     * @param list<array{0: string, 1: ClassKind, 2: ?string, 3: list<string>}> $graph
     */
    #[DataProvider('isSubclassOfCases')]
    public function testIsSubclassOfWalksTheGraph(
        array $graph,
        string $subject,
        string $target,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->resolverOverGraph($graph)->isSubclassOf(
                ClasslikeName::fromFullyQualified($subject),
                ClasslikeName::fromFullyQualified($target),
            ),
            "isSubclassOf walking from {$subject} to {$target}",
        );
    }

    /**
     * @return iterable<string, array{?string, string, string, ?string}>
     */
    public static function memberAccessCases(): iterable
    {
        yield 'public, from outside any class' => [null, 'App\\Child', 'basePublic', 'App\\Base'];
        yield 'protected, from outside any class' => [null, 'App\\Child', 'baseProtected', null];
        yield 'protected, from a subclass' => ['App\\Child', 'App\\Child', 'baseProtected', 'App\\Base'];
        yield 'protected, from its parent' => ['App\\Base', 'App\\Child', 'childProtected', 'App\\Child'];
        yield 'protected the parent declares, from a sibling' => [
            'App\\Sibling',
            'App\\Child',
            'baseProtected',
            'App\\Base',
        ];
        yield 'protected an override first declares in the parent, from a sibling' => [
            'App\\Sibling',
            'App\\Child',
            'overridden',
            'App\\Child',
        ];
        yield 'protected only the receiver declares, from a sibling' => [
            'App\\Sibling',
            'App\\Child',
            'childProtected',
            null,
        ];
        yield 'protected, from an unrelated class' => ['App\\Unrelated', 'App\\Child', 'baseProtected', null];
        yield 'private, from its own class' => ['App\\Child', 'App\\Child', 'childPrivate', 'App\\Child'];
        yield 'private, from its own class spelled in another case' => [
            'APP\\CHILD',
            'App\\Child',
            'childPrivate',
            'App\\Child',
        ];
        yield 'private, from its parent' => ['App\\Base', 'App\\Child', 'childPrivate', null];
        yield 'private a parent declares, from the subclass' => ['App\\Child', 'App\\Child', 'basePrivate', null];
        yield 'private a parent declares, from that parent on a subclass' => [
            'App\\Base',
            'App\\Child',
            'basePrivate',
            'App\\Base',
        ];
        yield 'private a subclass redeclares, from the parent on a subclass' => [
            'App\\Base',
            'App\\Child',
            'shadowed',
            'App\\Base',
        ];
        yield 'private a subclass redeclares, from the subclass' => [
            'App\\Child',
            'App\\Child',
            'shadowed',
            'App\\Child',
        ];
        yield 'private from a trait, from the class using it' => [
            'App\\Uses',
            'App\\Uses',
            'helperPrivate',
            'App\\Helper',
        ];
        yield 'private a class and its trait both declare, from the class on a subclass' => [
            'App\\Uses',
            'App\\UsesChild',
            'both',
            'App\\Uses',
        ];
        yield 'private from a trait a parent uses, from the subclass' => [
            'App\\UsesChild',
            'App\\UsesChild',
            'helperPrivate',
            null,
        ];
    }

    /**
     * Access is decided against the class that declares a member, not the
     * class it is looked up on: private from that class alone, protected from
     * classes related to the one that first declared it (PHP manual,
     * Visibility; `zend_check_protected` in php-src).
     */
    #[DataProvider('memberAccessCases')]
    public function testAMethodIsFoundOnlyWhereCodeMayCallIt(
        ?string $from,
        string $class,
        string $method,
        ?string $expectedOwner,
    ): void {
        self::assertSame(
            $expectedOwner,
            $this->resolverOverAccessGraph()->findMethod(
                ClasslikeName::fromFullyQualified($class),
                $method,
                $from === null ? null : ClasslikeName::fromFullyQualified($from),
            )?->name->owner->qualifiedName->fullyQualifiedName(),
            "{$class}::{$method}() from " . ($from ?? 'outside any class'),
        );
    }

    public function testAListingFromASiblingHoldsWhatTheSharedParentDeclares(): void
    {
        $members = $this->resolverOverAccessGraph()->getMembersOfKind(
            ClasslikeName::fromFullyQualified('App\\Child'),
            MemberKind::Method,
            ClasslikeName::fromFullyQualified('App\\Sibling'),
        );

        self::assertEqualsCanonicalizing(
            ['basePublic', 'baseProtected', 'overridden'],
            array_map(static fn ($m): string => $m->getName()->name, $members),
            'what the shared parent declares, but not what only Child declares or anything private',
        );
    }

    public function testAProtectedConstantIsCheckedAgainstItsNearestDeclaration(): void
    {
        $parent = ClasslikeName::fromFullyQualified('App\\P');
        $redeclares = ClasslikeName::fromFullyQualified('App\\C1');
        $resolver = $this->resolverOverClasses(
            $this->createClassInfo($parent, constants: [
                'X' => $this->createConstantInfo('X', Visibility::Protected, $parent),
            ]),
            $this->createClassInfo($redeclares, parent: $parent, constants: [
                'X' => $this->createConstantInfo('X', Visibility::Protected, $redeclares),
            ]),
            $this->createClassInfo(ClasslikeName::fromFullyQualified('App\\C2'), parent: $parent),
        );

        self::assertNull(
            $resolver->findConstant($redeclares, 'X', ClasslikeName::fromFullyQualified('App\\C2')),
            'C1::X is declared in C1, which a sibling is not related to, whoever declared X first',
        );
    }

    public function testAConstantHasNoParentPrivateException(): void
    {
        $parent = ClasslikeName::fromFullyQualified('App\\P');
        $child = ClasslikeName::fromFullyQualified('App\\C');
        $childX = $this->createConstantInfo('X', Visibility::Public, $child);
        $resolver = $this->resolverOverClasses(
            $this->createClassInfo($parent, constants: [
                'X' => $this->createConstantInfo('X', Visibility::Private, $parent),
            ]),
            $this->createClassInfo($child, parent: $parent, constants: ['X' => $childX]),
        );

        self::assertSame(
            $childX,
            $resolver->findConstant($child, 'X', $parent),
            'C::X from P is C\'s constant: a parent\'s own private one applies only to methods and properties',
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function lateBindingCases(): iterable
    {
        yield 'static, on the class it is called on' => ['App\\Sub', 'copy', 'App\\Sub'];
        yield 'self, on the class declaring it' => ['App\\Sub', 'same', 'App\\Base'];
        yield 'parent, on the declaring class\'s parent' => ['App\\Sub', 'up', 'App\\Root'];
        yield 'self from a trait, on the class using it' => ['App\\Sub', 'fromTrait', 'App\\Base'];
        yield 'parent from a trait, on the using class\'s parent' => ['App\\Sub', 'upFromTrait', 'App\\Root'];
        yield 'self from a trait alias, on the class using it' => ['App\\Base', 'aliased', 'App\\Base'];
    }

    /**
     * `static` names the class a member is called on; `self` the class that
     * declares it, which for a trait's member is the class using the trait;
     * `parent` that class's parent (PHP manual, Late Static Bindings; Traits).
     */
    #[DataProvider('lateBindingCases')]
    public function testAMemberIsReturnedWithItsLateBindingResolved(
        string $class,
        string $method,
        string $expected,
    ): void {
        $resolved = $this->resolverOverLateBindingGraph()->findMethod(
            ClasslikeName::fromFullyQualified($class),
            $method,
            null,
        );

        self::assertSame($expected, $resolved?->returnType?->format(), "{$class}::{$method}()");
    }

    public function testAParentCallBindsStaticToTheClassCalledFrom(): void
    {
        $sub = ClasslikeName::fromFullyQualified('App\\Sub');

        $resolved = $this->resolverOverLateBindingGraph()->findMethod(
            ClasslikeName::fromFullyQualified('App\\Base'),
            'copy',
            $sub,
            $sub,
        );

        self::assertSame('App\\Sub', $resolved?->returnType?->format(), 'parent:: passes on the calling class');
    }

    public function testAListingBindsEachMember(): void
    {
        $resolver = $this->resolverOverLateBindingGraph();
        $returns = [];
        foreach (['App\\Sub', 'App\\Base'] as $class) {
            $members = $resolver->getMembersOfKind(ClasslikeName::fromFullyQualified($class), MemberKind::Method, null);
            foreach ($members as $member) {
                $returns[$class][$member->getName()->name] = $member->getType()?->format();
            }
        }

        self::assertSame('App\\Sub', $returns['App\\Sub']['copy'] ?? null, 'static, as in a lookup');
        self::assertSame('App\\Base', $returns['App\\Sub']['fromTrait'] ?? null, 'a trait\'s self, as in a lookup');
        self::assertSame('App\\Base', $returns['App\\Base']['aliased'] ?? null, 'an alias, as in a lookup');
    }

    public function testAParentListingBindsStaticToTheClassCalledFrom(): void
    {
        $sub = ClasslikeName::fromFullyQualified('App\\Sub');
        $returns = [];
        $members = $this->resolverOverLateBindingGraph()->getMembersOfKind(
            ClasslikeName::fromFullyQualified('App\\Base'),
            MemberKind::Method,
            $sub,
            static: $sub,
        );
        foreach ($members as $member) {
            $returns[$member->getName()->name] = $member->getType()?->format();
        }

        self::assertSame('App\\Sub', $returns['copy'] ?? null, 'parent:: passes on the calling class');
    }

    public function testAPropertyTypeIsBoundToo(): void
    {
        $resolved = $this->resolverOverLateBindingGraph()->findProperty(
            ClasslikeName::fromFullyQualified('App\\Sub'),
            'next',
            null,
        );

        self::assertSame('App\\Base', $resolved?->type?->format(), 'self in a trait\'s property is the using class');
    }

    public function testAPropertyFollowsTheSameRule(): void
    {
        $base = ClasslikeName::fromFullyQualified('App\\Base');
        $child = ClasslikeName::fromFullyQualified('App\\Child');
        $property = $this->createPropertyInfo('shared', Visibility::Protected, $base);
        $resolver = $this->resolverOverClasses(
            $this->createClassInfo($base, properties: ['shared' => $property]),
            $this->createClassInfo($child, parent: $base),
            $this->createClassInfo(ClasslikeName::fromFullyQualified('App\\Sibling'), parent: $base),
        );

        self::assertSame(
            $property,
            $resolver->findProperty($child, 'shared', ClasslikeName::fromFullyQualified('App\\Sibling')),
            'a protected property the shared parent declares is reachable from a sibling',
        );
        self::assertNull($resolver->findProperty($child, 'shared', null), 'but not from outside any class');
    }

    /**
     * Base declares members of each visibility; Child and Sibling extend it;
     * Uses takes private methods from the trait Helper and declares one of them
     * itself; UsesChild extends Uses and redeclares that one publicly.
     */
    private function resolverOverAccessGraph(): MemberResolver
    {
        $base = ClasslikeName::fromFullyQualified('App\\Base');
        $child = ClasslikeName::fromFullyQualified('App\\Child');
        $helper = ClasslikeName::fromFullyQualified('App\\Helper');
        $uses = ClasslikeName::fromFullyQualified('App\\Uses');
        $usesChild = ClasslikeName::fromFullyQualified('App\\UsesChild');
        return $this->resolverOverClasses(
            $this->createClassInfo($base, methods: $this->methodsWith($base, [
                'basePublic' => Visibility::Public,
                'baseProtected' => Visibility::Protected,
                'basePrivate' => Visibility::Private,
                'overridden' => Visibility::Protected,
                'shadowed' => Visibility::Private,
            ])),
            $this->createClassInfo($child, parent: $base, methods: $this->methodsWith($child, [
                'childProtected' => Visibility::Protected,
                'childPrivate' => Visibility::Private,
                'overridden' => Visibility::Protected,
                'shadowed' => Visibility::Private,
            ])),
            $this->createClassInfo(ClasslikeName::fromFullyQualified('App\\Sibling'), parent: $base),
            $this->createClassInfo(ClasslikeName::fromFullyQualified('App\\Unrelated')),
            $this->createClassInfo($helper, ClassKind::Trait_, methods: $this->methodsWith($helper, [
                'helperPrivate' => Visibility::Private,
                'both' => Visibility::Private,
            ])),
            $this->createClassInfo($uses, traits: [$helper], methods: $this->methodsWith($uses, [
                'both' => Visibility::Private,
            ])),
            $this->createClassInfo($usesChild, parent: $uses, methods: $this->methodsWith($usesChild, [
                'both' => Visibility::Public,
            ])),
        );
    }

    /**
     * Root, then Base extending it and using the trait Mixin, then Sub
     * extending Base; Base also exposes Mixin's `fromTrait` as `aliased`.
     */
    private function resolverOverLateBindingGraph(): MemberResolver
    {
        $root = ClasslikeName::fromFullyQualified('App\\Root');
        $base = ClasslikeName::fromFullyQualified('App\\Base');
        $mixin = ClasslikeName::fromFullyQualified('App\\Mixin');
        $returning = fn (ClasslikeName $owner, string $name, LateBindingKeyword $keyword): MethodInfo => new MethodInfo(
            name: new MethodName($owner, $name),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: new LateStaticType($keyword),
            docblock: null,
            file: null,
            line: null,
        );
        $next = new PropertyInfo(
            name: new PropertyName($mixin, 'next'),
            visibility: Visibility::Public,
            isStatic: false,
            isReadonly: false,
            isPromoted: false,
            type: new LateStaticType(LateBindingKeyword::Self),
            docblock: null,
            file: null,
            line: null,
        );

        return $this->resolverOverClasses(
            $this->createClassInfo($root),
            $this->createClassInfo(
                $base,
                parent: $root,
                methods: [
                    'copy' => $returning($base, 'copy', LateBindingKeyword::Static),
                    'same' => $returning($base, 'same', LateBindingKeyword::Self),
                    'up' => $returning($base, 'up', LateBindingKeyword::Parent),
                ],
                traits: [$mixin],
                traitAliases: [
                    new TraitAlias(trait: $mixin, method: 'fromTrait', newName: 'aliased', newVisibility: null),
                ],
            ),
            $this->createClassInfo(
                $mixin,
                ClassKind::Trait_,
                methods: [
                    'fromTrait' => $returning($mixin, 'fromTrait', LateBindingKeyword::Self),
                    'upFromTrait' => $returning($mixin, 'upFromTrait', LateBindingKeyword::Parent),
                ],
                properties: ['next' => $next],
            ),
            $this->createClassInfo(ClasslikeName::fromFullyQualified('App\\Sub'), parent: $base),
        );
    }

    /**
     * @param array<string, Visibility> $visibilities
     * @return array<string, MethodInfo>
     */
    private function methodsWith(ClasslikeName $owner, array $visibilities): array
    {
        $methods = [];
        foreach ($visibilities as $name => $visibility) {
            $methods[$name] = $this->createMethodInfo($name, $visibility, $owner);
        }
        return $methods;
    }

    /**
     * @param list<array{0: string, 1: ClassKind, 2: ?string, 3: list<string>}> $graph
     */
    private function resolverOverGraph(array $graph): MemberResolver
    {
        $infos = [];
        foreach ($graph as [$fqn, $kind, $parent, $interfaces]) {
            $infos[] = $this->createClassInfo(
                ClasslikeName::fromFullyQualified($fqn),
                $kind,
                parent: $parent !== null ? ClasslikeName::fromFullyQualified($parent) : null,
                interfaces: array_map(
                    fn (string $i): ClasslikeName => ClasslikeName::fromFullyQualified($i),
                    $interfaces,
                ),
            );
        }

        return $this->resolverOverClasses(...$infos);
    }

    private function resolverOverClasses(ClassInfo ...$classes): MemberResolver
    {
        $infos = [];
        foreach ($classes as $info) {
            $infos[NameKind::ClassLike->normalize($info->name->qualifiedName)] = $info;
        }

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $q) => $infos[NameKind::ClassLike->normalize($q->qualifiedName)]
                ?? null,
        );

        return new MemberResolver($repo);
    }

    /**
     * @return class-string
     */
    /**
     * Code being edited can use traits in a cycle, which PHP rejects; the
     * lookup must still end.
     */
    public function testATraitCycleEnds(): void
    {
        $a = ClasslikeName::fromFullyQualified(self::fakeClass());
        $b = ClasslikeName::fromFullyQualified(self::fakeClass());
        $user = ClasslikeName::fromFullyQualified(self::fakeClass());
        $resolver = new MemberResolver($this->sourceOf(
            $this->createClassInfo(
                $a,
                kind: ClassKind::Trait_,
                methods: ['run' => $this->createMethodInfo('run', Visibility::Public, $a)],
                traits: [$a, $b],
            ),
            $this->createClassInfo($b, kind: ClassKind::Trait_, traits: [$a]),
            $this->createClassInfo($user, traits: [$a]),
        ));

        self::assertSame(
            'run',
            $resolver->findMethod($user, 'run', null)?->getName()->name,
            'a trait using itself or a trait that uses it back still brings in its own members',
        );
    }

    /**
     * An abstract trait method meeting an inherited one only has its signature
     * checked (`zend_add_trait_method`), so protected access is still checked
     * against the class that first declared the method.
     */
    public function testAnAbstractTraitMethodLeavesProtectedAccessToTheInheritedOne(): void
    {
        $parent = ClasslikeName::fromFullyQualified(self::fakeClass());
        $trait = ClasslikeName::fromFullyQualified(self::fakeClass());
        $user = ClasslikeName::fromFullyQualified(self::fakeClass());
        $sibling = ClasslikeName::fromFullyQualified(self::fakeClass());
        $resolver = new MemberResolver($this->sourceOf(
            $this->createClassInfo(
                $parent,
                methods: ['run' => $this->createMethodInfo('run', Visibility::Protected, $parent)],
            ),
            $this->createClassInfo(
                $trait,
                kind: ClassKind::Trait_,
                methods: ['run' => $this->createMethodInfo('run', Visibility::Protected, $trait, isAbstract: true)],
            ),
            $this->createClassInfo($user, parent: $parent, traits: [$trait]),
            $this->createClassInfo($sibling, parent: $parent),
        ));

        $found = $resolver->findMethod($user, 'run', $sibling);

        self::assertNotNull($found, 'a sibling of the using class shares the protected method\'s first declarer');
        self::assertSame($parent, $found->getName()->owner, 'the inherited method stays');
    }

    private static function fakeClass(): string
    {
        // @phpstan-ignore return.type
        return 'Fake\\Class' . random_int(0, PHP_INT_MAX);
    }

    private function sourceOf(ClassInfo ...$classes): SymbolSourceInterface
    {
        $byName = [];
        foreach ($classes as $class) {
            $byName[$class->name->qualifiedName->fullyQualifiedName()] = $class;
        }
        $source = self::createStub(SymbolSourceInterface::class);
        $source->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => $byName[$name->qualifiedName->fullyQualifiedName()] ?? null,
        );

        return $source;
    }

    /**
     * @param array<string, MethodInfo> $methods
     * @param array<string, PropertyInfo> $properties
     * @param array<string, ClasslikeConstantInfo> $constants
     * @param array<string, EnumCaseInfo> $enumCases
     * @param list<ClasslikeName> $traits
     * @param list<ClasslikeName> $interfaces
     * @param list<\Firehed\PhpLsp\Domain\TraitAlias> $traitAliases
     */
    private function createClassInfo(
        ClasslikeName $name,
        ClassKind $kind = ClassKind::Class_,
        ?ClasslikeName $parent = null,
        array $methods = [],
        array $properties = [],
        array $constants = [],
        array $enumCases = [],
        array $traits = [],
        array $interfaces = [],
        array $traitAliases = [],
    ): ClassInfo {
        return new ClassInfo(
            name: $name,
            kind: $kind,
            isAbstract: false,
            isFinal: false,
            isReadonly: false,
            isAttribute: false,
            parent: $parent,
            interfaces: $interfaces,
            traits: $traits,
            methods: $methods,
            properties: $properties,
            constants: $constants,
            enumCases: $enumCases,
            docblock: null,
            file: null,
            line: null,
            traitAliases: $traitAliases,
        );
    }

    private function createMethodInfo(
        string $name,
        Visibility $visibility,
        ClasslikeName $declaringClass,
        bool $isStatic = false,
        bool $isAbstract = false,
    ): MethodInfo {
        return new MethodInfo(
            name: new MethodName($declaringClass, $name),
            visibility: $visibility,
            isStatic: $isStatic,
            isAbstract: $isAbstract,
            isFinal: false,
            parameters: [],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
        );
    }

    private function createPropertyInfo(
        string $name,
        Visibility $visibility,
        ClasslikeName $declaringClass,
        bool $isStatic = false,
    ): PropertyInfo {
        return new PropertyInfo(
            name: new PropertyName($declaringClass, $name),
            visibility: $visibility,
            isStatic: $isStatic,
            isReadonly: false,
            isPromoted: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
        );
    }

    private function createConstantInfo(
        string $name,
        Visibility $visibility,
        ClasslikeName $declaringClass,
    ): ClasslikeConstantInfo {
        return new ClasslikeConstantInfo(
            name: new ClasslikeConstantName($declaringClass, $name),
            visibility: $visibility,
            isFinal: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
        );
    }

    private function createEnumCaseInfo(string $name, ClasslikeName $declaringClass): EnumCaseInfo
    {
        return new EnumCaseInfo(
            name: new EnumCaseName($declaringClass, $name),
            backingValue: null,
            docblock: null,
            file: null,
            line: null,
        );
    }

    #[DataProvider('allMemberKinds')]
    public function testGetMembersOfKindReturnsEmptyForUnknownClass(MemberKind $kind): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        self::assertSame(
            [],
            $resolver->getMembersOfKind(
                ClasslikeName::fromFullyQualified(self::fakeClass()),
                $kind,
                null,
            ),
            'an unknown class has no members of any kind',
        );
    }
}
