<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\LateStaticType;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\UnionType;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\ParsedDeclarationSource;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedDeclarationSource::class)]
final class ParsedDeclarationSourceTest extends TestCase
{
    use LoadsFixturesTrait;

    /** Declares all three kinds, so a lookup reading the wrong list is visible. */
    private const string FIXTURE = 'AutoloadFiles/helpers.php';

    private const array PARENT_CLASS = ['src/Inheritance/ParentClass.php', 'Fixtures\Inheritance\ParentClass'];

    private const array USER = ['src/Domain/User.php', 'Fixtures\Domain\User'];

    /** @var list<DeclaredSymbol> */
    private array $declared;
    private string $path;

    protected function setUp(): void
    {
        $this->path = $this->fixturePath(self::FIXTURE);
        $this->declared = $this->declaredIn(self::FIXTURE);
    }

    /**
     * @return iterable<string, array{callable(string): string}>
     */
    public static function spellings(): iterable
    {
        yield 'a path, as the disk reader names a document' => [static fn(string $path): string => $path];
        yield 'a URI, as a client names a document' => [FileUri::fromPath(...)];
    }

    /**
     * @param callable(string): string $spell
     */
    #[DataProvider('spellings')]
    public function testItReportsWhatTheDocumentDeclares(callable $spell): void
    {
        [$fixture] = self::USER;
        $declared = $this->declaredIn($fixture, $spell);

        self::assertSame(
            [['Fixtures\Domain\User', NameKind::ClassLike]],
            array_map(
                static fn(DeclaredSymbol $symbol): array => [$symbol->name->fullyQualifiedName(), $symbol->kind],
                $declared,
            ),
            'the fixture declares one class and nothing else',
        );
        $info = $declared[0]->info;
        self::assertInstanceOf(ClassInfo::class, $info, 'a class-like is described by a ClassInfo');
        self::assertSame(
            $this->fixturePath($fixture),
            $info->file,
            'the declaring file is a path however the document was named',
        );
    }

    public function testBuildsClassInfoForAClassLikeDeclaration(): void
    {
        $info = $this->build('Fixtures\Helpers\HelperRegistry', NameKind::ClassLike);

        self::assertInstanceOf(ClassInfo::class, $info, 'a class-like must build ClassInfo, not another kind\'s type');
        self::assertSame(
            'Fixtures\Helpers\HelperRegistry',
            $info->name->qualifiedName->fullyQualifiedName(),
            'the located declaration must be built',
        );
    }

    public function testBuildsFunctionInfoForAFunctionDeclaration(): void
    {
        $info = $this->build('Fixtures\Helpers\helperFormat', NameKind::Function_);

        self::assertInstanceOf(FunctionInfo::class, $info, 'a function must build FunctionInfo');
        self::assertCount(1, $info->parameters, 'the parsed signature must be carried, not just the name');
        self::assertSame($this->path, $info->file, 'the declaring file must be recorded from the path given');
    }

    public function testReturnsNullWhenTheFileDeclaresNoSuchName(): void
    {
        self::assertNull(
            $this->build('Fixtures\Helpers\notDeclaredHere', NameKind::Function_),
            'a name the declarations do not carry is absent',
        );
    }

    /**
     * A merged list, or the wrong one, would resolve these.
     *
     * @return iterable<string, array{string, NameKind}>
     */
    public static function crossKindQueries(): iterable
    {
        yield 'a class asked for as a function' => ['Fixtures\Helpers\HelperRegistry', NameKind::Function_];
        yield 'a function asked for as a class' => ['Fixtures\Helpers\helperFormat', NameKind::ClassLike];
        yield 'a constant asked for as a class' => ['Fixtures\Helpers\HELPER_LIMIT', NameKind::ClassLike];
    }

    #[DataProvider('crossKindQueries')]
    public function testAKindOnlyAnswersItsOwnDeclarations(string $fqn, NameKind $kind): void
    {
        self::assertNull(
            $this->build($fqn, $kind),
            'the kind must select the declaration list, so one namespace cannot answer for another',
        );
    }

