<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberKind::class)]
#[CoversClass(NameCase::class)]
class MemberKindTest extends TestCase
{
    public function testMembersOfReadsTheMapForItsKind(): void
    {
        $constant = self::createConstantInfo();
        $enumCase = self::createEnumCaseInfo();
        $method = self::createMethodInfo();
        $property = self::createPropertyInfo();
        $classInfo = self::createClassInfo($constant, $enumCase, $method, $property);

        self::assertSame(['MAX' => $constant], MemberKind::Constant->membersOf($classInfo));
        self::assertSame(['Draft' => $enumCase], MemberKind::EnumCase->membersOf($classInfo));
        self::assertSame(['run' => $method], MemberKind::Method->membersOf($classInfo));
        self::assertSame(['value' => $property], MemberKind::Property->membersOf($classInfo));
    }

    public function testMethodNamesAreMatchedCaseInsensitively(): void
    {
        self::assertSame(
            MemberKind::Method->normalize('run'),
            MemberKind::Method->normalize('RUN'),
        );
    }

    #[DataProvider('caseSensitiveKinds')]
    public function testEveryOtherKindIsMatchedLetterForLetter(MemberKind $kind): void
    {
        self::assertNotSame($kind->normalize('value'), $kind->normalize('VALUE'));
        self::assertSame('value', $kind->normalize('value'));
    }

    public function testKeyForSeparatesTwoKindsSharingAName(): void
    {
        self::assertNotSame(
            MemberKind::Method->keyFor('value'),
            MemberKind::Property->keyFor('value'),
        );
    }

    public function testKeyForCollapsesACaseVariedMethodOverride(): void
    {
        self::assertSame(
            MemberKind::Method->keyFor('overriddenMethod'),
            MemberKind::Method->keyFor('OVERRIDDENMETHOD'),
        );
    }

    /**
     * @return iterable<string, array{MemberKind}>
     */
    public static function caseSensitiveKinds(): iterable
    {
        yield 'constant' => [MemberKind::Constant];
        yield 'enum case' => [MemberKind::EnumCase];
        yield 'property' => [MemberKind::Property];
    }

    private static function createClassInfo(
        ConstantInfo $constant,
        EnumCaseInfo $enumCase,
        MethodInfo $method,
        PropertyInfo $property,
    ): ClassInfo {
        return new ClassInfo(
            name: new ClassName(self::class),
            kind: ClassKind::Class_,
            isAbstract: false,
            isFinal: false,
            isReadonly: false,
            isAttribute: false,
            parent: null,
            interfaces: [],
            traits: [],
            methods: ['run' => $method],
            properties: ['value' => $property],
            constants: ['MAX' => $constant],
            enumCases: ['Draft' => $enumCase],
            docblock: null,
            file: null,
            line: null,
        );
    }

    private static function createConstantInfo(): ConstantInfo
    {
        return new ConstantInfo(
            name: new ConstantName('MAX'),
            visibility: Visibility::Public,
            isFinal: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );
    }

    private static function createEnumCaseInfo(): EnumCaseInfo
    {
        return new EnumCaseInfo(
            name: new EnumCaseName('Draft'),
            backingValue: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );
    }

    private static function createMethodInfo(): MethodInfo
    {
        return new MethodInfo(
            name: new MethodName('run'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );
    }

    private static function createPropertyInfo(): PropertyInfo
    {
        return new PropertyInfo(
            name: new PropertyName('value'),
            visibility: Visibility::Public,
            isStatic: false,
            isReadonly: false,
            isPromoted: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );
    }
}
