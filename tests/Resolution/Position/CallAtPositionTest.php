<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Domain\ResolvedCallableInterface;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\ExpressionResolverInterface;
use Firehed\PhpLsp\Resolution\Position\CallAtPosition;
use Firehed\PhpLsp\Resolution\Position\PositionLocator;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallAtPosition::class)]
final class CallAtPositionTest extends TestCase
{
    use LoadsFixturesTrait;

    private const string BOUNDARIES = 'src/Resolution/ArgumentBoundaries.php';
    private const string EDITING = 'src/Completion/EditingNamedArg.php';

    public function testTheCallCarriesWhatItsArgumentsHold(): void
    {
        $callable = self::createStub(ResolvedCallableInterface::class);

        self::assertEquals(
            new CallContext($callable, 1, ['count'], 1, inArgumentValue: true),
            $this->callAt(self::BOUNDARIES, 'function_named_value', $callable),
            'the callable, the argument at the position, the arguments supplied, and that a value is being typed',
        );
    }

    public function testNoCallOutsideACallsArguments(): void
    {
        self::assertNull(
            $this->callAt('SignatureHelp.php', 'outside_call', self::createStub(ResolvedCallableInterface::class)),
            'no call\'s arguments hold the position',
        );
    }

    public function testNoCallWhenWhatIsCalledDoesNotResolve(): void
    {
        self::assertNull($this->callAt('SignatureHelp.php', 'second_param', null), 'nothing to show a signature of');
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function argumentSlots(): iterable
    {
        $b = self::BOUNDARIES;
        yield 'before a positional argument' => [$b, 'positional', false];
        yield 'a bare word, which may become a name' => [$b, 'typing_first', false];
        yield 'a positional variable' => [$b, 'positional_variable', true];
        yield 'an imported constant, written as a bare word' => [$b, 'imported_constant', false];
        yield 'right after a spread' => [$b, 'after_spread', true];
        yield 'after a positional string and a space' => [$b, 'after_first_with_space', true];
        yield 'the value of a named argument' => [$b, 'named_value', true];
        yield 'the start of the argument after a named one' => [$b, 'next_argument', false];
        yield 'right after a named argument\'s string value' => [$b, 'after_string_value', true];
        yield 'right after a named argument\'s numeric value' => [$b, 'after_numeric_value', true];
        yield 'right after an argument\'s name' => [$b, 'after_name', false];
        yield 'right after an argument\'s colon' => [$b, 'after_colon', true];
        yield 'a bare word outside a namespace' => ['TopLevel/positional_bare_word.php', 'global_bare_word', false];
        yield 'right after a numeric value, unclosed' => [self::EDITING, 'after_named_value', true];
        yield 'right after a string value, unclosed' => [self::EDITING, 'after_string_value', true];
        yield 'an empty argument list, unclosed' => [self::EDITING, 'function_empty', false];
        yield 'a positional variable, unclosed' => [self::EDITING, 'variable_in_call', true];
        yield 'a bare word, unclosed' => [self::EDITING, 'bare_word_in_call', false];
        yield 'an empty argument list' => ['SignatureHelp.php', 'no_constructor', false];
    }

    #[DataProvider('argumentSlots')]
    public function testReportsWhetherAValueIsBeingTyped(string $fixture, string $marker, bool $inValue): void
    {
        $call = $this->callAt($fixture, $marker, self::createStub(ResolvedCallableInterface::class));

        self::assertNotNull($call, 'the position is in a call\'s arguments');
        self::assertSame($inValue, $call->inArgumentValue, 'whether a value, where no argument name fits, is typed');
    }

    private function callAt(string $fixture, string $marker, ?ResolvedCallableInterface $callable): ?CallContext
    {
        $code = $this->loadFixture($fixture);
        $expressions = self::createStub(ExpressionResolverInterface::class);
        $expressions->method('resolveCallable')->willReturn($callable);
        $calls = new CallAtPosition(new PositionLocator(ProductionSyntaxSource::create()->source), $expressions);

        return $calls->callAt(
            new TextDocument('file:///' . $fixture, 'php', 1, $code),
            new ByteOffset($this->markerOffset($code, $marker)),
        );
    }
}