    /**
     * @return iterable<string, array{string, NameKind}>
     */
    public static function caseInsensitiveQueries(): iterable
    {
        yield 'class-like' => ['FIXTURES\HELPERS\HELPERREGISTRY', NameKind::ClassLike];
        yield 'function' => ['FIXTURES\HELPERS\HELPERFORMAT', NameKind::Function_];
    }

    #[DataProvider('caseInsensitiveQueries')]
    public function testMatchingFollowsTheKindsCaseRule(string $fqn, NameKind $kind): void
    {
        self::assertNotNull(
            $this->build($fqn, $kind),
            'PHP matches class and function names case-insensitively, which NameKind::normalize owns',
        );
    }

    public function testConstantsAreBuilt(): void
    {
        $info = $this->build('Fixtures\Helpers\HELPER_LIMIT', NameKind::Constant);
        self::assertInstanceOf(
            \Firehed\PhpLsp\Domain\ConstantInfo::class,
            $info,
            'a declared free-standing constant must resolve to ConstantInfo',
        );
    }

    public function testReportsEveryBuildableDeclarationWithItsKind(): void
    {
        $reported = [];
        foreach ($this->declared as $symbol) {
            $reported[] = $symbol->kind->name . '|' . $symbol->name->fullyQualifiedName();
        }

        self::assertContains(
            'ClassLike|Fixtures\Helpers\HelperRegistry',
            $reported,
            'a class-like the file declares must be reported for registration',
        );
        self::assertContains(
            'Function_|Fixtures\Helpers\helperFormat',
            $reported,
            'a function the file declares must be reported, under its own kind',
        );
        self::assertContains(
            'Constant|Fixtures\Helpers\HELPER_LIMIT',
            $reported,
            'a constant the file declares must be reported, under its own kind',
        );
    }

    public function testKeepsTheFirstOfDuplicateDeclarations(): void
    {
        $names = [];
        foreach ($this->declaredIn('MultiClass/DuplicateDeclarations.php') as $symbol) {
            $names[] = $symbol->kind->name . '|' . $symbol->name->fullyQualifiedName();
        }

        self::assertSame(
            array_unique($names),
            $names,
            'PHP defines the first declaration of a name, so a second must not register over it',
        );
    }

    public function testAMemberWhoseNameIsNotWrittenIsNotDeclared(): void
    {
        $info = $this->buildClassInfoFromFixture(
            'Recovery/DanglingAttribute.php',
            'Fixtures\Recovery\DanglingAttribute',
        );

        self::assertSame(
            ['kept'],
            array_keys($info->methods),
            'what recovery supplies for an attribute to apply to is not a method the class declares',
        );
    }

    public function testAPropertyWhoseNameIsNotWrittenIsNotDeclared(): void
    {
        $info = $this->buildClassInfoFromFixture(
            'Recovery/UnfinishedMembers.php',
            'Fixtures\Recovery\UnfinishedProperty',
        );

        self::assertSame(['kept'], array_keys($info->properties), 'a type being typed declares no property yet');
    }

    public function testAnEnumCaseWhoseNameIsNotWrittenIsNotDeclared(): void
    {
        $info = $this->buildClassInfoFromFixture('Recovery/UnfinishedMembers.php', 'Fixtures\Recovery\UnfinishedCase');

        self::assertSame(['Kept'], array_keys($info->enumCases), 'a case being typed declares no case yet');
    }

    public function testExtractsInsteadofExclusions(): void
    {
        $info = $this->buildClassInfoFromFixture(
            'src/Hierarchy/TraitAdaptationUser.php',
            'Fixtures\Hierarchy\TraitAdaptationUser',
        );

        self::assertSame(
            [NameKind::ClassLike->normalize(
                QualifiedName::fromFullyQualified('Fixtures\Hierarchy\ConflictingTraitB'),
            ) => ['conflictMethod']],
            $info->traitExclusions,
            'an insteadof adaptation must record the losing trait under the class-like identity key, '
                . 'so a case-different `use` still matches',
        );
    }

