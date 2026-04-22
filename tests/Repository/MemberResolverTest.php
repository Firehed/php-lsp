<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Repository;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Repository\ClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberResolver::class)]
final class MemberResolverTest extends TestCase
{
    public function testFindMethodReturnsNullForUnknownClass(): void
    {
        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturn(null);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod(
            new ClassName(self::fakeClass()),
            new MethodName('foo'),
            Visibility::Public,
        );

        self::assertNull($result);
    }

    public function testFindMethodReturnsMethodFromClass(): void
    {
        $className = new ClassName(self::fakeClass());
        $methodInfo = $this->createMethodInfo('doSomething', Visibility::Public, $className);
        $classInfo = $this->createClassInfo($className, methods: ['doSomething' => $methodInfo]);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturnMap([
            [$className, $classInfo],
        ]);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($className, new MethodName('doSomething'), Visibility::Public);

        self::assertSame($methodInfo, $result);
    }

    public function testFindMethodReturnsMethodFromParent(): void
    {
        $parentName = new ClassName(self::fakeClass());
        $childName = new ClassName(self::fakeClass());
        $methodInfo = $this->createMethodInfo('parentMethod', Visibility::Public, $parentName);

        $parentInfo = $this->createClassInfo($parentName, methods: ['parentMethod' => $methodInfo]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturnCallback(
            fn (ClassName $name) => match ($name->fqn) {
                $parentName->fqn => $parentInfo,
                $childName->fqn => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($childName, new MethodName('parentMethod'), Visibility::Public);

        self::assertSame($methodInfo, $result);
    }

    public function testFindMethodReturnsMethodFromTrait(): void
    {
        $traitName = new ClassName(self::fakeClass());
        $className = new ClassName(self::fakeClass());
        $methodInfo = $this->createMethodInfo('traitMethod', Visibility::Public, $traitName);

        $traitInfo = $this->createClassInfo(
            $traitName,
            kind: ClassKind::Trait_,
            methods: ['traitMethod' => $methodInfo],
        );
        $classInfo = $this->createClassInfo($className, traits: [$traitName]);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturnCallback(
            fn (ClassName $name) => match ($name->fqn) {
                $traitName->fqn => $traitInfo,
                $className->fqn => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($className, new MethodName('traitMethod'), Visibility::Public);

        self::assertSame($methodInfo, $result);
    }

    public function testFindMethodFiltersVisibility(): void
    {
        $className = new ClassName(self::fakeClass());
        $privateMethod = $this->createMethodInfo('privateMethod', Visibility::Private, $className);
        $classInfo = $this->createClassInfo($className, methods: ['privateMethod' => $privateMethod]);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($className, new MethodName('privateMethod'), Visibility::Public);

        self::assertNull($result);
    }

    public function testFindMethodExcludesParentPrivateMethods(): void
    {
        $parentName = new ClassName(self::fakeClass());
        $childName = new ClassName(self::fakeClass());
        $privateMethod = $this->createMethodInfo('privateMethod', Visibility::Private, $parentName);

        $parentInfo = $this->createClassInfo($parentName, methods: ['privateMethod' => $privateMethod]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturnCallback(
            fn (ClassName $name) => match ($name->fqn) {
                $parentName->fqn => $parentInfo,
                $childName->fqn => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($childName, new MethodName('privateMethod'), Visibility::Private);

        self::assertNull($result);
    }

    public function testFindMethodIncludesTraitPrivateMethods(): void
    {
        $traitName = new ClassName(self::fakeClass());
        $className = new ClassName(self::fakeClass());
        $privateMethod = $this->createMethodInfo('privateMethod', Visibility::Private, $traitName);

        $traitInfo = $this->createClassInfo(
            $traitName,
            kind: ClassKind::Trait_,
            methods: ['privateMethod' => $privateMethod],
        );
        $classInfo = $this->createClassInfo($className, traits: [$traitName]);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturnCallback(
            fn (ClassName $name) => match ($name->fqn) {
                $traitName->fqn => $traitInfo,
                $className->fqn => $classInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($className, new MethodName('privateMethod'), Visibility::Private);

        self::assertSame($privateMethod, $result);
    }

    public function testFindPropertyReturnsPropertyFromClass(): void
    {
        $className = new ClassName(self::fakeClass());
        $propInfo = $this->createPropertyInfo('myProp', Visibility::Public, $className);
        $classInfo = $this->createClassInfo($className, properties: ['myProp' => $propInfo]);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findProperty($className, new PropertyName('myProp'), Visibility::Public);

        self::assertSame($propInfo, $result);
    }

    public function testFindConstantReturnsConstantFromClass(): void
    {
        $className = new ClassName(self::fakeClass());
        $constInfo = $this->createConstantInfo('MY_CONST', Visibility::Public, $className);
        $classInfo = $this->createClassInfo($className, constants: ['MY_CONST' => $constInfo]);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->findConstant($className, new ConstantName('MY_CONST'), Visibility::Public);

        self::assertSame($constInfo, $result);
    }

    public function testGetMethodsReturnsAllAccessibleMethods(): void
    {
        $parentName = new ClassName(self::fakeClass());
        $childName = new ClassName(self::fakeClass());

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

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturnCallback(
            fn (ClassName $name) => match ($name->fqn) {
                $parentName->fqn => $parentInfo,
                $childName->fqn => $childInfo,
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
        $className = new ClassName(self::fakeClass());
        $instanceMethod = $this->createMethodInfo('instance', Visibility::Public, $className, isStatic: false);
        $staticMethod = $this->createMethodInfo('static', Visibility::Public, $className, isStatic: true);

        $classInfo = $this->createClassInfo($className, methods: [
            'instance' => $instanceMethod,
            'static' => $staticMethod,
        ]);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $staticOnly = $resolver->getMethods($className, Visibility::Public, static: true);
        $instanceOnly = $resolver->getMethods($className, Visibility::Public, static: false);

        self::assertSame([$staticMethod], $staticOnly);
        self::assertSame([$instanceMethod], $instanceOnly);
    }

    public function testGetPropertiesReturnsAllAccessibleProperties(): void
    {
        $className = new ClassName(self::fakeClass());
        $prop1 = $this->createPropertyInfo('prop1', Visibility::Public, $className);
        $prop2 = $this->createPropertyInfo('prop2', Visibility::Protected, $className);

        $classInfo = $this->createClassInfo($className, properties: [
            'prop1' => $prop1,
            'prop2' => $prop2,
        ]);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->getProperties($className, Visibility::Protected);

        self::assertCount(2, $result);
    }

    public function testGetConstantsReturnsAllAccessibleConstants(): void
    {
        $className = new ClassName(self::fakeClass());
        $const1 = $this->createConstantInfo('CONST1', Visibility::Public, $className);

        $classInfo = $this->createClassInfo($className, constants: ['CONST1' => $const1]);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturn($classInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->getConstants($className, Visibility::Public);

        self::assertSame([$const1], $result);
    }

    public function testGetEnumCasesReturnsAllCases(): void
    {
        $enumName = new ClassName(self::fakeClass());
        $case1 = $this->createEnumCaseInfo('Case1', $enumName);
        $case2 = $this->createEnumCaseInfo('Case2', $enumName);

        $enumInfo = $this->createClassInfo($enumName, kind: ClassKind::Enum_, enumCases: [
            'Case1' => $case1,
            'Case2' => $case2,
        ]);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturn($enumInfo);

        $resolver = new MemberResolver($repo);

        $result = $resolver->getEnumCases($enumName);

        self::assertCount(2, $result);
        self::assertContains($case1, $result);
        self::assertContains($case2, $result);
    }

    public function testDiamondInheritanceNoDuplicates(): void
    {
        // Diamond: Child uses Trait1 and Trait2, both use BaseTrait
        $baseTrait = new ClassName(self::fakeClass());
        $trait1 = new ClassName(self::fakeClass());
        $trait2 = new ClassName(self::fakeClass());
        $childName = new ClassName(self::fakeClass());

        $sharedMethod = $this->createMethodInfo('sharedMethod', Visibility::Public, $baseTrait);

        $baseTraitInfo = $this->createClassInfo($baseTrait, kind: ClassKind::Trait_, methods: [
            'sharedMethod' => $sharedMethod,
        ]);
        $trait1Info = $this->createClassInfo($trait1, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $trait2Info = $this->createClassInfo($trait2, kind: ClassKind::Trait_, traits: [$baseTrait]);
        $childInfo = $this->createClassInfo($childName, traits: [$trait1, $trait2]);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturnCallback(
            fn (ClassName $name) => match ($name->fqn) {
                $baseTrait->fqn => $baseTraitInfo,
                $trait1->fqn => $trait1Info,
                $trait2->fqn => $trait2Info,
                $childName->fqn => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->getMethods($childName, Visibility::Public);

        self::assertCount(1, $result);
    }

    public function testChildMethodOverridesParent(): void
    {
        $parentName = new ClassName(self::fakeClass());
        $childName = new ClassName(self::fakeClass());

        $parentMethod = $this->createMethodInfo('method', Visibility::Public, $parentName);
        $childMethod = $this->createMethodInfo('method', Visibility::Public, $childName);

        $parentInfo = $this->createClassInfo($parentName, methods: ['method' => $parentMethod]);
        $childInfo = $this->createClassInfo($childName, parent: $parentName, methods: ['method' => $childMethod]);

        $repo = self::createStub(ClassRepository::class);
        $repo->method('get')->willReturnCallback(
            fn (ClassName $name) => match ($name->fqn) {
                $parentName->fqn => $parentInfo,
                $childName->fqn => $childInfo,
                default => null,
            },
        );

        $resolver = new MemberResolver($repo);

        $result = $resolver->findMethod($childName, new MethodName('method'), Visibility::Public);

        self::assertSame($childMethod, $result);
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
     * @param array<string, ConstantInfo> $constants
     * @param array<string, EnumCaseInfo> $enumCases
     * @param list<ClassName> $traits
     */
    private function createClassInfo(
        ClassName $name,
        ClassKind $kind = ClassKind::Class_,
        ?ClassName $parent = null,
        array $methods = [],
        array $properties = [],
        array $constants = [],
        array $enumCases = [],
        array $traits = [],
    ): ClassInfo {
        return new ClassInfo(
            name: $name,
            kind: $kind,
            isAbstract: false,
            isFinal: false,
            isReadonly: false,
            parent: $parent,
            interfaces: [],
            traits: $traits,
            methods: $methods,
            properties: $properties,
            constants: $constants,
            enumCases: $enumCases,
            docblock: null,
            file: null,
            line: null,
        );
    }

    private function createMethodInfo(
        string $name,
        Visibility $visibility,
        ClassName $declaringClass,
        bool $isStatic = false,
    ): MethodInfo {
        return new MethodInfo(
            name: new MethodName($name),
            visibility: $visibility,
            isStatic: $isStatic,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: $declaringClass,
        );
    }

    private function createPropertyInfo(
        string $name,
        Visibility $visibility,
        ClassName $declaringClass,
        bool $isStatic = false,
    ): PropertyInfo {
        return new PropertyInfo(
            name: new PropertyName($name),
            visibility: $visibility,
            isStatic: $isStatic,
            isReadonly: false,
            isPromoted: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: $declaringClass,
        );
    }

    private function createConstantInfo(
        string $name,
        Visibility $visibility,
        ClassName $declaringClass,
    ): ConstantInfo {
        return new ConstantInfo(
            name: new ConstantName($name),
            visibility: $visibility,
            isFinal: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: $declaringClass,
        );
    }

    private function createEnumCaseInfo(string $name, ClassName $declaringClass): EnumCaseInfo
    {
        return new EnumCaseInfo(
            name: new EnumCaseName($name),
            docblock: null,
            file: null,
            line: null,
            declaringClass: $declaringClass,
        );
    }
}
