<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeConstantInfo;
use Firehed\PhpLsp\Domain\ClasslikeConstantName;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\MemberInfoInterface;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\ComposerAutoloadMapReader;
use Firehed\PhpLsp\Knowledge\ComposerMapBackend;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\MemberAccessKind;
use Firehed\PhpLsp\Resolution\MemberTarget;
use Firehed\PhpLsp\Resolution\Position\PositionLocator;
use Firehed\PhpLsp\Resolution\Position\SymbolAtPosition;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\TypeSource\NativeTypeSource;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expression resolution over a real parse, with the knowledge interfaces
 * stubbed: other files are only what a test says they are.
 */
#[CoversClass(ExpressionResolver::class)]
final class ExpressionResolutionTest extends TestCase
{
    use BuildsSymbolInfoTrait;
    use LoadsFixturesTrait;

    private const string BINDINGS = 'src/Definition/VariableBindings.php';

    private const string BUILTIN_TYPES = 'src/TypeInference/BuiltinTypes.php';

    private const string DYNAMIC = 'EdgeCases/DynamicAccess.php';

    private const string FOREACH = 'src/Hover/ForeachElement.php';

    private const string TOP_LEVEL = 'TopLevel/top_level_closures.php';

    private const string SYMBOLS = 'src/Position/Symbols.php';

    private const string USER = 'src/Domain/User.php';

    private const string USER_CLASS = 'Fixtures\Domain\User';

    private SymbolAtPosition $resolver;

    protected function setUp(): void
    {
        $this->resolver = self::resolverOver(
            self::createStub(SymbolSourceInterface::class),
            self::createStub(TypeSourceInterface::class),
            self::createStub(MemberResolverInterface::class),
        );
    }

    public function testArrowFunctionResolvesThroughToItsEnclosingFunction(): void
    {
        self::assertEquals(
            new ResolvedVariable('outer', null, self::bindingAt(self::BINDINGS, 72, 8)),
            $this->resolveVariableAt(self::BINDINGS, 'arrow_fallthrough'),
            'an arrow function captures an unbound name from the enclosing function',
        );
    }

    public function testTopLevelArrowFunctionCapturesFromTheFile(): void
    {
        self::assertEquals(
            new ResolvedVariable('outer', self::dateTimeType(), self::bindingAt(self::TOP_LEVEL, 7, 0)),
            $this->resolveVariableAt(self::TOP_LEVEL, 'top_arrow_capture'),
            'an arrow function at file level captures the file-level assignment',
        );
    }

    public function testTopLevelClosureUseTakesTheTypeOfTheFileLevelVariable(): void
    {
        self::assertEquals(
            new ResolvedVariable('closureOuter', self::dateTimeType(), self::bindingAt(self::TOP_LEVEL, 14, 28)),
            $this->resolveVariableAt(self::TOP_LEVEL, 'top_closure_use_capture'),
            'the use clause is the binding, typed by the file-level variable it captures',
        );
    }

    public function testTernaryAssignmentTakesTheTypeOfItsFirstBranch(): void
    {
        self::assertEquals(
            new ResolvedVariable(
                'user',
                new ClasslikeType(ClasslikeName::fromFullyQualified('Fixtures\Domain\User')),
                self::bindingAt(self::USER, 191, 8),
            ),
            $this->resolveReceiverAt(self::USER, 'nullsafe_via_assignment', '$user'),
            'a ternary resolves to its first branch that has a type',
        );
    }

    public function testACaughtVariableTakesTheCaughtType(): void
    {
        self::assertEquals(
            new ClasslikeType(self::className(\Throwable::class)),
            $this->resolveVariableAt(self::BINDINGS, 'catch_usage')?->getType(),
            'a caught variable is typed by its catch clause',
        );
    }

    public function testConstantOnAVariableClassIsUnresolved(): void
    {
        self::assertNull(
            $this->resolveSymbolAt(self::DYNAMIC, 'dynamic_class_const'),
            'the class is only known at runtime',
        );
    }

    public function testStaticPropertyOnAVariableClassIsUnresolved(): void
    {
        self::assertNull(
            $this->resolveSymbolAt(self::DYNAMIC, 'dynamic_class_static_prop'),
            'the class is only known at runtime',
        );
    }

