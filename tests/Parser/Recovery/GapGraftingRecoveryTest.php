<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\Recovery;

use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\Recovery\GapGraftingRecovery;
use Firehed\PhpLsp\Parser\Recovery\SkeletonRecovery;
use Firehed\PhpLsp\Parser\SyntaxSource\CodeParserInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\ParseAttempt;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserCodeParser;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GapGraftingRecovery::class)]
final class GapGraftingRecoveryTest extends TestCase
{
    use LoadsFixturesTrait;

    /**
     * Every kind of node that holds a body of statements in braces.
     */
    private const array BODIES = [
        Stmt\Namespace_::class, Stmt\Interface_::class, Stmt\Trait_::class, Stmt\Enum_::class,
        Stmt\Class_::class, Stmt\ClassMethod::class, Expr\Closure::class, Stmt\If_::class,
        Stmt\ElseIf_::class, Stmt\Else_::class, Stmt\While_::class, Stmt\Do_::class, Stmt\For_::class,
        Stmt\Foreach_::class, Stmt\TryCatch::class, Stmt\Catch_::class, Stmt\Finally_::class,
        Stmt\Declare_::class, Stmt\Block::class, Stmt\Function_::class,
    ];

    public function testEveryBodyGetsWhatTheParserDroppedFromIt(): void
    {
        $tree = $this->recover('Recovery/DroppedInEveryBody.php');

        $seen = [];
        $bodies = (new NodeFinder())->find(
            $tree,
            static fn (Node $node): bool => in_array($node::class, self::BODIES, true)
                && !($node instanceof Stmt\ClassMethod && $node->name->name === 'dropped'),
        );
        foreach ($bodies as $body) {
            $seen[] = $body::class;
            self::assertTrue(
                property_exists($body, 'stmts') && is_array($body->stmts) && self::holdsDroppedCode($body->stmts),
                $body::class . ' must hold the code the parser dropped from it',
            );
        }
        $missing = array_diff(self::BODIES, $seen);
        self::assertSame([], array_values($missing), 'the fixture must keep a body of every kind');
    }

    public function testAGapAfterTheLastStatementOfANamespaceDeclarationBelongsToIt(): void
    {
        $tree = $this->recover('Recovery/DroppedInStatementNamespace.php');

        $namespace = (new NodeFinder())->findFirstInstanceOf($tree, Stmt\Namespace_::class);
        self::assertNotNull($namespace);
        self::assertTrue(
            self::holdsDroppedCode($namespace->stmts),
            'the dropped statement is read inside the namespace',
        );
        self::assertCount(
            1,
            array_filter($tree, static fn (Stmt $stmt): bool => !$stmt instanceof Stmt\Declare_),
            'nothing is read outside it',
        );
    }

    public function testWhatIsReadSitsWhereItsCodeIsWritten(): void
    {
        $code = $this->loadFixture('Recovery/DroppedInStatementNamespace.php');
        $tree = $this->recover('Recovery/DroppedInStatementNamespace.php');

        $variable = (new NodeFinder())->findFirst(
            $tree,
            static fn (Node $node): bool => $node instanceof Expr\Variable && $node->name === 'dropped',
        );
        self::assertNotNull($variable);
        $offset = (int) strpos($code, '$dropped');
        self::assertSame($offset, $variable->getStartFilePos(), 'at its offset in the file');
        self::assertSame(substr_count($code, "\n", 0, $offset) + 1, $variable->getStartLine(), 'on its line');

        $call = (new NodeFinder())->findFirstInstanceOf($tree, Expr\FuncCall::class);
        self::assertNotNull($call);
        self::assertSame(
            [strpos($code, ", 1)")],
            ParsedDocument::argumentSeparatorsOf($call),
            'its argument separators are where its commas are',
        );
        $comments = (new NodeFinder())->findFirst(
            $tree,
            static fn (Node $node): bool => $node instanceof Stmt\Expression && $node->getComments() !== [],
        )?->getComments() ?? [];
        self::assertCount(1, $comments);
        self::assertSame(
            strpos($code, '// placed'),
            $comments[0]->getStartFilePos(),
            'its comments are where they are written',
        );
    }

    public function testAStatementReadOnlyInPartIsReadAgainWithWhatFollowsIt(): void
    {
        $tree = $this->recover('Recovery/PartlyReadStatement.php');

        $call = (new NodeFinder())->findFirst(
            self::methodBody($tree, 'partlyRead'),
            static fn (Node $node): bool => $node instanceof Expr\MethodCall,
        );
        self::assertInstanceOf(Expr\MethodCall::class, $call, 'the member and the call after it read as one');
        self::assertInstanceOf(Node\Identifier::class, $call->name);
        self::assertSame('partlyRead', $call->name->name);
    }

    public function testAStatementReadOnlyInPartStaysWhenNothingBetterReads(): void
    {
        $tree = $this->recover('Recovery/PartlyReadStatement.php');

        self::assertNotNull(
            (new NodeFinder())->findFirstInstanceOf(self::methodBody($tree, 'unreadable'), Expr\PropertyFetch::class),
            'what the parser read of it is kept',
        );
    }

    /**
     * @return iterable<string, array{string, class-string<Stmt|Expr>, class-string<Expr>}>
     */
    public static function unfinishedConstructs(): iterable
    {
        yield 'a name ending in a separator, before a comment' => ['separator', Expr\New_::class, Expr\New_::class];
        yield 'a for header' => ['loop', Stmt\For_::class, Expr\NullsafePropertyFetch::class];
        yield 'a match subject' => ['choice', Expr\Match_::class, Expr\ClassConstFetch::class];
    }