    public function testExtractsAliasAdaptations(): void
    {
        $info = $this->buildClassInfoFromFixture(
            'src/Hierarchy/TraitAdaptationUser.php',
            'Fixtures\Hierarchy\TraitAdaptationUser',
        );

        $aliasesByNewName = [];
        foreach ($info->traitAliases as $alias) {
            $aliasesByNewName[$alias->newName ?? '(visibility-only)'] = $alias;
        }

        self::assertArrayHasKey(
            'conflictMethodFromB',
            $aliasesByNewName,
            'a rename `as` adaptation must record its new name',
        );
        self::assertSame(
            'conflictMethod',
            $aliasesByNewName['conflictMethodFromB']->method,
            'the alias must carry the original method name',
        );
        self::assertNull(
            $aliasesByNewName['conflictMethodFromB']->newVisibility,
            'a rename-only alias does not change visibility',
        );

        self::assertArrayHasKey(
            'protectedOnlyInB',
            $aliasesByNewName,
            'an alias renaming and re-scoping must be recorded',
        );
        self::assertSame(
            Visibility::Protected,
            $aliasesByNewName['protectedOnlyInB']->newVisibility,
            'the new visibility flag must be mapped through visibilityFromFlags',
        );
    }

    public function testPromotedPropertyWithMalformedVarNodeIsSkipped(): void
    {
        // php-parser recovers `private $)` by attaching an Error node as the
        // parameter's var. The extractor must skip it rather than crash on
        // reading a non-string name.
        $info = $this->buildClassInfoFromFixture(
            'src/IncompleteCode/BrokenParameters.php',
            'Fixtures\IncompleteCode\BrokenPromotedProperty',
        );

        self::assertSame(
            [],
            $info->properties,
            'a promoted-property Param whose var is not a Variable must not become a PropertyInfo',
        );
    }

    /**
     * @return array<string, array{string, Visibility, bool}>
     */
    public static function promotedParameters(): array
    {
        return [
            'public' => ['publicId', Visibility::Public, false],
            'protected' => ['protectedId', Visibility::Protected, false],
            'private' => ['privateId', Visibility::Private, false],
            'readonly alone' => ['readonlyId', Visibility::Public, true],
            'private(set) alone' => ['privateSetCount', Visibility::Public, false],
            'protected(set) alone' => ['protectedSetCount', Visibility::Public, false],
            'hooks alone' => ['hookedId', Visibility::Public, false],
        ];
    }

    #[DataProvider('promotedParameters')]
    public function testPromotedParameterBecomesProperty(
        string $name,
        Visibility $visibility,
        bool $isReadonly,
    ): void {
        $info = $this->buildClassInfoFromFixture(
            'src/Utility/PromotedModifiers.php',
            'Fixtures\Utility\PromotedModifiers',
        );

        self::assertArrayHasKey($name, $info->properties, 'any modifier on a constructor parameter promotes it');
        $property = $info->properties[$name];
        self::assertTrue($property->isPromoted, 'a property declared by a constructor parameter is promoted');
        self::assertSame($visibility, $property->visibility, 'read visibility is public unless a keyword narrows it');
        self::assertSame(
            $isReadonly,
            $property->isReadonly,
            '`readonly` on the parameter makes it read-only; set-visibility and hooks do not',
        );
    }

    public function testUnmodifiedConstructorParameterIsNotAProperty(): void
    {
        $info = $this->buildClassInfoFromFixture(
            'src/Utility/PromotedModifiers.php',
            'Fixtures\Utility\PromotedModifiers',
        );

        self::assertArrayNotHasKey(
            'notPromoted',
            $info->properties,
            'a constructor parameter without modifiers is not promoted',
        );
    }

    public function testFunctionParameterWithMalformedVarNodeIsSkipped(): void
    {
        // Same shape as the promoted-property fixture, in a free-standing
        // function's parameter list.
        $name = QualifiedName::fromFullyQualified('Fixtures\IncompleteCode\brokenFreeStandingParam');
        $info = array_find(
            $this->declaredIn('src/IncompleteCode/BrokenParameters.php'),
            static fn(DeclaredSymbol $symbol): bool => $symbol->declares($name, NameKind::Function_),
        )?->info;

        self::assertInstanceOf(FunctionInfo::class, $info);
        self::assertSame(
            [],
            $info->parameters,
            'a function Param whose var is not a Variable must not become a ParameterInfo',
        );
    }

