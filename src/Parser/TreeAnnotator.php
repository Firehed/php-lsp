<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use Closure;
use PhpParser\Error;
use PhpParser\ErrorHandler;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Token;

/**
 * The parent-connecting and name-resolving pass every
 * {@see SyntaxSource\SyntaxSourceInterface} runs on its own result, which
 * also records each call's argument list when given the tree's tokens.
 *
 * A skeleton tree ({@see SyntaxSource\SkeletonSyntaxSource})
 * is annotated by the same code as a parsed one, so a downstream
 * reader finds the parent links and resolved names
 * {@see ParsedDocument} promises regardless of which
 * source produced the tree.
 */
final class TreeAnnotator
{
    private readonly ParentConnectingVisitor $parents;
    private readonly NameResolver $names;

    /**
     * @param bool $tolerant When true, a name-resolution failure (a duplicate
     *        `use` alias, an unresolvable relative name) is swallowed rather
     *        than thrown. The skeleton {@see SyntaxSource\SkeletonSyntaxSource}
     *        builds trees from broken files where either can appear. The
     *        php-parser source runs in the strict default so a truly unrepresentable AST
     *        still yields no statements.
     *
     *        Swallowed errors are discarded, not collected: an annotator lives
     *        for the session, and an error keeps its node, and through parent
     *        links a whole document tree, alive.
     */
    public function __construct(bool $tolerant = false)
    {
        $this->parents = new ParentConnectingVisitor();
        $this->names = $tolerant ? new NameResolver(new class implements ErrorHandler {
            public function handleError(Error $error): void
            {
            }
        }) : new NameResolver();
    }

    /**
     * @param array<Node> $tree
     * @param array<Token> $tokens The tokens the tree was parsed from, or none
     *        when no lexer produced it; a source that builds calls itself sets
     *        {@see ParsedDocument::ARGUMENT_LIST} on them.
     * @return array<Stmt>
     */
    public function annotate(array $tree, array $tokens): array
    {
        $traverser = new NodeTraverser($this->parents, $this->names);
        if ($tokens !== []) {
            $annotateCall = static function (CallLike|Attribute $call) use ($tokens): void {
                $call->setAttribute(ParsedDocument::ARGUMENT_LIST, self::argumentList($call, $tokens));
            };
            $traverser->addVisitor(new class ($annotateCall) extends NodeVisitorAbstract {
                /**
                 * @param Closure(CallLike|Attribute): void $annotateCall
                 */
                public function __construct(private readonly Closure $annotateCall)
                {
                }

                public function enterNode(Node $node): null
                {
                    if ($node instanceof CallLike || $node instanceof Attribute) {
                        ($this->annotateCall)($node);
                    }
                    return null;
                }
            });
        }

        /** @var array<Stmt> */
        return $traverser->traverse($tree);
    }

    /**
     * @param array<Token> $tokens
     */
    private static function argumentList(CallLike|Attribute $call, array $tokens): ?ArgumentList
    {
        $args = $call instanceof Attribute ? $call->args : $call->getRawArgs();
        $opensAt = self::argumentListStart($call, $args, $tokens);

        return $opensAt === null ? null : new ArgumentList($opensAt, self::argumentSeparators($call, $args, $tokens));
    }

    /**
     * The `(` is the last real token before the first argument, or, with none,
     * before the `)` that closes the list (absent when recovery left it
     * unclosed). A call written without parentheses has neither, and no list.
     *
     * @param array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder> $args
     * @param array<Token> $tokens
     */
    private static function argumentListStart(CallLike|Attribute $call, array $args, array $tokens): ?int
    {
        if ($args === []) {
            $t = $call->getEndTokenPos();
            if ($tokens[$t]->is(')')) {
                $t--;
            }
        } else {
            $t = $args[0]->getStartTokenPos() - 1;
        }
        while ($tokens[$t]->isIgnorable()) {
            $t--;
        }
        return $tokens[$t]->is('(') ? $tokens[$t]->pos : null;
    }

    /**
     * A call's own commas sit only in the gaps between its arguments, or after
     * the last one before the `)` that closes the list (the call's last token,
     * when recovery left it unclosed), so only those gaps are scanned.
     *
     * @param array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder> $args
     * @param array<Token> $tokens
     * @return list<int>
     */
    private static function argumentSeparators(CallLike|Attribute $call, array $args, array $tokens): array
    {
        $separators = [];
        foreach ($args as $i => $arg) {
            $next = $args[$i + 1] ?? null;
            $gapEnd = $next === null ? $call->getEndTokenPos() : $next->getStartTokenPos() - 1;
            for ($t = $arg->getEndTokenPos() + 1; $t <= $gapEnd; $t++) {
                if ($next === null && $tokens[$t]->is(')')) {
                    break;
                }
                if ($tokens[$t]->is(',')) {
                    $separators[] = $tokens[$t]->pos;
                }
            }
        }
        return $separators;
    }
}
