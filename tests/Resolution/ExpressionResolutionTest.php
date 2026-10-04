<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\Scope;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Resolution that reads only the document's own tree, so the knowledge
 * interfaces are stubbed.
 */
#[CoversClass(ExpressionResolver::class)]
#[CoversClass(Scope::class)]
final class ExpressionResolutionTest extends TestCase
{
    use LoadsFixturesTrait;

    private const string BINDINGS = 'src/Definition/VariableBindings.php';

    private const string DYNAMIC = 'EdgeCases/DynamicAccess.php';

    private const string TOP_LEVEL = 'TopLevel/top_level_closures.php';

    private const string USER = 'src/Domain/User.php';

    private SymbolResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = self::resolverOver(self::createStub(SymbolSourceInterface::class));
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

    private static function bindingAt(string $fixture, int $line, int $character): Location
    {
        return new Location(self::uri($fixture), $line, $character, $line, $character);
    }

    private static function resolverOver(SymbolSourceInterface $symbols): SymbolResolver
    {
        return new SymbolResolver(
            ProductionSyntaxSource::create()->source,
            $symbols,
            self::createStub(MemberResolverInterface::class),
            self::createStub(TypeSourceInterface::class),
        );
    }

    private static function uri(string $fixture): string
    {
        return "file:///{$fixture}";
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