    public function testAliasWithoutSourceTraitLeavesTraitNull(): void
    {
        $info = $this->buildClassInfoFromFixture(
            'src/Hierarchy/TraitNamelessAliasUser.php',
            'Fixtures\Hierarchy\TraitNamelessAliasUser',
        );

        self::assertCount(1, $info->traitAliases, 'the fixture declares exactly one alias');
        self::assertNull(
            $info->traitAliases[0]->trait,
            'an `as` clause that names no source trait leaves the alias trait null',
        );
        self::assertSame('onlyInA', $info->traitAliases[0]->method);
        self::assertSame('renamedOnlyInA', $info->traitAliases[0]->newName);
    }

    /**
     * @return iterable<string, array{string, string, ClassKind, bool, bool, bool, bool}>
     */
    public static function classLikeShapes(): iterable
    {
        yield 'final attribute class' => [
            'src/Attributes/NoConstructorAttribute.php',
            'Fixtures\Attributes\NoConstructorAttribute',
            ClassKind::Class_,
            false,
            true,
            false,
            true,
        ];
        yield 'final readonly class' => [
            'src/Domain/Money.php',
            'Fixtures\Domain\Money',
            ClassKind::Class_,
            false,
            true,
            true,
            false,
        ];
        yield 'abstract class' => [
            'src/Hierarchy/AbstractImplementor.php',
            'Fixtures\Hierarchy\AbstractImplementor',
            ClassKind::Class_,
            true,
            false,
            false,
            false,
        ];
        yield 'interface' => [
            'src/Domain/Entity.php',
            'Fixtures\Domain\Entity',
            ClassKind::Interface_,
            false,
            false,
            false,
            false,
        ];
        yield 'trait' => [
            'src/Hierarchy/InnerTrait.php',
            'Fixtures\Hierarchy\InnerTrait',
            ClassKind::Trait_,
            false,
            false,
            false,
            false,
        ];
        yield 'enum' => [
            'src/Enum/Status.php',
            'Fixtures\Enum\Status',
            ClassKind::Enum_,
            false,
            false,
            false,
            false,
        ];
    }

    #[DataProvider('classLikeShapes')]
    public function testClassLikeCarriesItsKindAndModifiers(
        string $fixture,
        string $fqn,
        ClassKind $kind,
        bool $isAbstract,
        bool $isFinal,
        bool $isReadonly,
        bool $isAttribute,
    ): void {
        $info = $this->buildClassInfoFromFixture($fixture, $fqn);

        self::assertSame($kind, $info->kind, 'the declaration keyword decides the kind');
        self::assertSame($isAbstract, $info->isAbstract, 'only an abstract class is abstract');
        self::assertSame($isFinal, $info->isFinal, 'only a final class is final');
        self::assertSame($isReadonly, $info->isReadonly, 'only a readonly class is readonly');
        self::assertSame($isAttribute, $info->isAttribute, 'a class declared with #[Attribute] is an attribute');
    }

    /**
     * @return iterable<string, array{string, string, ?string, list<string>, list<string>}>
     */
    public static function supertypes(): iterable
    {
        yield 'class extending a class and implementing an interface' => [
            'src/Exception/AppException.php',
            'Fixtures\Exception\AppException',
            'RuntimeException',
            ['Fixtures\Exception\ExceptionInterface'],
            [],
        ];
        yield 'class implementing interfaces and using a trait' => [
            'src/Domain/User.php',
            'Fixtures\Domain\User',
            null,
            ['Fixtures\Domain\Entity', 'Fixtures\Domain\Person'],
            ['Fixtures\Traits\HasTimestamps'],
        ];
        yield 'interface extending several' => [
            'src/Hierarchy/LeafInterface.php',
            'Fixtures\Hierarchy\LeafInterface',
            null,
            ['Fixtures\Hierarchy\MiddleInterface', 'Countable'],
            [],
        ];
        yield 'trait using a trait' => [
            'src/Hierarchy/OuterTrait.php',
            'Fixtures\Hierarchy\OuterTrait',
            null,
            [],
            ['Fixtures\Hierarchy\InnerTrait'],
        ];
        yield 'pure enum' => [
            'src/Enum/Status.php',
            'Fixtures\Enum\Status',
            null,
            ['UnitEnum'],
            [],
        ];
        yield 'backed enum implementing an interface' => [
            'src/Enum/SerializableStatus.php',
            'Fixtures\Enum\SerializableStatus',
            null,
            ['JsonSerializable', 'UnitEnum', 'BackedEnum'],
            [],
        ];
    }

