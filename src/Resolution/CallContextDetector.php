<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSource;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt;

/**
 * Detects call context (function/method/constructor calls) at a cursor
 * position. One `nodeAt` lookup through the {@see SyntaxSource} composite plus
 * a parent walk: the cursor-text source synthesizes the call frame for a
 * broken parse (build-manifest step-41), so no separate text path is needed.
 *
 * @phpstan-type RawDetection array{
 *   0: FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute,
 *   1: int,
 *   2: list<string>,
 *   3: int,
 * }
 *
 * @internal
 */
final class CallContextDetector
{
    public function __construct(
        private readonly SyntaxSource $parser,
    ) {
    }

    /**
     * @param array<Stmt> $ast
     * @return RawDetection|null
     */
    public function detect(array $ast, TextDocument $document, int $offset): ?array
    {
        $node = $this->parser->nodeAt($ast, $document, $offset);
        // Walk parents until an enclosing call is found. The tree annotator sets
        // the parent attribute, so this is a pointer walk, not a traversal.
        while ($node !== null && !self::isCallLike($node)) {
            $parent = $node->getAttribute('parent');
            $node = $parent instanceof Node ? $parent : null;
        }

        if (!self::isCallLike($node)) {
            return null;
        }

        $activeParam = 0;
        $usedNames = [];
        $positionalCount = 0;
        $sawNamedArg = false;

        foreach ($node->args as $i => $arg) {
            $argEnd = $arg->getEndFilePos();
            $argBeforeCursor = $offset > $argEnd;

            if ($arg instanceof Arg && $arg->name !== null) {
                $usedNames[] = $arg->name->name;
                $sawNamedArg = true;
            } elseif (!$sawNamedArg && $argBeforeCursor) {
                $positionalCount++;
            }
            if ($argBeforeCursor) {
                $activeParam = $i + 1;
            }
        }

        return [$node, $activeParam, $usedNames, $positionalCount];
    }

    /**
     * @phpstan-assert-if-true FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute $node
     */
    private static function isCallLike(?Node $node): bool
    {
        return $node instanceof FuncCall
            || $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
            || $node instanceof StaticCall
            || $node instanceof New_
            || $node instanceof Attribute;
    }
}
