<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\Position\PositionLocator;
use Firehed\PhpLsp\Resolution\Position\SymbolAtPosition;
use Firehed\PhpLsp\Resolution\ResolvedTypeOnly;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
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

    public function testTopLevelArrowFunctionHasNoScopeToCaptureFrom(): void
    {
        self::assertEquals(
            new ResolvedVariable('outer', null),
            $this->resolveVariableAt(self::TOP_LEVEL, 'top_arrow_capture'),
            'a file-scope assignment is not an enclosing function scope, so no binding is found',
        );
    }

    public function testTopLevelClosureUseBindsWithoutAType(): void
    {
        self::assertEquals(
            new ResolvedVariable('closureOuter', null, self::bindingAt(self::TOP_LEVEL, 14, 28)),
            $this->resolveVariableAt(self::TOP_LEVEL, 'top_closure_use_capture'),
            'the use clause is the binding, but no enclosing function gives it a type',
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
            new ResolvedTypeOnly(new ClasslikeType(self::className('Fixtures\Definition\VariableBindings'))),
            $this->resolveVariableAt(self::BINDINGS, 'this_usage'),
            '$this is typed by the class it appears in',
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
        $this->typeEveryParameterAs(new PrimitiveType('int'));

        self::assertEquals(
            new ParameterInfo('count', new PrimitiveType('int'), '1', 1, false, false),
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
        $parameter = self::parameterInfo('stri', new PrimitiveType('string'));
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
        $parameter = self::parameterInfo('name', new PrimitiveType('string'));
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
        $content = $this->loadFixture($fixture);
        ['line' => $line, 'character' => $character] = $locate($content);

        $document = new TextDocument(self::uri($fixture), 'php', 1, $content);

        return $this->resolver->symbolAt($document, $document->offsetAt($line, $character));
    }
}