    /**
     * @param class-string<Stmt|Expr> $construct
     * @param class-string<Expr> $inside
     */
    #[DataProvider('unfinishedConstructs')]
    public function testAnUnfinishedConstructIsReadAsItWillBeOnceFinished(
        string $method,
        string $construct,
        string $inside,
    ): void {
        $body = self::methodBody($this->recover('Recovery/UnfinishedConstructs.php'), $method);
        $found = (new NodeFinder())->findFirstInstanceOf($body, $construct);

        self::assertInstanceOf($construct, $found, 'what was written is read as the construct it starts');
        self::assertNotNull(
            (new NodeFinder())->findFirstInstanceOf([$found], $inside),
            'with what is written inside it',
        );
    }

    public function testACommentReadWithItsCodeStaysWhereItIsWritten(): void
    {
        $code = $this->loadFixture('Recovery/UnfinishedConstructs.php');
        $statement = self::methodBody($this->recover('Recovery/UnfinishedConstructs.php'), 'commented')[0] ?? null;

        self::assertInstanceOf(Stmt\For_::class, $statement, 'the code after the comment is read');
        $comment = $statement->getComments()[0] ?? null;
        self::assertNotNull($comment, 'with the comment before it');
        $start = (int) strpos($code, '// kept with what follows');
        self::assertSame(
            [$start, $start + strlen('// kept with what follows') - 1],
            [$comment->getStartFilePos(), $comment->getEndFilePos()],
            'where the document has it',
        );
    }

    public function testCodeThatReadsAsNothingAddsNothing(): void
    {
        $tree = $this->recover('Recovery/StrayKeyword.php');

        self::assertCount(2, $tree, 'the statements around the stray keyword, and nothing for it');
    }

    public function testAGapNoEndingReadsLeavesTheTreeAsTheParserReadIt(): void
    {
        $code = $this->loadFixture('Recovery/StrayKeyword.php');
        $parser = new PhpParserCodeParser();
        $attempt = $parser->parse($code);
        $readsOnlyTheFile = new class ($parser, $code) implements CodeParserInterface {
            public function __construct(private PhpParserCodeParser $parser, private string $file)
            {
            }

            public function parse(string $code): ParseAttempt
            {
                return $code === $this->file ? $this->parser->parse($code) : new ParseAttempt([], [], []);
            }
        };

        $tree = (new GapGraftingRecovery($readsOnlyTheFile))->recover($code, $attempt)->tree;

        self::assertSame($attempt->tree, $tree, 'nothing is grafted where nothing reads');
    }

    public function testCodeBeingTypedIsReadWithoutTheLinesAfterIt(): void
    {
        $tree = $this->recover('src/Completion/AttributeNamedArgumentsIncomplete.php');

        $attribute = (new NodeFinder())->findFirstInstanceOf($tree, Node\Attribute::class);
        self::assertInstanceOf(Node\Attribute::class, $attribute, 'the attribute being typed is read');
        self::assertSame('Route', $attribute->name->toString());
    }

    public function testASkeletonsMethodBodiesAreRead(): void
    {
        $code = $this->loadFixture('Recovery/OnlySkeletonReads.php');
        $parser = new PhpParserCodeParser();
        $attempt = (new SkeletonRecovery())->recover($code, $parser->parse($code));

        $tree = (new GapGraftingRecovery($parser))->recover($code, $attempt)->tree;

        $method = (new NodeFinder())->findFirstInstanceOf($tree, Stmt\ClassMethod::class);
        self::assertNotNull($method);
        self::assertNotNull(
            (new NodeFinder())->findFirstInstanceOf($method->stmts ?? [], Expr\MethodCall::class),
            'the body the skeleton leaves empty is read',
        );
    }

    public function testCodeTheParserReadWholeIsLeftAlone(): void
    {
        $code = $this->loadFixture('src/Domain/User.php');
        $parser = new PhpParserCodeParser();
        $attempt = $parser->parse($code);

        self::assertSame($attempt, (new GapGraftingRecovery($parser))->recover($code, $attempt));
    }

    public function testNoTreeIsLeftAlone(): void
    {
        $attempt = new ParseAttempt([], [], []);

        self::assertSame($attempt, (new GapGraftingRecovery(new PhpParserCodeParser()))->recover('', $attempt));
    }

    /**
     * @return array<Stmt>
     */
    private function recover(string $fixture): array
    {
        $code = $this->loadFixture($fixture);
        $parser = new PhpParserCodeParser();
        return (new GapGraftingRecovery($parser))->recover($code, $parser->parse($code))->tree;
    }

    /**
     * @param array<Stmt> $tree
     * @return array<Stmt>
     */
    private static function methodBody(array $tree, string $name): array
    {
        $method = (new NodeFinder())->findFirst(
            $tree,
            static fn (Node $node): bool => $node instanceof Stmt\ClassMethod && $node->name->name === $name,
        );
        self::assertInstanceOf(Stmt\ClassMethod::class, $method, "the method {$name} is read");
        return $method->stmts ?? [];
    }

    /**
     * @param array<mixed> $statements
     */
    private static function holdsDroppedCode(array $statements): bool
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\ClassMethod && $statement->name->name === 'dropped') {
                return true;
            }
            if (
                $statement instanceof Stmt\Expression
                && $statement->expr instanceof Expr\Assign
                && $statement->expr->var instanceof Expr\Variable
                && $statement->expr->var->name === 'dropped'
            ) {
                return true;
            }
        }
        return false;
    }
}
