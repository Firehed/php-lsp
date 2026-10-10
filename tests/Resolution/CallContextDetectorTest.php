<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\NodeLocator\CompositeNodeLocator;
use Firehed\PhpLsp\Parser\NodeLocator\CursorTextNodeLocator;
use Firehed\PhpLsp\Parser\NodeLocator\NodeLocatorInterface;
use Firehed\PhpLsp\Parser\NodeLocator\TreeNodeLocator;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Resolution\CallContextDetector;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use LogicException;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type RawDetection from CallContextDetector
 */
#[CoversClass(CallContextDetector::class)]
final class CallContextDetectorTest extends TestCase
{
    use LoadsFixturesTrait;

    /**
     * @return array<string, array{string, class-string, int, list<string>, int}>
     */
    public static function calls(): array
    {
        return [
            'first argument' => ['first_param', FuncCall::class, 0, [], 0],
            'second argument' => ['second_param', FuncCall::class, 1, [], 1],
            'named argument' => ['named_arg', FuncCall::class, 1, ['a', 'b'], 0],
            'constructor' => ['constructor', New_::class, 0, [], 0],
            'static call' => ['static_call', StaticCall::class, 0, [], 0],
        ];
    }

    /**
     * @param class-string $callClass
     * @param list<string> $usedNames
     */
    #[DataProvider('calls')]
    public function testDetectsTheEnclosingCall(
        string $marker,
        string $callClass,
        int $activeParameter,
        array $usedNames,
        int $positionalCount,
    ): void {
        $detection = $this->detectAt('SignatureHelp.php', $marker);

        self::assertNotNull($detection, 'the cursor is inside a call');
        [$call, $active, $used, $positional] = $detection;
        self::assertInstanceOf($callClass, $call, 'the enclosing call is found through the parent links');
        self::assertSame($activeParameter, $active, 'commas before the cursor advance the parameter');
        self::assertSame($usedNames, $used, 'every named argument counts as used');
        self::assertSame($positionalCount, $positional, 'only positional arguments before the cursor fill');
    }

    public function testNoCallEnclosesTheCursor(): void
    {
        self::assertNull(
            $this->detectAt('SignatureHelp.php', 'outside_call'),
            'an assignment outside any call has no call context',
        );
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    public static function boundaries(): array
    {
        return [
            'typing the first argument' => ['typing_first', 0, 0],
            'after the first argument and a space' => ['after_first_with_space', 0, 0],
            'after a comma' => ['after_comma', 1, 1],
            'after a comma with no space' => ['after_comma_no_space', 1, 1],
            'typing the second argument' => ['typing_second', 1, 1],
            'after a named argument' => ['after_named', 1, 0],
            'inside the second argument\'s value' => ['inside_second_value', 1, 1],
        ];
    }

    #[DataProvider('boundaries')]
    public function testCountsOnlyArgumentsClosedByACommaAsFinished(
        string $marker,
        int $activeParameter,
        int $positionalCount,
    ): void {
        $detection = $this->detectAt('src/Resolution/ArgumentBoundaries.php', $marker);

        self::assertNotNull($detection, 'the cursor is inside a call');
        self::assertSame($activeParameter, $detection[1], 'the argument being typed is the active parameter');
        self::assertSame($positionalCount, $detection[3], 'only arguments before the cursor\'s argument are filled');
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function argumentSlots(): array
    {
        return [
            'a positional argument' => ['positional', false],
            'the value of a named argument' => ['named_value', true],
            'the start of the argument after a named one' => ['next_argument', false],
            'right after a named argument\'s string value' => ['after_string_value', true],
            'right after a named argument\'s numeric value' => ['after_numeric_value', true],
            'right after an argument\'s name' => ['after_name', false],
            'right after an argument\'s colon' => ['after_colon', true],
        ];
    }

    #[DataProvider('argumentSlots')]
    public function testReportsWhetherTheCursorIsInANamedArgumentsValue(string $marker, bool $expected): void
    {
        $detection = $this->detectAt('src/Resolution/ArgumentBoundaries.php', $marker);

        self::assertNotNull($detection, 'the cursor is inside a call');
        self::assertSame($expected, $detection[4], 'whether a named argument\'s value is being typed');
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function unclosedSlots(): array
    {
        return [
            'right after a numeric value' => ['after_named_value', true],
            'right after a string value' => ['after_string_value', true],
            'an empty argument list' => ['function_empty', false],
        ];
    }

    /**
     * While a call is being typed, its node can come from the cursor text
     * rather than the parsed tree; the rule is the same.
     */
    #[DataProvider('unclosedSlots')]
    public function testReportsANamedValueInAnUnclosedCall(string $marker, bool $expected): void
    {
        $detection = $this->detectAt(
            'src/Completion/EditingNamedArg.php',
            $marker,
            new CompositeNodeLocator(new TreeNodeLocator(), new CursorTextNodeLocator()),
        );

        self::assertNotNull($detection, 'the cursor is inside a call');
        self::assertSame($expected, $detection[4], 'whether a named argument\'s value is being typed');
    }

    public function testRejectsACallWithoutItsArgumentSeparators(): void
    {
        $locator = self::createStub(NodeLocatorInterface::class);
        $locator->method('nodeAt')->willReturn(new FuncCall(new Name('f')));

        $this->expectException(LogicException::class);

        (new CallContextDetector($locator))->detect(
            new ParsedDocument(new TextDocument('file:///f.php', 'php', 1, ''), []),
            0,
        );
    }

    /**
     * @return RawDetection|null
     */
    private function detectAt(string $fixture, string $marker, ?NodeLocatorInterface $locator = null): ?array
    {
        $content = $this->loadFixture($fixture);
        $parsed = (new PhpParserSyntaxSource(new TreeAnnotator()))->parse(
            new TextDocument('file:///' . $fixture, 'php', 1, $content),
        );

        $detector = new CallContextDetector($locator ?? new TreeNodeLocator());

        return $detector->detect($parsed, $this->markerOffset($content, $marker));
    }
}
