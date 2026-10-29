<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberKind::class)]
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

    #[DataProvider('isMethodCases')]
    public function testIsMethod(MemberKind $kind, bool $expected): void
    {
        self::assertSame($expected, $kind->isMethod(), $kind->name . ' isMethod');
    }

    /**
     * Methods and properties are reached through an object's own table, with
     * access checked against the name's first declaration; constants and enum
     * cases against the nearest declaration (`zend_verify_const_access`).
     */
    #[DataProvider('accessByFirstDeclarationCases')]
    public function testAccessIsCheckedByTheFirstDeclarationForObjectMembers(MemberKind $kind, bool $expected): void
    {
        self::assertSame($expected, $kind->checksAccessByFirstDeclaration(), $kind->name);
    }

    /**
     * @return iterable<string, array{MemberKind, bool}>
     */
    public static function accessByFirstDeclarationCases(): iterable
    {
        yield 'constant' => [MemberKind::Constant, false];
        yield 'enum case' => [MemberKind::EnumCase, false];
        yield 'method' => [MemberKind::Method, true];
        yield 'property' => [MemberKind::Property, true];
    }

    public function testKeyForCollapsesACaseVariedMethodOverride(): void
    {
        self::assertSame(
            MemberKind::Method->keyFor('overriddenMethod'),
            MemberKind::Method->keyFor('OVERRIDDENMETHOD'),
        );
    }

    /**
     * @return iterable<string, array{MemberKind, bool}>
     */
    public static function isMethodCases(): iterable
    {
        yield 'constant' => [MemberKind::Constant, false];
        yield 'enum case' => [MemberKind::EnumCase, false];
        yield 'method' => [MemberKind::Method, true];
        yield 'property' => [MemberKind::Property, false];
    }

    /**
     * @return iterable<string, array{MemberKind, MemberInfoInterface, bool}>
     */
    public static function traitBindingCases(): iterable
    {
        yield 'a concrete method' => [MemberKind::Method, self::createMethodInfo(), true];
        yield 'an abstract method' => [MemberKind::Method, self::createMethodInfo(isAbstract: true), false];
        yield 'a property' => [MemberKind::Property, self::createPropertyInfo(), true];
        yield 'a constant' => [MemberKind::Constant, self::createConstantInfo(), true];
        yield 'an enum case' => [MemberKind::EnumCase, self::createEnumCaseInfo(), true];
    }

    /**
     * PHP binds a trait's abstract methods only after the using class inherits
     * from its parent (php-src `zend_do_link_class`).
     */
    #[DataProvider('traitBindingCases')]
    public function testOnlyAnAbstractMethodIsBoundAfterInheritance(
        MemberKind $kind,
        MemberInfoInterface $member,
        bool $expected,
    ): void {
        self::assertSame($expected, $kind->bindsBeforeInheritance($member), "when a trait's {$kind->name} is bound");
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
        ClasslikeConstantInfo $constant,
        EnumCaseInfo $enumCase,
        MethodInfo $method,
        PropertyInfo $property,
    ): ClassInfo {
        return new ClassInfo(
            name: ClasslikeName::fromFullyQualified(self::class),
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

    private static function createConstantInfo(): ClasslikeConstantInfo
    {
        return new ClasslikeConstantInfo(
            name: new ClasslikeConstantName(ClasslikeName::fromFullyQualified(self::class), 'MAX'),
            visibility: Visibility::Public,
            isFinal: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
        );
    }

    private static function createEnumCaseInfo(): EnumCaseInfo
    {
        return new EnumCaseInfo(
            name: new EnumCaseName(ClasslikeName::fromFullyQualified(self::class), 'Draft'),
            backingValue: null,
            docblock: null,
            file: null,
            line: null,
        );
    }

    private static function createMethodInfo(bool $isAbstract = false): MethodInfo
    {
        return new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified(self::class), 'run'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: $isAbstract,
            isFinal: false,
            parameters: [],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
        );
    }

    private static function createPropertyInfo(): PropertyInfo
    {
        return new PropertyInfo(
            name: new PropertyName(ClasslikeName::fromFullyQualified(self::class), 'value'),
            visibility: Visibility::Public,
            isStatic: false,
            isReadonly: false,
            isPromoted: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
        );
    }
}