    public function testForeachElementTypeNamedThroughAnImport(): void
    {
        $this->knowUserAnd(self::functionDocumented('Fixtures\Hover\foreachUserProvider', '/** @return User[] */'));

        self::assertEquals(
            new ResolvedVariable('user', self::userType(), self::bindingAt(self::FOREACH, 78, 42)),
            $this->resolveReceiverAt(self::FOREACH, 'foreach_func_call', '$user'),
            'a foreach variable takes its element type from the `@return` docblock, resolved through `use`',
        );
    }

    public function testForeachElementTypeFullyQualified(): void
    {
        $this->knowUserAnd(self::functionDocumented(
            'Fixtures\Hover\foreachUserFqnProvider',
            '/** @return \Fixtures\Domain\User[] */',
        ));

        self::assertEquals(
            new ResolvedVariable('user', self::userType(), self::bindingAt(self::FOREACH, 85, 45)),
            $this->resolveReceiverAt(self::FOREACH, 'foreach_func_call_fqn', '$user'),
            'a leading backslash names the element type exactly',
        );
    }

    public function testForeachElementTypeNamingNoKnownClassHasNoType(): void
    {
        $this->knowUserAnd(self::functionDocumented(
            'Fixtures\Hover\foreachUnknownProvider',
            '/** @return NoSuchClass[] */',
        ));

        self::assertEquals(
            new ResolvedVariable('item', null, self::bindingAt(self::FOREACH, 92, 45)),
            $this->resolveReceiverAt(self::FOREACH, 'foreach_func_call_unknown', '$item'),
            'an element type is only taken when it names a known class',
        );
    }

    public function testForeachOverADocblockWithoutAnElementTypeHasNoType(): void
    {
        $this->knowUserAnd(self::functionDocumented('Fixtures\Hover\foreachUserProvider', '/** @return array */'));

        self::assertEquals(
            new ResolvedVariable('user', null, self::bindingAt(self::FOREACH, 78, 42)),
            $this->resolveReceiverAt(self::FOREACH, 'foreach_func_call', '$user'),
            'a docblock that names no element type gives the element no type',
        );
    }

    public function testCloneKeepsTheTypeOfWhatItCopies(): void
    {
        $this->typeEveryParameterAs(self::dateTimeType());

        self::assertEquals(
            new ResolvedVariable('cloned', self::dateTimeType(), self::bindingAt(self::BUILTIN_TYPES, 60, 8)),
            $this->resolveReceiverAt(self::BUILTIN_TYPES, 'clone_receiver', '$cloned'),
            'a clone has the type of the object it copies',
        );
    }

    public function testNullCoalesceTakesItsLeftSide(): void
    {
        $this->typeEveryParameterAs(self::dateTimeType());

        self::assertEquals(
            new ResolvedVariable('result', self::dateTimeType(), self::bindingAt(self::BUILTIN_TYPES, 73, 8)),
            $this->resolveReceiverAt(self::BUILTIN_TYPES, 'coalesce_receiver', '$result'),
            'a coalesce resolves through its left side when that resolves',
        );
    }

    public function testThisIsTheEnclosingClass(): void
    {
        self::assertEquals(
            new ResolvedVariable('this', new ClasslikeType(self::className('Fixtures\Definition\VariableBindings'))),
            $this->resolveVariableAt(self::BINDINGS, 'this_usage'),
            '$this is a variable typed by the class it appears in, as completion offers it',
        );
    }

