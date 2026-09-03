<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * One resolver for the enclosing class-like of a node.
 *
 * Reads the parent chain first ({@see ScopeFinder::findEnclosingClassName}); if
 * the chain is detached from any class-like — a parser-recovered AST that lost
 * its class scope, or a synthetic node built for chain-typing on broken code —
 * falls back to {@see TextFallbackHelper::resolveEnclosingClassName} using the
 * node's document position. The one caller allowed by
 * {@see phpstan.neon}'s disallow rule, so `$this` typing in
 * {@see ExpressionResolver::resolve} cannot fork onto its own text-fallback
 * path.
 *
 * @internal
 */
final class EnclosingClassResolver
{
    public function __construct(private readonly TextFallbackHelper $textFallback)
    {
    }

    /**
     * @param array<Stmt> $ast
     * @return ?class-string
     */
    public function forNode(Node $node, array $ast, TextDocument $document): ?string
    {
        $name = ScopeFinder::findEnclosingClassName($node);
        if ($name !== null) {
            return $name;
        }
        $offset = $node->getStartFilePos();
        if ($offset < 0) {
            return null;
        }
        $line = max(0, $node->getStartLine() - 1);
        return $this->textFallback->resolveEnclosingClassName(
            $ast,
            $offset,
            $document->getContent(),
            $line,
        );
    }
}
