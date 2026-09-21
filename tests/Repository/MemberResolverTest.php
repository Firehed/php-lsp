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
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\TraitAlias;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberResolver::class)]
final class MemberResolverTest extends TestCase
{
    public function testFindMethodReturnsNullForUnknownClass(): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $result = $resolver->findMethod($className, 'foo', Visibility::Public);

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
            Visibility::Public,
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
            Visibility::Public,
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
            new EnumCaseName('Foo'),
        );

        self::assertNull($result);
    }

    public function testGetMethodsReturnsEmptyForUnknownClass(): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMethods(ClasslikeName::fromFullyQualified(self::fakeClass()), Visibility::Public);

        self::assertSame([], $result);
    }

    public function testGetPropertiesReturnsEmptyForUnknownClass(): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        $result = $resolver->getProperties(ClasslikeName::fromFullyQualified(self::fakeClass()), Visibility::Public);

        self::assertSame([], $result);
    }

    public function testGetConstantsReturnsEmptyForUnknownClass(): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        $result = $resolver->getConstants(ClasslikeName::fromFullyQualified(self::fakeClass()), Visibility::Public);

        self::assertSame([], $result);
    }

    public function testGetEnumCasesReturnsEmptyForUnknownClass(): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        $result = $resolver->getEnumCases(ClasslikeName::fromFullyQualified(self::fakeClass()));

        self::assertSame([], $result);
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

        $result = $resolver->findMethod($className, 'doSomething', Visibility::Public);

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

        $result = $resolver->findMethod($childName, 'parentMethod', Visibility::Public);

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

        $result = $resolver->findMethod($className, 'traitMethod', Visibility::Public);

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

        $result = $resolver->findMethod($className, 'privateMethod', Visibility::Public);

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

        $result = $resolver->findMethod($childName, 'privateMethod', Visibility::Private);

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

        $result = $resolver->findMethod($className, 'privateMethod', Visibility::Private);

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

        $result = $resolver->findProperty($className, 'myProp', Visibility::Public);

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

        $result = $resolver->findProperty($childName, 'parentProp', Visibility::Public);

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

        $result = $resolver->findProperty($className, 'traitProp', Visibility::Public);

        self::assertSame($propInfo, $result);
    }

    public function testFindPropertyReturnsNullWhenNotFound(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $classInfo = $this->createClassInfo($className);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findProperty($className, 'nonexistent', Visibility::Public);

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

        $result = $resolver->findConstant($className, 'MY_CONST', Visibility::Public);

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

        $result = $resolver->findConstant($childName, 'PARENT_CONST', Visibility::Public);

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

        $result = $resolver->findConstant($className, 'TRAIT_CONST', Visibility::Public);

        self::assertSame($constInfo, $result);
    }

    public function testFindConstantReturnsNullWhenNotFound(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $classInfo = $this->createClassInfo($className);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findConstant($className, 'NONEXISTENT', Visibility::Public);

        self::assertNull($result);
    }

    public function testGetMethodsReturnsAllAccessibleMethods(): void
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

        $result = $resolver->getMethods($childName, Visibility::Private);

        self::assertCount(3, $result);
        self::assertContains($childMethod, $result);
        self::assertContains($parentPublic, $result);
        self::assertContains($parentProtected, $result);
        self::assertNotContains($parentPrivate, $result);
    }

    public function testGetMethodsFiltersStatic(): void
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

        $staticOnly = $resolver->getMethods($className, Visibility::Public, MemberFilter::Static);
        $instanceOnly = $resolver->getMethods($className, Visibility::Public, MemberFilter::Instance);

        self::assertSame([$staticMethod], $staticOnly);
        self::assertSame([$instanceMethod], $instanceOnly);
    }

    public function testGetPropertiesReturnsAllAccessibleProperties(): void
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

        $result = $resolver->getProperties($className, Visibility::Protected);

        self::assertCount(2, $result);
    }

    public function testGetPropertiesIncludesParentProperties(): void
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

        $result = $resolver->getProperties($childName, Visibility::Private);

        self::assertCount(3, $result);
        self::assertContains($childProp, $result);
        self::assertContains($parentPublic, $result);
        self::assertContains($parentProtected, $result);
        self::assertNotContains($parentPrivate, $result);
    }

    public function testGetPropertiesFiltersStatic(): void
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

        $staticOnly = $resolver->getProperties($className, Visibility::Public, MemberFilter::Static);
        $instanceOnly = $resolver->getProperties($className, Visibility::Public, MemberFilter::Instance);

        self::assertSame([$staticProp], $staticOnly);
        self::assertSame([$instanceProp], $instanceOnly);
    }

    public function testGetPropertiesIncludesTraitProperties(): void
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

        $result = $resolver->getProperties($className, Visibility::Public);

        self::assertCount(2, $result);
        self::assertContains($classProp, $result);
        self::assertContains($traitProp, $result);
    }

    public function testGetConstantsReturnsAllAccessibleConstants(): void
    {
        $className = ClasslikeName::fromFullyQualified(self::fakeClass());
        $const1 = $this->createConstantInfo('CONST1', Visibility::Public, $className);

        $classInfo = $this->createClassInfo($className, constants: ['CONST1' => $const1]);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->getConstants($className, Visibility::Public);

        self::assertSame([$const1], $result);
    }

    public function testGetConstantsIncludesParentConstants(): void
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

        $result = $resolver->getConstants($childName, Visibility::Public);

        self::assertCount(2, $result);
        self::assertContains($childConst, $result);
        self::assertContains($parentConst, $result);
    }

    public function testGetConstantsIncludesTraitConstants(): void
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

        $result = $resolver->getConstants($className, Visibility::Public);

        self::assertCount(2, $result);
        self::assertContains($classConst, $result);
        self::assertContains($traitConst, $result);
    }

    public function testGetEnumCasesReturnsAllCases(): void
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

        $result = $resolver->getEnumCases($enumName);

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

        $result = $resolver->findEnumCase($enumName, new EnumCaseName('Case2'));

        self::assertSame($case2, $result);
    }

    public function testFindEnumCaseReturnsNullWhenNotFound(): void
    {
        $enumName = ClasslikeName::fromFullyQualified(self::fakeClass());
        $enumInfo = $this->createClassInfo($enumName, kind: ClassKind::Enum_);

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn($enumInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findEnumCase($enumName, new EnumCaseName('NonExistent'));

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

        $result = $resolver->getMethods($childName, Visibility::Public);

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

        $result = $resolver->findMethod($childName, 'nonexistent', Visibility::Public);

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

        $result = $resolver->findProperty($childName, 'nonexistent', Visibility::Public);

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

        $result = $resolver->findConstant($childName, 'NONEXISTENT', Visibility::Public);

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

        $result = $resolver->findMethod($className, 'method2', Visibility::Public);

        self::assertSame($method2, $result);
    }

    public function testGetConstantsFiltersInaccessibleConstants(): void
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

        $result = $resolver->getConstants($className, Visibility::Public);

        self::assertSame([$publicConst], $result);
    }

    public function testGetMethodsChildOverridesParent(): void
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

        $result = $resolver->getMethods($childName, Visibility::Public);

        self::assertCount(1, $result);
        self::assertSame($childMethod, $result[0]);
    }

    public function testGetPropertiesChildOverridesParent(): void
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

        $result = $resolver->getProperties($childName, Visibility::Public);

        self::assertCount(1, $result);
        self::assertSame($childProp, $result[0]);
    }

    public function testGetConstantsChildOverridesParent(): void
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

        $result = $resolver->getConstants($childName, Visibility::Public);

        self::assertCount(1, $result);
        self::assertSame($childConst, $result[0]);
    }

    public function testGetPropertiesDiamondInheritance(): void
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

        $result = $resolver->getProperties($childName, Visibility::Public);

        self::assertCount(1, $result);
    }

    public function testGetConstantsDiamondInheritance(): void
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

        $result = $resolver->getConstants($childName, Visibility::Public);

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

        $result = $resolver->findMethod($childName, 'method', Visibility::Public);

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

        $result = $resolver->findMethod($childName, 'deepMethod', Visibility::Public);

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

        $result = $resolver->findConstant($className, 'INTERFACE_CONST', Visibility::Public);

        self::assertSame($interfaceConst, $result);
    }

    public function testGetConstantsIncludesInterfaceConstants(): void
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

        $result = $resolver->getConstants($className, Visibility::Public);

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

        $result = $resolver->findMethod($className, 'OVERRIDDENMETHOD', Visibility::Public);

        self::assertSame($methodInfo, $result);
    }

    public function testGetMethodsTreatsCaseVariedOverrideAsOneMethod(): void
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

        $result = $resolver->getMethods($childName, Visibility::Public);

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

        $result = $resolver->findProperty($className, 'VALUE', Visibility::Public);

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

        $result = $resolver->findConstant($className, 'Value', Visibility::Public);

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

        self::assertNull($resolver->findEnumCase($enumName, new EnumCaseName('DRAFT')));
    }

    public function testGetConstantsKeepsCaseVariedNamesApart(): void
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

        $result = $resolver->getConstants($childName, Visibility::Public);

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
            $resolver->findMethod($className, 'exposedName', Visibility::Public),
            'an alias whose source method is missing must not surface a method',
        );
        self::assertSame(
            [],
            $resolver->getMethods($className, Visibility::Public),
            'the missing-source alias must not appear in the enumerated methods either',
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

        $resolved = $resolver->findMethod($className, 'exposedName', Visibility::Public);

        self::assertNotNull($resolved, 'the alias with no explicit trait must resolve through the used-trait scan');
        self::assertSame(
            $className->qualifiedName->fullyQualifiedName(),
            $resolved->getDeclaringClass()->qualifiedName->fullyQualifiedName(),
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
            $resolver->findMethod($className, 'exposedName', Visibility::Public),
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
        $infos = [];
        foreach ($graph as [$fqn, $kind, $parent, $interfaces]) {
            $info = $this->createClassInfo(
                ClasslikeName::fromFullyQualified($fqn),
                $kind,
                parent: $parent !== null ? ClasslikeName::fromFullyQualified($parent) : null,
                interfaces: array_map(
                    fn (string $i): ClasslikeName => ClasslikeName::fromFullyQualified($i),
                    $interfaces,
                ),
            );
            $infos[NameKind::ClassLike->normalize(QualifiedName::fromFullyQualified($fqn))] = $info;
        }

        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $q) => $infos[NameKind::ClassLike->normalize($q->qualifiedName)]
                ?? null,
        );

        $resolver = new MemberResolver($repo);

        self::assertSame(
            $expected,
            $resolver->isSubclassOf(
                ClasslikeName::fromFullyQualified($subject),
                ClasslikeName::fromFullyQualified($target),
            ),
            "isSubclassOf walking from {$subject} to {$target}",
        );
    }

    /**
     * @return class-string
     */
    private static function fakeClass(): string
    {
        // @phpstan-ignore return.type
        return 'Fake\\Class' . random_int(0, PHP_INT_MAX);
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
    ): MethodInfo {
        return new MethodInfo(
            name: new MethodName($declaringClass, $name),
            visibility: $visibility,
            isStatic: $isStatic,
            isAbstract: false,
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
            name: new EnumCaseName($name),
            backingValue: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: $declaringClass,
        );
    }

    public function testGetMembersOfKindReturnsEmptyForUnknownClass(): void
    {
        $repo = self::createStub(SymbolSourceInterface::class);
        $repo->method('lookupClassLike')->willReturn(null);

        $resolver = new MemberResolver($repo);

        self::assertSame(
            [],
            $resolver->getMembersOfKind(
                ClasslikeName::fromFullyQualified(self::fakeClass()),
                \Firehed\PhpLsp\Domain\MemberKind::Method,
                Visibility::Public,
            ),
        );
    }
}