    /**
     * @param list<string> $interfaces
     * @param list<string> $traits
     */
    #[DataProvider('supertypes')]
    public function testClassLikeCarriesItsSupertypes(
        string $fixture,
        string $fqn,
        ?string $parent,
        array $interfaces,
        array $traits,
    ): void {
        $info = $this->buildClassInfoFromFixture($fixture, $fqn);

        self::assertEquals(
            $parent === null ? null : ClasslikeName::fromFullyQualified($parent),
            $info->parent,
            'only a class has a parent, from its extends clause',
        );
        self::assertEquals(
            array_map(ClasslikeName::fromFullyQualified(...), $interfaces),
            $info->interfaces,
            'a class or enum implements, an interface extends, and an enum also has its implicit interfaces',
        );
        self::assertEquals(
            array_map(ClasslikeName::fromFullyQualified(...), $traits),
            $info->traits,
            'every trait a class-like uses is recorded',
        );
    }

    public function testClassLikeCarriesItsDocblockAndFile(): void
    {
        $info = $this->buildClassInfoFromFixture('src/Domain/Entity.php', 'Fixtures\Domain\Entity');

        self::assertSame(
            "/**\n * Base interface for all domain entities.\n */",
            $info->docblock,
            'the declaration\'s doc comment is kept as written',
        );
        self::assertSame(
            $this->fixturePath('src/Domain/Entity.php'),
            $info->file,
            'the declaring file is recorded from the path given',
        );
    }

    /**
     * @return iterable<string, array{string, string, string, Visibility, bool, bool}>
     */
    public static function methodModifiers(): iterable
    {
        yield 'public static' => [...self::PARENT_CLASS, 'staticMethod', Visibility::Public, true, false];
        yield 'private static' => [...self::PARENT_CLASS, 'privateStaticMethod', Visibility::Private, true, false];
        yield 'protected' => [...self::PARENT_CLASS, 'protectedMethod', Visibility::Protected, false, false];
        yield 'abstract' => [
            'src/Utility/ClassModifiers.php',
            'Fixtures\Utility\AbstractBase',
            'doSomething',
            Visibility::Public,
            false,
            true,
        ];
    }

    #[DataProvider('methodModifiers')]
    public function testMethodCarriesItsModifiers(
        string $fixture,
        string $fqn,
        string $method,
        Visibility $visibility,
        bool $isStatic,
        bool $isAbstract,
    ): void {
        $info = $this->buildClassInfoFromFixture($fixture, $fqn)->methods[$method];

        self::assertSame($visibility, $info->visibility, 'the visibility keyword decides visibility');
        self::assertSame($isStatic, $info->isStatic, 'only a static method is static');
        self::assertSame($isAbstract, $info->isAbstract, 'only an abstract method is abstract');
    }

    /**
     * @return iterable<string, array{string, string, string, TypeInterface}>
     */
    public static function methodReturnTypes(): iterable
    {
        yield 'self stays late-bound' => [
            'src/Domain/Team.php',
            'Fixtures\Domain\Team',
            'withLeader',
            new LateStaticType(LateBindingKeyword::Self),
        ];
        yield 'static stays late-bound' => [
            'src/Traits/SingletonTrait.php',
            'Fixtures\Traits\SingletonTrait',
            'instance',
            new LateStaticType(LateBindingKeyword::Static),
        ];
        yield 'nullable class' => [...self::USER, 'getTeam', self::nullable('Fixtures\Domain\Team')];
    }

    #[DataProvider('methodReturnTypes')]
    public function testMethodCarriesItsReturnType(
        string $fixture,
        string $fqn,
        string $method,
        TypeInterface $returnType,
    ): void {
        self::assertEquals(
            $returnType,
            $this->buildClassInfoFromFixture($fixture, $fqn)->methods[$method]->returnType,
            'self and static are kept late-bound so a subclass can resolve them; ?T is T|null',
        );
    }