    public function testThisIsUntypedWhereTheScopeDoesNotBindIt(): void
    {
        self::assertEquals(
            new ResolvedVariable('this', null),
            $this->resolveVariableAt('TopLevel/this_binding.php', 'static_method_this'),
            'a static method has no $this, though a class encloses it',
        );
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function memberVisibility(): iterable
    {
        $parent = 'src/Inheritance/ParentClass.php';
        $outsider = 'src/Inheritance/Outsider.php';
        $sibling = 'src/Inheritance/SiblingClass.php';
        yield 'private, from its own class' => [$parent, 'private_method_internal', 'privateMethod'];
        yield 'private, from a subclass' => ['src/Inheritance/ChildClass.php', 'private_method', null];
        yield 'protected, from a parent class' => [$parent, 'child_protected_from_parent', 'childProtectedMethod'];
        yield 'private, from an unrelated class' => [$outsider, 'private_from_outside', null];
        yield 'protected, from an unrelated class' => [$outsider, 'protected_from_outside', null];
        yield 'public, from an unrelated class' => [$outsider, 'public_from_outside', 'parentMethod'];
        yield 'protected constant, from an unrelated class' => [$outsider, 'protected_constant_from_outside', null];
        yield 'protected the shared parent declares, from a sibling' => [
            $sibling,
            'parent_protected_from_sibling',
            'protectedMethod',
        ];
        yield 'protected only the receiver declares, from a sibling' => [
            $sibling,
            'child_protected_from_sibling',
            null,
        ];
        yield 'private, from its class on a subclass instance' => [$parent, 'own_private_on_child', 'privateMethod'];
    }

    /**
     * A member a call cannot reach from where it is written is not what the
     * call names (PHP manual, Visibility).
     */
    #[DataProvider('memberVisibility')]
    public function testAMemberResolvesOnlyWhereItIsVisible(string $fixture, string $marker, ?string $expected): void
    {
        $this->resolveOverTheFixtures();

        $symbol = $this->resolveSymbolAt($fixture, $marker);

        self::assertSame(
            $expected,
            $symbol instanceof MemberInfoInterface ? $symbol->getName()->name : null,
            "the member named at {$marker}",
        );
    }

    public function testACallResolvesWithTheReturnTypeOfTheMethodItCalls(): void
    {
        $this->resolveOverTheFixtures();

        $symbol = $this->resolveSymbolAt('src/Inheritance/ParentClass.php', 'shadowed_private_on_child');

        self::assertInstanceOf(MethodInfo::class, $symbol, 'the parent\'s own private method');
        self::assertSame('Fixtures\Inheritance\ParentClass', $symbol->name->owner->qualifiedName->fullyQualifiedName());
        self::assertSame(
            'int',
            $symbol->returnType?->format(),
            'its own return type, not that of the subclass method of the same name',
        );
    }

    public function testAParentCallPassesOnTheCallingClassAsStatic(): void
    {
        $this->resolveOverTheFixtures();

        $symbol = $this->resolveSymbolAt('src/LateBinding/Sub.php', 'parent_call_static');

        self::assertSame(
            'Fixtures\LateBinding\Sub',
            $symbol?->getType()?->format(),
            'parent::makeInstance() returns static, which a parent:: call binds to the calling class',
        );
    }

    public function testAParentConstantPassesOnTheCallingClassAsStatic(): void
    {
        $sub = self::className('Fixtures\LateBinding\Sub');
        $constant = new ClasslikeConstantInfo(
            name: new ClasslikeConstantName(self::className('Fixtures\LateBinding\Base'), 'NAME'),
            visibility: Visibility::Public,
            isFinal: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
        );
        $members = self::createStub(MemberResolverInterface::class);
        $members->method('findConstant')->willReturnCallback(
            static fn (ClasslikeName $class, string $name, ?ClasslikeName $from, ?ClasslikeName $static)
                => $static?->equals($sub) === true ? $constant : null,
        );
        $this->resolver = self::resolverOver(
            self::createStub(SymbolSourceInterface::class),
            self::createStub(TypeSourceInterface::class),
            $members,
        );

        self::assertSame(
            $constant,
            $this->resolveSymbolAt('src/LateBinding/Sub.php', 'parent_constant_static'),
            'a parent:: constant binds static to the calling class, as a parent:: call does',
        );
    }

    public function testAPropertyHooksParameterIsABindingInTheHook(): void
    {
        self::assertEquals(
            PrimitiveType::Int,
            $this->resolveVariableAt('TopLevel/this_binding.php', 'hook_parameter')?->getType(),
            'a set hook\'s parameter binds in the hook, typed as declared',
        );
    }

    public function testAStaticMethodsParameterIsTypedByItsMethod(): void
    {
        $this->typeEveryParameterAs(PrimitiveType::Int);

        self::assertEquals(
            PrimitiveType::Int,
            $this->resolveVariableAt('TopLevel/this_binding.php', 'static_method_param')?->getType(),
            'the method\'s parameter types are looked up by its class, which a static method still has',
        );
    }

    public function testUnboundVariableInAClosureWithoutCaptureIsUntyped(): void
    {
        self::assertEquals(
            new ResolvedVariable('outer', null),
            $this->resolveVariableAt(self::BINDINGS, 'closure_uncaptured'),
            'a long closure sees only what its use clause captures',
        );
    }

    public function testAParameterIsWhatItsDeclarationDeclares(): void
    {
        $this->typeEveryParameterAs(PrimitiveType::Int);

        self::assertEquals(
            new ParameterInfo('count', PrimitiveType::Int, '1', 1, false, false),
            $this->resolveAt(
                'src/Position/Symbols.php',
                fn (string $content): array => $this->locateCursor($content, 'second_parameter'),
            ),
            'a declared parameter carries its type, default, and place in the list',
        );
    }

    public function testACapturedVariableTakesItsTypeFromWhereItWasBound(): void
    {
        self::assertEquals(
            new ClasslikeType(self::className('Fixtures\Definition\VariableBindings')),
            $this->resolveVariableAt(self::BINDINGS, 'use_clause_usage')?->getType(),
            'a closure\'s use clause carries the type the variable had outside it',
        );
    }

    public function testAConstantResolvesThroughTheNamesInEffect(): void
    {
        $constant = self::constantInfo(QualifiedName::fromFullyQualified('PHP_EOL'));
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('lookupConstant')->willReturnCallback(
            static fn (ConstantName $name): ?ConstantInfo
                => $name->qualifiedName->fullyQualifiedName() === 'PHP_EOL' ? $constant : null,
        );
        $this->resolver = self::resolverOver(
            $symbols,
            self::createStub(TypeSourceInterface::class),
            self::createStub(MemberResolverInterface::class),
        );

        self::assertSame(
            $constant,
            $this->resolveAt(self::SYMBOLS, fn (string $content): array => $this->locateCursor($content, 'constant')),
            'an unqualified constant falls back to the global one',
        );
    }

    public function testAStaticPropertyResolvesOnTheNamedClass(): void
    {
        $child = self::className('Fixtures\Inheritance\ChildClass');
        $property = self::propertyInfo($child, 'staticProperty', self::userType());
        $members = self::createStub(MemberResolverInterface::class);
        $members->method('findProperty')->willReturnCallback(
            static fn (ClasslikeName $class, string $name): ?PropertyInfo
                => $class->equals($child) && $name === 'staticProperty' ? $property : null,
        );
        $this->resolver = self::resolverOver(
            self::createStub(SymbolSourceInterface::class),
            self::createStub(TypeSourceInterface::class),
            $members,
        );

        self::assertSame(
            $property,
            $this->resolveSymbolAt('src/Inheritance/ChildClass.php', 'self_property'),
            'self names the enclosing class',
        );
    }

    public function testAnArgumentsNameIsAParameterOfTheFunctionCalled(): void
    {
        $parameter = self::parameterInfo('stri', PrimitiveType::String);
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('lookupFunction')->willReturn(
            self::functionInfo(QualifiedName::fromFullyQualified('str_repeat'), parameters: [$parameter]),
        );
        $this->resolver = self::resolverOver(
            $symbols,
            self::createStub(TypeSourceInterface::class),
            self::createStub(MemberResolverInterface::class),
        );

        self::assertSame(
            $parameter,
            $this->resolveAt(
                'Position/ArgumentNameWord.php',
                fn (string $content): array => $this->locateCursor($content, 'argument_name'),
            ),
            'a function call\'s callable is the function',
        );
    }

    public function testAnArgumentsNameIsAParameterOfTheConstructorRun(): void
    {
        $parameter = self::parameterInfo('name', PrimitiveType::String);
        $members = self::createStub(MemberResolverInterface::class);
        $members->method('findMethod')->willReturnCallback(
            static fn (ClasslikeName $class, string $name): ?MethodInfo => self::isUser($class)
                && $name === '__construct'
                ? new MethodInfo(
                    name: new MethodName($class, '__construct'),
                    visibility: Visibility::Public,
                    isStatic: false,
                    isAbstract: false,
                    isFinal: false,
                    parameters: [$parameter],
                    returnType: null,
                    docblock: null,
                    file: null,
                    line: null,
                )
                : null,
        );
        $this->resolver = self::resolverOver(
            self::createStub(SymbolSourceInterface::class),
            self::createStub(TypeSourceInterface::class),
            $members,
        );

        self::assertSame(
            $parameter,
            $this->resolveAt(
                self::SYMBOLS,
                fn (string $content): array => $this->locateCursor($content, 'constructor_argument'),
            ),
            'a new expression\'s callable is the constructor it runs',
        );
    }

    /**
     * `new parent()` makes an instance of the parent, so `static` in its
     * constructor is the parent itself, not the class it is written in.
     */
    public function testAConstructorIsLookedUpWithoutForwardingStatic(): void
    {
        $fixture = 'src/TypeInference/NewKeywords.php';
        $content = $this->loadFixture($fixture);
        $document = new TextDocument(self::uri($fixture), 'php', 1, $content);
        $at = strpos($content, 'new parent(');
        assert(is_int($at), "{$fixture} constructs its parent");
        $located = (new PositionLocator(ProductionSyntaxSource::create()->source))
            ->locate($document, new ByteOffset($at + strlen('new parent(')));
        assert($located->call !== null, 'the position is in the constructor\'s argument list');

        $members = $this->createMock(MemberResolverInterface::class);
        $members->expects(self::once())
            ->method('findMethod')
            ->with(
                self::className('Fixtures\Inheritance\ParentClass'),
                '__construct',
                self::className('Fixtures\TypeInference\NewKeywords'),
                null,
            );
        $expressions = new ExpressionResolver(
            $members,
            self::createStub(SymbolSourceInterface::class),
            self::createStub(TypeSourceInterface::class),
        );

        $expressions->resolveCallable($located->call->call, $located->parsed);
    }

    public function testMethodCallResolvesOnTheReceiversClass(): void
    {
        $this->knowUserMembers();

        self::assertEquals(
            self::methodInfo(self::className(self::USER_CLASS), 'setName', self::userType()),
            $this->resolveSymbolAt(self::USER, 'setName'),
            'a method call resolves to the method on the class of its receiver',
        );
    }

    public function testStaticCallResolvesOnTheNamedClass(): void
    {
        $this->knowUserMembers();

        self::assertEquals(
            self::methodInfo(self::className(self::USER_CLASS), 'create', self::userType(), isStatic: true),
            $this->resolveSymbolAt(self::USER, 'create'),
            'a static call resolves to the method on the class it names',
        );
    }

    public function testPropertyFetchResolvesOnTheReceiversClass(): void
    {
        $this->knowUserMembers();

        self::assertEquals(
            self::propertyInfo(self::className(self::USER_CLASS), 'manager', self::userType()),
            $this->resolveSymbolAt(self::USER, 'manager'),
            'a property fetch resolves to the property on the class of its receiver',
        );
    }

    public function testMemberTheReceiversClassLacksIsUnresolved(): void
    {
        $members = $this->createMock(MemberResolverInterface::class);
        $members->expects(self::once())
            ->method('findProperty')
            ->with(self::className(self::USER_CLASS), 'unknownProperty')
            ->willReturn(null);
        $this->resolver = self::resolverOver(
            self::createStub(SymbolSourceInterface::class),
            self::createStub(TypeSourceInterface::class),
            $members,
        );

        self::assertNull(
            $this->resolveSymbolAt(self::USER, 'unknown_property'),
            'no class the receiver could be declares the property',
        );
    }

    public function testAMemberOfAnUntypedReceiverIsUnresolved(): void
    {
        self::assertNull(
            $this->resolveSymbolAt(self::USER, 'untyped_property'),
            'nothing names the class the receiver is',
        );
    }

    /**
     * @return iterable<string, array{string, string, ?MemberTarget}>
     */
    public static function memberTargets(): iterable
    {
        $procedural = 'src/Mixed/ProceduralWithClass.php';
        $inMultiClass = self::className('Fixtures\Completion\ChildInMultiFile');
        $inheritance = self::className('Fixtures\Completion\InheritanceCompletion');
        yield '$this' => [
            'MultiClass/MultiClass.php',
            'this_in_second_class',
            new MemberTarget(new ClasslikeType($inMultiClass), $inMultiClass, MemberAccessKind::Instance),
        ];
        yield 'parent::' => [
            'src/Completion/InheritanceCompletion.php',
            'parent_access',
            new MemberTarget(
                new ClasslikeType(self::className('Fixtures\Inheritance\ChildClass')),
                $inheritance,
                MemberAccessKind::Parent,
            ),
        ];
        yield 'a class by name, outside any class' => [
            $procedural,
            'standalone_static_access',
            new MemberTarget(
                new ClasslikeType(self::className('Fixtures\Completion\StaticAccess')),
                null,
                MemberAccessKind::Static,
            ),
        ];
        $static = self::className('Fixtures\Completion\StaticAccess');
        yield 'self::' => [
            'src/Completion/StaticAccess.php',
            'self_empty',
            new MemberTarget(new ClasslikeType($static), $static, MemberAccessKind::Static),
        ];
        yield 'static::' => [
            'src/Completion/StaticAccess.php',
            'static_keyword',
            new MemberTarget(new ClasslikeType($static), $static, MemberAccessKind::Static),
        ];
        yield 'self:: outside a class' => [$procedural, 'self_outside_class', null];
        yield 'an unbound variable' => [$procedural, 'unknown_var', null];
        yield 'an expression before ::' => ['src/Completion/StaticAccess.php', 'this_static', null];
    }

    #[DataProvider('memberTargets')]
    public function testAMemberIsLookedUpOnWhatItsReceiverNames(
        string $fixture,
        string $marker,
        ?MemberTarget $expected,
    ): void {
        $expressions = new ExpressionResolver(
            self::createStub(MemberResolverInterface::class),
            self::createStub(SymbolSourceInterface::class),
            self::createStub(TypeSourceInterface::class),
        );

        self::assertEquals(
            $expected,
            $this->memberTargetAt(
                $expressions,
                $fixture,
                fn (string $content): array => $this->locateCursor($content, $marker),
            ),
            'the type before the operator, seen from the class-like the access is written in',
        );
    }

    public function testAReceiverOfAPrimitiveTypeHasNoMembers(): void
    {
        $types = self::createStub(TypeSourceInterface::class);
        $types->method('forMethodParameter')->willReturn(PrimitiveType::Int);
        $expressions = new ExpressionResolver(
            self::createStub(MemberResolverInterface::class),
            self::createStub(SymbolSourceInterface::class),
            $types,
        );

        self::assertNull(
            $this->memberTargetAt(
                $expressions,
                self::BUILTIN_TYPES,
                fn (string $content): array => $this->locateHoverMarker($content, 'clone_receiver'),
            ),
            'an int names no class-like to look a member up on',
        );
    }

    private static function bindingAt(string $fixture, int $line, int $character): Location
    {
        return new Location(self::uri($fixture), $line, $character, $line, $character);
    }

    private static function dateTimeType(): ClasslikeType
    {
        return new ClasslikeType(self::className(\DateTime::class));
    }

    private static function functionDocumented(string $fqn, string $docblock): FunctionInfo
    {
        return new FunctionInfo(FunctionName::fromFullyQualified($fqn), [], null, $docblock, null, null);
    }

    private static function isUser(ClasslikeName $class): bool
    {
        return $class->equals(self::className(self::USER_CLASS));
    }

    private static function userType(): ClasslikeType
    {
        return new ClasslikeType(self::className(self::USER_CLASS));
    }

    private static function resolverOver(
        SymbolSourceInterface $symbols,
        TypeSourceInterface $types,
        MemberResolverInterface $members,
    ): SymbolAtPosition {
        return new SymbolAtPosition(
            new PositionLocator(ProductionSyntaxSource::create()->source),
            new ExpressionResolver($members, $symbols, $types),
            $symbols,
        );
    }

    private static function uri(string $fixture): string
    {
        return "file:///{$fixture}";
    }

    /**
     * Resolves against a symbol source that knows the fixtures' User class and
     * these functions, and nothing else.
     */
    private function knowUserAnd(FunctionInfo ...$functions): void
    {
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('lookupClassLike')->willReturnCallback(
            static fn (ClasslikeName $name): ?ClassInfo => $name->equals(self::className(self::USER_CLASS))
                ? self::classInfo(self::USER_CLASS)
                : null,
        );
        $symbols->method('lookupFunction')->willReturnCallback(
            static function (FunctionName $name) use ($functions): ?FunctionInfo {
                $wanted = $name->qualifiedName->fullyQualifiedName();
                foreach ($functions as $function) {
                    if ($function->name->qualifiedName->fullyQualifiedName() === $wanted) {
                        return $function;
                    }
                }

                return null;
            },
        );
        $this->resolver = self::resolverOver(
            $symbols,
            self::createStub(TypeSourceInterface::class),
            self::createStub(MemberResolverInterface::class),
        );
    }

    /**
     * Resolves against a member resolver that knows the User members the fixture
     * calls, and nothing else.
     */
    private function knowUserMembers(): void
    {
        $members = self::createStub(MemberResolverInterface::class);
        $members->method('findMethod')->willReturnCallback(
            static fn (ClasslikeName $class, string $name): ?MethodInfo => self::isUser($class)
                && in_array($name, ['setName', 'create'], true)
                ? self::methodInfo($class, $name, self::userType(), isStatic: $name === 'create')
                : null,
        );
        $members->method('findProperty')->willReturnCallback(
            static fn (ClasslikeName $class, string $name): ?PropertyInfo => self::isUser($class)
                && $name === 'manager'
                ? self::propertyInfo($class, $name, self::userType())
                : null,
        );
        $this->resolver = self::resolverOver(
            self::createStub(SymbolSourceInterface::class),
            self::createStub(TypeSourceInterface::class),
            $members,
        );
    }

    /**
     * Resolves against a type source that gives every method parameter this
     * type, and knows nothing else.
     */
    /**
     * Resolves against the fixtures' own classes, read as the server reads
     * them, so a member lookup sees the real type graph.
     */
    private function resolveOverTheFixtures(): void
    {
        $production = ProductionSyntaxSource::create();
        $symbols = new ComposerMapBackend(
            new ComposerAutoloadMapReader(dirname(__DIR__) . '/Fixtures'),
            $production->reader,
            $production->declarations,
        );
        $members = new MemberResolver($symbols);
        $this->resolver = self::resolverOver($symbols, new NativeTypeSource($symbols, $members), $members);
    }

    private function typeEveryParameterAs(TypeInterface $type): void
    {
        $types = self::createStub(TypeSourceInterface::class);
        $types->method('forMethodParameter')->willReturn($type);
        $this->resolver = self::resolverOver(
            self::createStub(SymbolSourceInterface::class),
            $types,
            self::createStub(MemberResolverInterface::class),
        );
    }

    /**
     * The `//hover:` marker lands on the call; this resolves its receiver.
     */
    private function resolveReceiverAt(string $fixture, string $marker, string $variable): ?ResolvedSymbolInterface
    {
        return $this->resolveAt($fixture, function (string $content) use ($marker, $variable): array {
            $line = $this->locateHoverMarker($content, $marker)['line'];
            $character = strpos(explode("\n", $content)[$line], $variable);
            assert($character !== false, "{$variable} is not on the line marked {$marker}");

            return ['line' => $line, 'character' => $character];
        });
    }

    private function resolveSymbolAt(string $fixture, string $marker): ?ResolvedSymbolInterface
    {
        return $this->resolveAt(
            $fixture,
            fn (string $content): array => $this->locateHoverMarker($content, $marker),
        );
    }

    private function resolveVariableAt(string $fixture, string $marker): ?ResolvedSymbolInterface
    {
        return $this->resolveAt(
            $fixture,
            fn (string $content): array => $this->locateVariableMarker($content, $marker),
        );
    }

    /**
     * @param \Closure(string): array{line: int, character: int} $locate Finds
     *        the position in the fixture's text.
     */
    private function resolveAt(string $fixture, \Closure $locate): ?ResolvedSymbolInterface
    {
        [$document, $offset] = $this->positionIn($fixture, $locate);

        return $this->resolver->symbolAt($document, $offset);
    }

    /**
     * @param \Closure(string): array{line: int, character: int} $locate Finds
     *        a member access in the fixture's text.
     */
    private function memberTargetAt(ExpressionResolver $expressions, string $fixture, \Closure $locate): ?MemberTarget
    {
        $located = (new PositionLocator(ProductionSyntaxSource::create()->source))
            ->locate(...$this->positionIn($fixture, $locate));
        assert($located->access !== null, "a member is accessed at the position in {$fixture}");

        return $expressions->memberTarget($located->access, $located->parsed);
    }

    /**
     * @param \Closure(string): array{line: int, character: int} $locate Finds
     *        the position in the fixture's text.
     * @return array{TextDocument, ByteOffset}
     */
    private function positionIn(string $fixture, \Closure $locate): array
    {
        $content = $this->loadFixture($fixture);
        ['line' => $line, 'character' => $character] = $locate($content);

        $document = new TextDocument(self::uri($fixture), 'php', 1, $content);

        return [$document, $document->offsetAt($line, $character)];
    }
}
