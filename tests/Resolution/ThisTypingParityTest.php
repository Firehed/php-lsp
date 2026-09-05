<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Both hover on `$this` and completion on `$this->` route through
 * `ExpressionResolver::resolve(Variable('this'))`, which reads the enclosing
 * class through `EnclosingClassResolver`. The two features must agree on the
 * receiver type for the same source input (build-manifest step-31 done clause).
 */
#[CoversClass(SymbolResolver::class)]
final class ThisTypingParityTest extends TestCase
{
    use LoadsFixturesTrait;

    private SymbolResolver $resolver;

    protected function setUp(): void
    {
        $production = ProductionSyntaxSource::create();
        $parser = $production->source;
        $fixturesRoot = __DIR__ . '/../Fixtures';
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($fixturesRoot),
            $fixturesRoot . '/vendor',
            $parser,
            $production->reader,
        );
        $this->resolver = new SymbolResolver(
            $parser,
            $knowledge->source,
            new MemberResolver($knowledge->source),
        );
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

        // File-scope `$this`: the AST places the Variable in the namespace's
        // top-level statement list, not inside the Class_ body, so its parent
        // chain never reaches a class-like. `EnclosingClassResolver` must fall
        // back to text-scanning the document for the enclosing class. Both
        // hover and completion agree on the fallback answer for the receiver
        // type; vantage inference remains AST-driven, so completion sees only
        // public members here.
        yield 'detached parent chain via file-scope $this' => [
            'relative' => 'src/Resolution/BrokenThisTypingParity.php',
            'hoverNeedle' => '$this;',
            'completionMarker' => 'broken_member',
            'expectedClass' => 'Fixtures\\Resolution\\BrokenThisTypingParity',
            'expectedVisibility' => Visibility::Public,
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
        $hover = $this->resolver->resolveAtPosition($document, $hoverLine, $hoverColumn);
        self::assertNotNull($hover, 'hover on $this should resolve to a symbol');

        $completionCursor = $this->locateCursor($content, $completionMarker);
        $access = $this->resolver->getMemberAccessContext(
            $document,
            $completionCursor['line'],
            $completionCursor['character'],
        );
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