    /**
     * @return iterable<string, array{string, string, string, int, ParameterInfo}>
     */
    public static function methodParameters(): iterable
    {
        $constructor = [...self::USER, '__construct'];
        yield 'scalar default' => [
            ...$constructor,
            3,
            new ParameterInfo('age', new PrimitiveType('int'), '0', 3, false, false),
        ];
        yield 'class-constant default, printed with its resolved name' => [
            ...$constructor,
            4,
            new ParameterInfo(
                'status',
                new ClasslikeType(ClasslikeName::fromFullyQualified('Fixtures\Enum\Status')),
                '\Fixtures\Enum\Status::Active',
                4,
                false,
                false,
            ),
        ];
        yield 'nullable self, kept late-bound, with a null default' => [
            ...$constructor,
            5,
            new ParameterInfo('manager', self::nullableSelf(), 'null', 5, false, false),
        ];
        yield 'untyped' => [
            'vendor/psr/http-message/src/ServerRequestInterface.php',
            'Psr\Http\Message\ServerRequestInterface',
            'getAttribute',
            1,
            new ParameterInfo('default', null, 'null', 1, false, false),
        ];
    }

    #[DataProvider('methodParameters')]
    public function testMethodParameterCarriesItsTypeAndDefault(
        string $fixture,
        string $fqn,
        string $method,
        int $position,
        ParameterInfo $parameter,
    ): void {
        self::assertEquals(
            $parameter,
            $this->buildClassInfoFromFixture($fixture, $fqn)->methods[$method]->parameters[$position],
            'a parameter carries its declared type (if any) and its default as PHP source',
        );
    }

    public function testNestedAnonymousClassMembersStayOutOfTheEnclosingClass(): void
    {
        $info = $this->buildClassInfoFromFixture(
            'src/TypeInference/AnonymousClass.php',
            'Fixtures\TypeInference\AnonymousClass',
        );

        self::assertSame(
            ['getAnonymous'],
            array_keys($info->methods),
            'a method of an anonymous class inside a method body is not the enclosing class\'s',
        );
    }

    /**
     * @return iterable<string, array{string, string, string, Visibility, bool, bool, TypeInterface}>
     */
    public static function properties(): iterable
    {
        $int = new PrimitiveType('int');
        $string = new PrimitiveType('string');
        yield 'private static' => [...self::USER, 'instanceCount', Visibility::Private, true, false, $int];
        yield 'private readonly' => [...self::USER, 'id', Visibility::Private, false, true, $string];
        yield 'public nullable self, kept late-bound' => [
            ...self::USER,
            'manager',
            Visibility::Public,
            false,
            false,
            self::nullableSelf(),
        ];
        yield 'public static' => [...self::PARENT_CLASS, 'staticProperty', Visibility::Public, true, false, $string];
        yield 'protected' => [...self::PARENT_CLASS, 'protectedProperty', Visibility::Protected, false, false, $int];
    }

    #[DataProvider('properties')]
    public function testPropertyCarriesItsModifiersAndType(
        string $fixture,
        string $fqn,
        string $property,
        Visibility $visibility,
        bool $isStatic,
        bool $isReadonly,
        TypeInterface $type,
    ): void {
        $info = $this->buildClassInfoFromFixture($fixture, $fqn)->properties[$property];

        self::assertSame($visibility, $info->visibility, 'the visibility keyword decides visibility');
        self::assertSame($isStatic, $info->isStatic, 'only a static property is static');
        self::assertSame($isReadonly, $info->isReadonly, 'only a readonly property is readonly');
        self::assertEquals($type, $info->type, 'the declared type is carried');
    }

    /**
     * @return iterable<string, array{string, string, string, Visibility}>
     */
    public static function classConstants(): iterable
    {
        yield 'public' => [...self::PARENT_CLASS, 'PARENT_CONST', Visibility::Public];
        yield 'protected' => [...self::PARENT_CLASS, 'PROTECTED_CONST', Visibility::Protected];
    }

