<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\SymbolResolver;
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

    private const string USER = 'src/Domain/User.php';

    private const string USER_CLASS = 'Fixtures\Domain\User';

    private SymbolResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = self::resolverOver(
            self::createStub(SymbolSourceInterface::class),
            self::createStub(TypeSourceInterface::class),
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

    private static function userType(): ClasslikeType
    {
        return new ClasslikeType(self::className(self::USER_CLASS));
    }

    private static function resolverOver(SymbolSourceInterface $symbols, TypeSourceInterface $types): SymbolResolver
    {
        $production = ProductionSyntaxSource::create();
        return new SymbolResolver(
            $production->source,
            $production->locator,
            $symbols,
            self::createStub(MemberResolverInterface::class),
            $types,
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
        $this->resolver = self::resolverOver($symbols, self::createStub(TypeSourceInterface::class));
    }

    /**
     * Resolves against a type source that gives every method parameter this
     * type, and knows nothing else.
     */
    private function typeEveryParameterAs(TypeInterface $type): void
    {
        $types = self::createStub(TypeSourceInterface::class);
        $types->method('forMethodParameter')->willReturn($type);
        $this->resolver = self::resolverOver(self::createStub(SymbolSourceInterface::class), $types);
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

        return $this->resolver->resolveAtPosition(
            new TextDocument(self::uri($fixture), 'php', 1, $content),
            $line,
            $character,
        );
    }
}
