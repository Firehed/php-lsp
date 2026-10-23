<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\ResolvedCallableInterface;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Resolution\ExpressionResolverInterface;
use Firehed\PhpLsp\Resolution\Position\PositionLocator;
use Firehed\PhpLsp\Resolution\Position\SymbolAtPosition;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PhpParser\Node\Expr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolAtPosition::class)]
final class SymbolAtPositionTest extends TestCase
{
    use BuildsSymbolInfoTrait;
    use LoadsFixturesTrait;

    private const string FIXTURE = 'src/Position/Symbols.php';

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function words(): iterable
    {
        yield 'an imported class' => ['import', 'class'];
        yield 'a parameter\'s class type, named through its import' => ['parameter_type', 'class'];
        yield 'a type that is not a class-like' => ['builtin_type', null];
        yield 'a method\'s declared name' => ['declared_method', null];
        yield 'a parameter\'s declared name' => ['parameter', 'parameter'];
        yield 'a variable' => ['variable', Expr\Variable::class];
        yield 'a member' => ['member', Expr\MethodCall::class];
        yield 'a static member' => ['static_member', Expr\StaticCall::class];
        yield 'a called function' => ['called', Expr\FuncCall::class];
        yield 'an argument\'s name' => ['argument_name', 'argument'];
        yield 'a constant' => ['constant', Expr\ConstFetch::class];
        yield 'self as a return type' => ['self_type', 'self'];
        yield 'static as a static receiver' => ['static_receiver_keyword', 'self'];
        yield 'self after new' => ['new_self', 'self'];
        yield 'a comment' => ['comment', null];
        yield 'a member with nothing written' => ['unwritten_member', null];
    }

    /**
     * @param ?string $expected Which collaborator answer the position gives
     */
    #[DataProvider('words')]
    public function testAWordResolvesAsWhereItIsWrittenSays(string $marker, ?string $expected): void
    {
        $user = self::classInfo('Fixtures\Domain\User');
        $self = self::classInfo('Fixtures\Position\Symbols');
        $parameter = new ParameterInfo('user', null, null, 0, false, false);
        $argument = new ParameterInfo('user', null, null, 0, false, false);
        $resolved = [
            Expr\Variable::class => new ResolvedVariable('variable', null),
            Expr\MethodCall::class => new ResolvedVariable('method call', null),
            Expr\StaticCall::class => new ResolvedVariable('static call', null),
            Expr\FuncCall::class => new ResolvedVariable('function call', null),
            Expr\ConstFetch::class => new ResolvedVariable('constant', null),
        ];
        $answers = ['class' => $user, 'self' => $self, 'parameter' => $parameter, 'argument' => $argument]
            + $resolved;

        $callable = self::createStub(ResolvedCallableInterface::class);
        $callable->method('getParameterByName')->willReturnCallback(
            static fn (string $name): ?ParameterInfo => $name === 'user' ? $argument : null,
        );
        $expressions = self::createStub(ExpressionResolverInterface::class);
        $expressions->method('resolve')->willReturnCallback(
            static fn (Expr $expr): ?ResolvedSymbolInterface => $resolved[$expr::class] ?? null,
        );
        $expressions->method('resolveParameter')->willReturn($parameter);
        $expressions->method('resolveCallable')->willReturn($callable);
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('lookupClassLike')->willReturnCallback(
            static fn (ClasslikeName $name) => array_find(
                [$user, $self],
                static fn ($info): bool => $info->name->equals($name),
            ),
        );

        self::assertSame(
            $expected === null ? null : $answers[$expected],
            $this->symbolAt($marker, $expressions, $symbols),
            'the slot the word is written in decides what it names',
        );
    }

    public function testAVariableThatDoesNotResolveIsStillAVariable(): void
    {
        self::assertEquals(
            new ResolvedVariable('user', null),
            $this->symbolAt(
                'variable',
                self::createStub(ExpressionResolverInterface::class),
                self::createStub(SymbolSourceInterface::class),
            ),
            'a variable names itself even when nothing types it',
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function positionsWithNothingToResolve(): iterable
    {
        $user = 'src/Domain/User.php';
        yield 'a literal' => [$user, 'return 42; //hover:literal_number', '42'];
        // The inner `$name` resolves; the outer variable's name is an expression.
        yield 'the outer $ of a variable variable' => [$user, 'echo /*|outer_var_var*/$$name;', '$$name'];
        yield 'self outside any class' => [
            'src/Hover/ResolveNewFallbacks.php',
            '(new self())->foo(); //hover:global_self_new',
            'self',
        ];
    }

    #[DataProvider('positionsWithNothingToResolve')]
    public function testNothingResolvesAtAPositionWithoutASymbol(
        string $fixture,
        string $lineText,
        string $target,
    ): void {
        $code = $this->loadFixture($fixture);
        $line = strpos($code, $lineText);
        assert($line !== false);
        $offset = strpos($code, $target, $line);
        assert($offset !== false);
        $symbols = new SymbolAtPosition(
            new PositionLocator(ProductionSyntaxSource::create()->source),
            self::createStub(ExpressionResolverInterface::class),
            self::createStub(SymbolSourceInterface::class),
        );

        self::assertNull(
            $symbols->symbolAt(new TextDocument('file:///User.php', 'php', 1, $code), new ByteOffset($offset)),
            'nothing at the position names a symbol',
        );
    }

    private function symbolAt(
        string $marker,
        ExpressionResolverInterface $expressions,
        SymbolSourceInterface $symbols,
    ): ?ResolvedSymbolInterface {
        $code = $this->loadFixture(self::FIXTURE);
        $symbolAt = new SymbolAtPosition(
            new PositionLocator(ProductionSyntaxSource::create()->source),
            $expressions,
            $symbols,
        );
        return $symbolAt->symbolAt(
            new TextDocument('file:///' . self::FIXTURE, 'php', 1, $code),
            new ByteOffset($this->markerOffset($code, $marker)),
        );
    }
}
