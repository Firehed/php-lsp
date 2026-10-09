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
 * The parent-connecting and name-resolving pass every tree-producing
 * {@see SyntaxSource\SyntaxSourceInterface} runs on its own result, which
 * also records argument separators when given the tree's tokens.
 *
 * A skeleton tree ({@see SyntaxSource\SkeletonSyntaxSource})
 * is annotated by the same code as a parsed one, so a downstream
 * reader finds the parent links and resolved names
 * {@see SyntaxSource\SyntaxSourceInterface} promises regardless of which
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
     *        builds trees from broken files where either can appear, and the
     *        `phpstan.neon` traversal allowlist confines the two-visitor stack
     *        to this class — a separate tolerant annotator cannot live outside
     *        the allowlist, so the mode lives here instead. The php-parser
     *        source runs in the strict default so a truly unrepresentable AST
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
     *        when no lexer produced it; a source that builds nodes itself sets
     *        {@see SyntaxSource\SyntaxSourceInterface::ARGUMENT_SEPARATORS} on them.
     * @return array<Stmt>
     */
    public function annotate(array $tree, array $tokens): array
    {
        $traverser = new NodeTraverser($this->parents, $this->names);
        if ($tokens !== []) {
            $traverser->addVisitor(new class (
                static fn (CallLike|Attribute $call): array => self::argumentSeparators($call, $tokens),
            ) extends NodeVisitorAbstract {
                /**
                 * @param Closure(CallLike|Attribute): list<int> $separatorsOf
                 */
                public function __construct(private readonly Closure $separatorsOf)
                {
                }

                public function enterNode(Node $node): null
                {
                    if ($node instanceof CallLike || $node instanceof Attribute) {
                        $node->setAttribute(
                            SyntaxSource\SyntaxSourceInterface::ARGUMENT_SEPARATORS,
                            ($this->separatorsOf)($node),
                        );
                    }
                    return null;
                }
            });
        }

        /** @var array<Stmt> */
        return $traverser->traverse($tree);
    }

    /**
     * Scans from the first argument to the bracket that closes the list (or
     * the call's last token, when recovery left it unclosed), keeping commas
     * at the list's own depth.
     *
     * @param array<Token> $tokens
     * @return list<int>
     */
    private static function argumentSeparators(CallLike|Attribute $call, array $tokens): array
    {
        $args = $call instanceof Attribute ? $call->args : $call->getRawArgs();
        if ($args === []) {
            return [];
        }
        $separators = [];
        $depth = 0;
        for ($i = $args[0]->getStartTokenPos(); $i <= $call->getEndTokenPos(); $i++) {
            $token = $tokens[$i];
            if ($token->is(['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
                $depth++;
            } elseif ($token->is([')', ']', '}'])) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0 && $token->is(',')) {
                $separators[] = $token->pos;
            }
        }
        return $separators;
    }
}
