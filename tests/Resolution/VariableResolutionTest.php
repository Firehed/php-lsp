<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
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
 * Which binding a variable resolves to across closure boundaries. Bindings are
 * read from the tree alone, so the knowledge interfaces are stubbed.
 */
#[CoversClass(ExpressionResolver::class)]
#[CoversClass(Scope::class)]
final class VariableResolutionTest extends TestCase
{
    use LoadsFixturesTrait;

    private const string BINDINGS = 'src/Definition/VariableBindings.php';

    private const string TOP_LEVEL = 'TopLevel/top_level_closures.php';

    private SymbolResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SymbolResolver(
            ProductionSyntaxSource::create()->source,
            self::createStub(SymbolSourceInterface::class),
            self::createStub(MemberResolverInterface::class),
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

    private static function bindingAt(string $fixture, int $line, int $character): Location
    {
        return new Location(self::uri($fixture), $line, $character, $line, $character);
    }

    private static function uri(string $fixture): string
    {
        return "file:///{$fixture}";
    }

    private function resolveVariableAt(string $fixture, string $marker): ?ResolvedSymbolInterface
    {
        $content = $this->loadFixture($fixture);
        ['line' => $line, 'character' => $character] = $this->locateVariableMarker($content, $marker);

        return $this->resolver->resolveAtPosition(
            new TextDocument(self::uri($fixture), 'php', 1, $content),
            $line,
            $character,
        );
    }
}
