<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Utility\NodeAtPosition;
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
 * Detects call context (function/method/constructor calls) at a cursor position.
 *
 * Combines the AST path (NodeAtPosition walk) and the text path
 * (TextFallbackHelper regex) in one class.
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
        private readonly TextFallbackHelper $textFallback,
    ) {
    }

    /**
     * Detect a call from the AST at the given offset.
     *
     * @param array<Stmt> $ast
     * @return RawDetection|null
     */
    public function fromAst(array $ast, int $offset): ?array
    {
        $nodeFinder = new NodeAtPosition();
        $node = $nodeFinder->find(
            $ast,
            $offset,
            fn (Node $n) => $n instanceof FuncCall
                || $n instanceof MethodCall
                || $n instanceof NullsafeMethodCall
                || $n instanceof StaticCall
                || $n instanceof New_
                || $n instanceof Attribute,
        );

        if (
            !$node instanceof FuncCall
            && !$node instanceof MethodCall
            && !$node instanceof NullsafeMethodCall
            && !$node instanceof StaticCall
            && !$node instanceof New_
            && !$node instanceof Attribute
        ) {
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
     * Detect a call from text when AST detection fails.
     *
     * @param array<Stmt> $ast
     * @return RawDetection|null
     */
    public function fromText(array $ast, int $offset, string $content, int $line): ?array
    {
        return $this->textFallback->detectCallFromText($ast, $offset, $content, $line);
    }
}
