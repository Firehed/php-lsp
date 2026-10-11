<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\Lexer\PhpTokenLexer;
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
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Closure;
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
            'before a positional argument' => ['positional', false],
            'a bare word, which may become a name' => ['typing_first', false],
            'a positional variable' => ['positional_variable', true],
            'an imported constant, written as a bare word' => ['imported_constant', false],
            'right after a spread' => ['after_spread', true],
            'after a positional string and a space' => ['after_first_with_space', true],
            'the value of a named argument' => ['named_value', true],
            'the start of the argument after a named one' => ['next_argument', false],
            'right after a named argument\'s string value' => ['after_string_value', true],
            'right after a named argument\'s numeric value' => ['after_numeric_value', true],
            'right after an argument\'s name' => ['after_name', false],
            'right after an argument\'s colon' => ['after_colon', true],
        ];
    }

    #[DataProvider('argumentSlots')]
    public function testReportsWhetherAValueIsBeingTyped(string $marker, bool $expected): void
    {
        $detection = $this->detectAt('src/Resolution/ArgumentBoundaries.php', $marker);

        self::assertNotNull($detection, 'the cursor is inside a call');
        self::assertSame($expected, $detection[4], 'whether a value, where no argument name fits, is being typed');
    }

    /**
     * Outside a namespace a bare word resolves to a fully qualified name, which
     * must not hide that it was written bare.
     */
    public function testABareWordOutsideANamespaceMayStillBecomeAName(): void
    {
        $detection = $this->detectAt('TopLevel/positional_bare_word.php', 'global_bare_word');

        self::assertNotNull($detection, 'the cursor is inside a call');
        self::assertFalse($detection[4], 'a bare word may yet become an argument name');
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
            'a positional variable' => ['variable_in_call', true],
            'a bare word, which may become a name' => ['bare_word_in_call', false],
        ];
    }

    /**
     * While a call is being typed, its node can come from the cursor text
     * rather than the parsed tree; the rule is the same.
     */
    #[DataProvider('unclosedSlots')]
    public function testReportsAValueInAnUnclosedCall(string $marker, bool $expected): void
    {
        $detection = $this->detectAt(
            'src/Completion/EditingNamedArg.php',
            $marker,
            self::composite(),
        );

        self::assertNotNull($detection, 'the cursor is inside a call');
        self::assertSame($expected, $detection[4], 'whether a value, where no argument name fits, is being typed');
    }

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function callbackPositions(): array
    {
        $parsed = 'src/Resolution/CallbackBodies.php';
        $typed = 'src/IncompleteCode/CallbackBodies.php';

        return [
            'closure body' => [$parsed, 'closure_body', null],
            'closure parameter' => [$parsed, 'closure_parameter', null],
            'arrow function body' => [$parsed, 'arrow_body', null],
            'call in an arrow function body' => [$parsed, 'call_in_arrow_body', 'strlen'],
            'anonymous class body' => [$parsed, 'anonymous_class_body', null],
            'anonymous class property default' => [$parsed, 'anonymous_class_property', null],
            'anonymous class constructor argument' => [$parsed, 'anonymous_class_argument', 'new'],
            'closure body being typed' => [$typed, 'closure_body', null],
            'closure parameter being typed' => [$typed, 'closure_parameter', null],
            'arrow function parameter being typed' => [$typed, 'arrow_parameter', null],
            'arrow function body being typed' => [$typed, 'arrow_body', null],
            'member access in an arrow function body being typed' => [$typed, 'arrow_body_member', null],
            'call in an arrow function body being typed' => [$typed, 'call_in_arrow_body', 'strlen'],
            'argument after an arrow function being typed' => [$typed, 'after_arrow_argument', 'array_map'],
            'named arrow function argument being typed' => [$typed, 'named_arrow_body', null],
            'attributed arrow function being typed' => [$typed, 'attributed_arrow_body', null],
            'arrow function after an operator being typed' => [$typed, 'arrow_after_operator', null],
            'arrow function return type being typed' => [$typed, 'arrow_return_type', null],
            'arrow function head before its arrow' => [$typed, 'arrow_before_arrow', null],
            'closure use list being typed' => [$typed, 'closure_use', null],
            'argument after a closure being typed' => [$typed, 'after_closure_argument', 'array_map'],
            'anonymous class body being typed' => [$typed, 'anonymous_class_body', null],
        ];
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function closureEdges(): array
    {
        return [
            'at the closure\'s start' => [10, true],
            'inside the closure' => [11, false],
        ];
    }

    /**
     * A cursor where a closure argument begins is still at the argument.
     */
    #[DataProvider('closureEdges')]
    public function testOnlyAPositionStrictlyInsideAFunctionLikeLeavesTheCall(int $offset, bool $inCall): void
    {
        $closure = new Closure([], ['startFilePos' => 10]);
        $call = new FuncCall(new Name('f'), [new Arg($closure)], [ParsedDocument::ARGUMENT_SEPARATORS => []]);
        $closure->setAttribute('parent', $call->args[0]);
        $call->args[0]->setAttribute('parent', $call);
        $locator = self::createStub(NodeLocatorInterface::class);
        $locator->method('nodeAt')->willReturn($closure);

        $detection = (new CallContextDetector($locator))->detect(
            new ParsedDocument(new TextDocument('file:///f.php', 'php', 1, ''), []),
            $offset,
        );

        self::assertSame($inCall, $detection !== null, 'the call encloses only the closure\'s start');
    }

    /**
     * Code inside a function-like or class-like runs on its own, so it is not
     * an argument of a call the function-like or class-like is passed to.
     */
    #[DataProvider('callbackPositions')]
    public function testABodyPassedToACallIsNotInItsArguments(string $fixture, string $marker, ?string $callee): void
    {
        $detection = $this->detectAt(
            $fixture,
            $marker,
            self::composite(),
        );

        $call = $detection[0] ?? null;
        self::assertSame(
            $callee,
            match (true) {
                $call instanceof FuncCall && $call->name instanceof Name => $call->name->toString(),
                $call instanceof New_ => 'new',
                default => null,
            },
            'only a call whose arguments hold the cursor directly encloses it',
        );
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

    private static function composite(): CompositeNodeLocator
    {
        return new CompositeNodeLocator(new TreeNodeLocator(), new CursorTextNodeLocator(new PhpTokenLexer()));
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
