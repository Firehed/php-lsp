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
     * @return iterable<string, array{MemberKind, bool}>
     */
    public static function traitAdaptationCases(): iterable
    {
        yield 'constant' => [MemberKind::Constant, false];
        yield 'enum case' => [MemberKind::EnumCase, false];
        yield 'method' => [MemberKind::Method, true];
        yield 'property' => [MemberKind::Property, false];
    }

    /**
     * `as` and `insteadof` adapt only methods.
     */
    #[DataProvider('traitAdaptationCases')]
    public function testOnlyMethodsTakeTraitAdaptations(MemberKind $kind, bool $expected): void
    {
        self::assertSame($expected, $kind->takesTraitAdaptations(), $kind->name);
    }

    /**
     * @return iterable<string, array{MemberKind, bool}>
     */
    public static function privateInheritanceCases(): iterable
    {
        yield 'constant' => [MemberKind::Constant, false];
        yield 'enum case' => [MemberKind::EnumCase, false];
        yield 'method' => [MemberKind::Method, true];
        yield 'property' => [MemberKind::Property, true];
    }

    /**
     * A subclass's table keeps a parent's private methods and properties, but
     * not its private constants (`do_inherit_class_constant`).
     */
    #[DataProvider('privateInheritanceCases')]
    public function testOnlyObjectMembersAreInheritedWhenPrivate(MemberKind $kind, bool $expected): void
    {
        self::assertSame($expected, $kind->inheritsPrivate(), $kind->name);
    }

    /**
     * @return iterable<string, array{MemberKind, MemberInfoInterface, MemberInfoInterface, bool}>
     */
    public static function prototypeCases(): iterable
    {
        $public = self::createMethodInfo();
        $abstract = self::createMethodInfo(isAbstract: true);
        $private = self::createMethodInfo(visibility: Visibility::Private);
        $privateAbstract = self::createMethodInfo(visibility: Visibility::Private, isAbstract: true);
        $constructor = self::createMethodInfo('__CONSTRUCT');
        yield 'a method' => [MemberKind::Method, $public, $public, true];
        yield 'a private method' => [MemberKind::Method, $private, $private, false];
        yield 'a private abstract method' => [MemberKind::Method, $privateAbstract, $privateAbstract, true];
        yield 'a constructor' => [MemberKind::Method, $constructor, $constructor, false];
        yield 'a constructor from an abstract one' => [MemberKind::Method, $constructor, $abstract, true];
        yield 'a property' => [MemberKind::Property, self::createPropertyInfo(), self::createPropertyInfo(), true];
        $privateProperty = self::createPropertyInfo(Visibility::Private);
        yield 'a private property' => [MemberKind::Property, $privateProperty, $privateProperty, false];
        yield 'a constant' => [MemberKind::Constant, self::createConstantInfo(), self::createConstantInfo(), false];
    }

    /**
     * Whether a member overriding $inherited takes $root, the inherited
     * member's prototype or itself, as its prototype
     * (`do_inheritance_check_on_method` in php-src).
     */
    #[DataProvider('prototypeCases')]
    public function testAnOverrideTakesThePrototypePhpGivesIt(
        MemberKind $kind,
        MemberInfoInterface $inherited,
        MemberInfoInterface $root,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            $kind->inheritsPrototype($inherited, $root),
            'whether the override has a prototype',
        );
    }

    /**
     * @return iterable<string, array{MemberKind, MemberInfoInterface, bool}>
     */
    public static function traitReplacementCases(): iterable
    {
        yield 'a static property' => [MemberKind::Property, self::createPropertyInfo(isStatic: true), true];
        yield 'an instance property' => [MemberKind::Property, self::createPropertyInfo(), false];
        yield 'a method' => [MemberKind::Method, self::createMethodInfo(), false];
        yield 'a constant' => [MemberKind::Constant, self::createConstantInfo(), false];
    }

    /**
     * A trait's static property is declared again on the class using it,
     * replacing a compatible one (`zend_do_traits_property_binding`); any
     * other trait member yields to one already there.
     */
    #[DataProvider('traitReplacementCases')]
    public function testOnlyAStaticTraitPropertyReplacesAnExistingMember(
        MemberKind $kind,
        MemberInfoInterface $member,
        bool $expected,
    ): void {
        self::assertSame($expected, $kind->traitReplacesExisting($member), 'whether the trait member replaces');
    }

    /**
     * @return iterable<string, array{MemberKind, MemberInfoInterface, MemberInfoInterface, bool}>
     */
    public static function ownPrivateCases(): iterable
    {
        $static = self::createPropertyInfo(Visibility::Private, isStatic: true);
        $instance = self::createPropertyInfo(Visibility::Private);
        yield 'a method' => [MemberKind::Method, self::createMethodInfo(), self::createMethodInfo(), true];
        yield 'an instance property' => [MemberKind::Property, $instance, $instance, true];
        yield 'a static property beside a static one' => [MemberKind::Property, $static, $static, true];
        yield 'a static property beside an instance one' => [MemberKind::Property, $static, $instance, false];
        yield 'a constant' => [MemberKind::Constant, self::createConstantInfo(), self::createConstantInfo(), false];
    }

    /**
     * Code in a class reaching $found by name on an instance of a subclass
     * uses its own private $own instead, except a private static property
     * beside an instance one (`zend_std_get_method`, `zend_get_property_offset`).
     */
    #[DataProvider('ownPrivateCases')]
    public function testCodeReachesItsOwnPrivateMemberAsPhpDoes(
        MemberKind $kind,
        MemberInfoInterface $own,
        MemberInfoInterface $found,
        bool $expected,
    ): void {
        self::assertSame($expected, $kind->prefersOwnPrivate($own, $found), 'whether its own private member is used');
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

    private static function createConstantInfo(Visibility $visibility = Visibility::Public): ClasslikeConstantInfo
    {
        return new ClasslikeConstantInfo(
            name: new ClasslikeConstantName(ClasslikeName::fromFullyQualified(self::class), 'MAX'),
            visibility: $visibility,
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

    private static function createMethodInfo(
        string $name = 'run',
        Visibility $visibility = Visibility::Public,
        bool $isAbstract = false,
    ): MethodInfo {
        return new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified(self::class), $name),
            visibility: $visibility,
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

    private static function createPropertyInfo(
        Visibility $visibility = Visibility::Public,
        bool $isStatic = false,
    ): PropertyInfo {
        return new PropertyInfo(
            name: new PropertyName(ClasslikeName::fromFullyQualified(self::class), 'value'),
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
}
