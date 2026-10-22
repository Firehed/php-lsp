<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\Position\CallAtPosition;
use Firehed\PhpLsp\Resolution\Position\ExpectedAtPosition;
use Firehed\PhpLsp\Resolution\Position\PositionLocator;
use Firehed\PhpLsp\Resolution\Position\SymbolAtPosition;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Both hover on `$this` and completion on `$this->` route through
 * `ExpressionResolver::resolve(Variable('this'))`, which reads `$this` from the
 * node's scope.
 * The two features must agree on the receiver type for the same source input.
 *
 * Enclosing-class resolution runs off the AST alone — no backend lookup, no
 * member walk, no type source — so the symbol and type sources are stubbed,
 * and the member resolver needs no class to say `$this` sees private members.
 */
#[CoversClass(ExpectedAtPosition::class)]
#[CoversClass(SymbolAtPosition::class)]
final class ThisTypingParityTest extends TestCase
{
    use LoadsFixturesTrait;

    private ExpectedAtPosition $expected;

    private SymbolAtPosition $symbols;

    protected function setUp(): void
    {
        $production = ProductionSyntaxSource::create();
        $symbols = self::createStub(SymbolSourceInterface::class);
        $members = new MemberResolver($symbols);
        $types = self::createStub(TypeSourceInterface::class);
        $expressions = new ExpressionResolver($members, $symbols, $types);
        $locator = new PositionLocator($production->source);
        $this->expected = new ExpectedAtPosition(
            $locator,
            new CallAtPosition($locator, $expressions),
            $expressions,
            $members,
        );
        $this->symbols = new SymbolAtPosition($locator, $expressions, $symbols);
    }

    /**
     * @return iterable<string, array{
     *     relative: string,
     *     hoverNeedle: string,
     *     completionMarker: string,
     *     expectedClass: string,
     *     expectedVisibility: Visibility,
     * }>
     */
    public static function parityCases(): iterable
    {
        yield 'well-formed class body' => [
            'relative' => 'src/Resolution/ThisTypingParity.php',
            'hoverNeedle' => '$this;',
            'completionMarker' => 'this_member',
            'expectedClass' => 'Fixtures\\Resolution\\ThisTypingParity',
            // Vantage is the enclosing class, so `$this->` sees private members.
            'expectedVisibility' => Visibility::Private,
        ];
    }

    #[DataProvider('parityCases')]
    public function testHoverAndCompletionAgreeOnThisReceiverType(
        string $relative,
        string $hoverNeedle,
        string $completionMarker,
        string $expectedClass,
        Visibility $expectedVisibility,
    ): void {
        $content = $this->loadFixture($relative);
        $document = new TextDocument('file:///' . $relative, 'php', 1, $content);

        // Hover position: on the `$` of the `$this` expression the needle
        // names. The marker syntax cannot land inside a variable token, so
        // the position is derived from the fixture line and needle column.
        ['line' => $hoverLine, 'column' => $hoverColumn] = self::hoverAt($content, $hoverNeedle);
        $hover = $this->symbols->symbolAt($document, $document->offsetAt($hoverLine, $hoverColumn));
        self::assertNotNull($hover, 'hover on $this should resolve to a symbol');

        $access = $this->expected
            ->expectedAt($document, new ByteOffset($this->markerOffset($content, $completionMarker)))
            ->members;
        self::assertNotNull($access, 'completion on $this-> should detect a member-access context');

        self::assertSame(
            $hover->getType()?->format(),
            $access->type->format(),
            'hover on $this and completion on $this-> must resolve to the same enclosing class',
        );
        self::assertSame(
            $expectedClass,
            $access->type->format(),
            'the shared helper resolves the enclosing class in the fixture',
        );
        self::assertSame(
            $expectedVisibility,
            $access->minVisibility,
            'completion visibility must match the case expectation for this fixture',
        );
    }

    /**
     * @return array{line: int, column: int}
     */
    private static function hoverAt(string $content, string $needle): array
    {
        $offset = strpos($content, $needle);
        assert($offset !== false, "fixture is missing the needle \"$needle\"");
        $before = substr($content, 0, $offset);
        $line = substr_count($before, "\n");
        $lastNewline = strrpos($before, "\n");
        $column = $lastNewline === false ? $offset : $offset - $lastNewline - 1;
        return ['line' => $line, 'column' => $column];
    }
}
