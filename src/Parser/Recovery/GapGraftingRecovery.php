<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\Recovery;

use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\CodeParserInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\ParseAttempt;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * php-parser recovers from an error by dropping the statement or class member
 * it occurs in. This {@see RecoveryInterface} finds each gap — code in a body
 * that no statement the tree holds covers, split where the parser reported an
 * error — reads it again on its own, finished the way its body needs, and
 * puts what it reads back where the code is. A skeleton's empty method bodies
 * are gaps too.
 *
 * Where a gap still cannot be read, it is left as it was.
 *
 * @phpstan-type Host Stmt\ClassLike|Stmt\ClassMethod|Stmt\Function_|Expr\Closure|Stmt\If_|Stmt\ElseIf_
 *     |Stmt\Else_|Stmt\While_|Stmt\Do_|Stmt\For_|Stmt\Foreach_|Stmt\TryCatch|Stmt\Catch_|Stmt\Finally_
 *     |Stmt\Namespace_|Stmt\Declare_|Stmt\Block
 */
final class GapGraftingRecovery implements RecoveryInterface
{
    use FinishesCodeTrait;

    /**
     * What a gap is read inside of, by the body it was dropped from.
     */
    private const string CLASS_BODY = '<?php class _ {';
    private const string BLOCK = '<?php function _() {';
    private const string FILE = '<?php ';

    /**
     * Endings that complete an unfinished class member: none, a method body,
     * a declaration for a dangling attribute or modifier to apply to, the
     * rest of a method header, or a property name.
     */
    private const array MEMBER_ENDINGS = ['', ' {}', ' function _() {}', '() {}', ' $_;'];

    /**
     * Endings that complete an unfinished statement: its semicolon, the block
     * a control structure or declaration header is waiting for, or none.
     */
    private const array STATEMENT_ENDINGS = [';', ' {}', ''];

    public function __construct(
        private readonly CodeParserInterface $parser,
    ) {
    }

    public function recover(string $code, ParseAttempt $attempt): ParseAttempt
    {
        return $this->graftGaps($code, $attempt, false);
    }

    /**
     * Reading a gap can drop code again, so what it reads has its own gaps
     * grafted once more, though no further.
     */
    private function graftGaps(string $code, ParseAttempt $attempt, bool $nested): ParseAttempt
    {
        if ($attempt->tree === [] || $attempt->errors === []) {
            return $attempt;
        }
        $layout = new CodeLayout($code, $attempt->tokens, $attempt->errors, $nested);

        $bodies = [];
        $nodes = (new NodeFinder())->find($attempt->tree, static fn (): bool => true);
        foreach (array_filter($nodes, self::isHost(...)) as $host) {
            $body = $layout->bracedBody(self::headerEnd($host), $host instanceof Node\FunctionLike);
            if ($body !== null) {
                $bodies[] = [$host, $body, $host instanceof Stmt\ClassLike ? self::CLASS_BODY : self::BLOCK];
            }
        }
        // A `namespace Name;` holds everything up to the next top-level
        // statement, so a gap after its last statement belongs inside it.
        $top = array_values($attempt->tree);
        foreach ($top as $i => $statement) {
            if (
                !$statement instanceof Stmt\Namespace_
                || $statement->getAttribute('kind') !== Stmt\Namespace_::KIND_SEMICOLON
            ) {
                continue;
            }
            $end = isset($top[$i + 1]) ? $top[$i + 1]->getStartFilePos() : strlen($code);
            $body = $layout->statementBody(self::headerEnd($statement), $end);
            if ($body !== null) {
                $statement->setAttribute('endFilePos', $body[1] - 1);
                $bodies[] = [$statement, $body, self::FILE];
            }
        }

        $tree = $this->graft($layout, $top, $layout->fileBody(), self::FILE);
        foreach ($bodies as [$host, $body, $kind]) {
            $host->stmts = $this->graft($layout, $host->stmts ?? [], $body, $kind);
        }
        return new ParseAttempt($tree, $attempt->tokens, $attempt->errors);
    }

    /**
     * Whether a node holds a body of statements in braces.
     *
     * @phpstan-assert-if-true Host $node
     */
    private static function isHost(Node $node): bool
    {
        return ($node instanceof Stmt\ClassLike
            || $node instanceof Stmt\ClassMethod
            || $node instanceof Stmt\Function_
            || $node instanceof Expr\Closure
            || $node instanceof Stmt\If_
            || $node instanceof Stmt\ElseIf_
            || $node instanceof Stmt\Else_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
            || $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\TryCatch
            || $node instanceof Stmt\Catch_
            || $node instanceof Stmt\Finally_
            || $node instanceof Stmt\Namespace_
            || $node instanceof Stmt\Declare_
            || $node instanceof Stmt\Block)
            && $node->stmts !== null;
    }