    #[DataProvider('classConstants')]
    public function testClassConstantCarriesItsVisibility(
        string $fixture,
        string $fqn,
        string $constant,
        Visibility $visibility,
    ): void {
        $constants = $this->buildClassInfoFromFixture($fixture, $fqn)->constants;

        self::assertArrayHasKey($constant, $constants, 'a class-like\'s own constant is recorded');
        self::assertSame($visibility, $constants[$constant]->visibility, 'the visibility keyword decides visibility');
    }

    /**
     * @return iterable<string, array{string, string, string, int|string|null}>
     */
    public static function enumCases(): iterable
    {
        yield 'int-backed' => ['src/Enum/Priority.php', 'Fixtures\Enum\Priority', 'Low', 1];
        yield 'string-backed' => ['src/Enum/Color.php', 'Fixtures\Enum\Color', 'Red', 'red'];
        yield 'pure' => ['src/Enum/Status.php', 'Fixtures\Enum\Status', 'Active', null];
    }

    #[DataProvider('enumCases')]
    public function testEnumCaseCarriesItsBackingValue(
        string $fixture,
        string $fqn,
        string $case,
        int|string|null $backingValue,
    ): void {
        $cases = $this->buildClassInfoFromFixture($fixture, $fqn)->enumCases;

        self::assertArrayHasKey($case, $cases, 'each declared case is recorded');
        self::assertSame($backingValue, $cases[$case]->backingValue, 'the backing value is kept with its type');
    }

    public function testBackedEnumAddsImplicitMembersTypedByItsBackingType(): void
    {
        $info = $this->buildClassInfoFromFixture('src/Enum/Priority.php', 'Fixtures\Enum\Priority');

        self::assertArrayHasKey('fromScore', $info->methods, 'a declared method is kept alongside the implicit ones');
        self::assertEquals(
            new PrimitiveType('int'),
            $info->methods['from']->parameters[0]->type,
            'from() takes the backing type',
        );
        self::assertEquals(new PrimitiveType('int'), $info->properties['value']->type, '$value has the backing type');
    }

    public function testPureEnumHasNoBackedMembers(): void
    {
        $info = $this->buildClassInfoFromFixture('src/Enum/Status.php', 'Fixtures\Enum\Status');

        self::assertArrayHasKey('cases', $info->methods, 'every enum has cases()');
        self::assertArrayNotHasKey('from', $info->methods, 'only a backed enum has from()');
        self::assertArrayNotHasKey('value', $info->properties, 'only a backed enum has $value');
    }

    /**
     * `?self` as a declaration writes it: the class it names is bound where the
     * member is used.
     */
    private static function nullableSelf(): UnionType
    {
        return new UnionType([new LateStaticType(LateBindingKeyword::Self), new PrimitiveType('null')]);
    }

    private static function nullable(string $fqn): UnionType
    {
        return new UnionType([
            new ClasslikeType(ClasslikeName::fromFullyQualified($fqn)),
            new PrimitiveType('null'),
        ]);
    }

    private function build(string $fqn, NameKind $kind): ?SymbolInfoInterface
    {
        $name = QualifiedName::fromFullyQualified($fqn);
        return array_find(
            $this->declared,
            static fn(DeclaredSymbol $symbol): bool => $symbol->declares($name, $kind),
        )?->info;
    }

    private function buildClassInfoFromFixture(string $fixturePath, string $fqn): ClassInfo
    {
        $name = QualifiedName::fromFullyQualified($fqn);
        $info = array_find(
            $this->declaredIn($fixturePath),
            static fn(DeclaredSymbol $symbol): bool => $symbol->declares($name, NameKind::ClassLike),
        )?->info;
        self::assertInstanceOf(ClassInfo::class, $info, "the fixture must declare $fqn as a class-like");
        return $info;
    }

    /**
     * What the one production route reports for a fixture, so these cases
     * describe the {@see \Firehed\PhpLsp\Knowledge\DeclarationSourceInterface} contract callers rely on.
     *
     * @param ?callable(string): string $spell How the document's path is written as its URI
     * @return list<DeclaredSymbol>
     */
    private function declaredIn(string $fixture, ?callable $spell = null): array
    {
        $path = $this->fixturePath($fixture);
        return ProductionSyntaxSource::create()->declarations->declarationsIn(
            new TextDocument($spell === null ? $path : $spell($path), 'php', 1, $this->loadFixture($fixture)),
        );
    }
}
