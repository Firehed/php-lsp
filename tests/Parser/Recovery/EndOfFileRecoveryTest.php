<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\Recovery;

use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\Recovery\EndOfFileRecovery;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserCodeParser;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EndOfFileRecovery::class)]
final class EndOfFileRecoveryTest extends TestCase
{
    use LoadsFixturesTrait;

    /**
     * @return iterable<string, array{string, class-string<Node>}>
     */
    public static function unfinishedEndings(): iterable
    {
        yield 'member access' => ['Recovery/EndsInMemberAccess.php', Expr\PropertyFetch::class];
        yield 'an operand not yet typed' => ['Recovery/EndsAfterNew.php', Expr\New_::class];
        yield 'an unterminated string' => ['Recovery/EndsInString.php', Scalar\String_::class];
        yield 'an unterminated quoted string' => ['Recovery/EndsInQuotedString.php', Scalar\String_::class];
        yield 'an open argument list' => ['Recovery/EndsInCallArguments.php', Expr\FuncCall::class];
    }

    /**
     * @param class-string<Node> $unfinished
     */
    #[DataProvider('unfinishedEndings')]
    public function testTheUnfinishedConstructIsReadInsideItsMethod(string $fixture, string $unfinished): void
    {
        $code = $this->loadFixture($fixture);
        $parser = new PhpParserCodeParser();
        $attempt = $parser->parse($code);
        self::assertSame([], $attempt->tree, "{$fixture} must be code php-parser reads nothing of");

        $tree = (new EndOfFileRecovery($parser))->recover($code, $attempt)->tree;

        $method = (new NodeFinder())->findFirst(
            $tree,
            static fn (Node $node): bool => $node instanceof Stmt\ClassMethod && $node->name->name === 'run',
        );
        self::assertInstanceOf(Stmt\ClassMethod::class, $method, 'the method being edited is read');
        self::assertNotNull(
            (new NodeFinder())->findFirstInstanceOf($method->stmts ?? [], $unfinished),
            'the construct being typed is read inside it',
        );
    }

    public function testWhatTheEndingAddsIsNotWritten(): void
    {
        $code = $this->loadFixture('Recovery/EndsAfterNew.php');
        $parser = new PhpParserCodeParser();

        $tree = (new EndOfFileRecovery($parser))->recover($code, $parser->parse($code))->tree;

        $new = (new NodeFinder())->findFirstInstanceOf($tree, Expr\New_::class);
        self::assertNotNull($new);
        self::assertFalse(ParsedDocument::isWritten($new->class), 'the class after `new` is not typed yet');
        self::assertTrue(ParsedDocument::isWritten($new), 'the `new` that is typed is written');
        self::assertSame(strlen($code) - 1, $new->getEndFilePos(), 'and ends where the code does');
    }

    public function testAnEndingThatStillDoesNotParseLeavesTheAttempt(): void
    {
        $code = $this->loadFixture('Recovery/OnlyClassKeyword.php');
        $parser = new PhpParserCodeParser();
        $attempt = $parser->parse($code);
        self::assertSame([], $attempt->tree, 'the parser reads nothing of the code on its own');

        self::assertSame(
            $attempt,
            (new EndOfFileRecovery($parser))->recover($code, $attempt),
            'a declaration with neither name nor body is not finished by closing brackets',
        );
    }

    public function testCodeWithNoErrorsIsLeftAlone(): void
    {
        $code = $this->loadFixture('Recovery/OnlyOpenTag.php');
        $parser = new PhpParserCodeParser();
        $attempt = $parser->parse($code);

        self::assertSame(
            $attempt,
            (new EndOfFileRecovery($parser))->recover($code, $attempt),
            'code that declares nothing has nothing to recover',
        );
    }

    public function testATreeAlreadyReadIsLeftAlone(): void
    {
        $code = $this->loadFixture('src/Domain/User.php');
        $parser = new PhpParserCodeParser();
        $attempt = $parser->parse($code);

        self::assertSame($attempt, (new EndOfFileRecovery($parser))->recover($code, $attempt));
    }
}