    /**
     * The last position of what comes before a body: the name, condition,
     * parameters, and types written ahead of its brace. A bare block starts
     * at its brace.
     *
     * @param Host $host
     */
    private static function headerEnd(Node $host): int
    {
        if ($host instanceof Stmt\Block) {
            return $host->getStartFilePos() - 1;
        }
        $parts = match (true) {
            $host instanceof Stmt\Class_ => [$host->name, $host->extends, ...$host->implements],
            $host instanceof Stmt\Interface_ => [$host->name, ...$host->extends],
            $host instanceof Stmt\Enum_ => [$host->name, $host->scalarType, ...$host->implements],
            $host instanceof Stmt\ClassLike => [$host->name],
            $host instanceof Expr\Closure => [...$host->params, ...$host->uses, $host->returnType],
            $host instanceof Stmt\ClassMethod, $host instanceof Stmt\Function_
                => [$host->name, ...$host->params, $host->returnType],
            $host instanceof Stmt\If_, $host instanceof Stmt\ElseIf_, $host instanceof Stmt\While_ => [$host->cond],
            $host instanceof Stmt\For_ => [...$host->init, ...$host->cond, ...$host->loop],
            $host instanceof Stmt\Foreach_ => [$host->expr, $host->keyVar, $host->valueVar],
            $host instanceof Stmt\Catch_ => [...$host->types, $host->var],
            $host instanceof Stmt\Namespace_ => [$host->name],
            $host instanceof Stmt\Declare_ => $host->declares,
            default => [],
        };
        $end = $host->getStartFilePos();
        foreach ($parts as $part) {
            if ($part instanceof Node) {
                $end = max($end, $part->getEndFilePos());
            }
        }
        return $end;
    }

    /**
     * The statements of a body with what each gap between them reads as. A
     * statement the parser read only part of, followed by a gap, is the start
     * of what it dropped, so it is read again with the gap; so is a member the
     * skeleton read from its text alone.
     *
     * @param array<Stmt> $statements
     * @param array{int, int} $body
     * @return list<Stmt>
     */
    private function graft(CodeLayout $layout, array $statements, array $body, string $host): array
    {
        [$cursor, $end] = $body;
        $statements = array_values($statements);
        $out = [];
        $partial = null;
        foreach ($statements as $i => $statement) {
            $out = [...$out, ...$this->readGapAfter($layout, $partial, $cursor, $statement->getStartFilePos(), $host)];
            $cursor = max($cursor, $statement->getEndFilePos() + 1);
            $next = $statements[$i + 1] ?? null;
            $partial = $statement->getAttribute(SkeletonRecovery::APPROXIMATE) === true
                || ((new NodeFinder())->findFirstInstanceOf([$statement], Expr\Error::class) !== null
                    && $layout->piecesOf($cursor, $next?->getStartFilePos() ?? $end) !== [])
                ? $statement
                : null;
            if ($partial === null) {
                $out[] = $statement;
            }
        }
        return [...$out, ...$this->readGapAfter($layout, $partial, $cursor, $end, $host)];
    }

    /**
     * What a gap reads as, read from where a partly read statement before it
     * starts; that statement as the parser read it where the two together
     * read as nothing.
     *
     * @return list<Stmt>
     */
    private function readGapAfter(CodeLayout $layout, ?Stmt $partial, int $start, int $end, string $host): array
    {
        if ($partial === null) {
            return $this->readGap($layout, $start, $end, $host);
        }
        $read = $this->readGap($layout, $partial->getStartFilePos(), $end, $host);
        return $read === [] ? [$partial] : $read;
    }

    /**
     * @return list<Stmt>
     */
    private function readGap(CodeLayout $layout, int $start, int $end, string $host): array
    {
        $out = [];
        foreach ($layout->piecesOf($start, $end) as [$pieceStart, $pieceEnd]) {
            array_push($out, ...$this->readPiece($layout, $pieceStart, $pieceEnd, $host));
        }
        return $out;
    }

    /**
     * @return list<Stmt>
     */
    private function readPiece(CodeLayout $layout, int $start, int $end, string $host): array
    {
        $text = $host . $layout->text($start, $end);
        $chosen = null;
        foreach (self::endings($host, $text) as $ending) {
            $attempt = $this->parser->parse($text . $ending);
            if (self::statementsIn($attempt->tree, $host) === []) {
                continue;
            }
            // Without an ending that reads cleanly, the earliest that reads
            // anything is the most faithful.
            $chosen ??= [$text . $ending, $attempt];
            if ($attempt->errors === []) {
                $chosen = [$text . $ending, $attempt];
                break;
            }
        }
        if ($chosen === null) {
            return [];
        }
        [$finished, $attempt] = $chosen;
        $read = self::statementsIn(
            $layout->nested ? $attempt->tree : $this->graftGaps($finished, $attempt, true)->tree,
            $host,
        );
        self::place($read, $start - strlen($host), $layout->lineOf($start) - 1, $end - 1);
        // A statement only the ending wrote reads none of the gap's code.
        return array_values(array_filter($read, ParsedDocument::isWritten(...)));
    }

    /**
     * @return list<string>
     */
    private static function endings(string $host, string $text): array
    {
        [$standIn, $open, $previous] = self::unfinished(array_values(\PhpToken::tokenize($text)));
        // A class body or block is read inside a wrapper whose brace opens first.
        $wrapped = $host !== self::FILE;
        $inner = $standIn . self::terminated($wrapped ? array_slice($open, 1) : $open, $previous);
        return array_map(
            static fn (string $ending): string => $inner . $ending . ($wrapped ? '}' : ''),
            $host === self::CLASS_BODY ? self::MEMBER_ENDINGS : self::STATEMENT_ENDINGS,
        );
    }

    /**
     * @param array<Stmt> $tree
     * @return list<Stmt>
     */
    private static function statementsIn(array $tree, string $host): array
    {
        if ($host === self::FILE) {
            return array_values($tree);
        }
        $wrapper = $tree[0] ?? null;
        $statements = $wrapper instanceof Stmt\Class_ || $wrapper instanceof Stmt\Function_ ? $wrapper->stmts : [];
        return array_values($statements);
    }
}
