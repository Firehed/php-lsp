<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Parser\NodeLocator\NodeLocatorInterface;
use Firehed\PhpLsp\Parser\ParsedDocument;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;

/**
 * Detects call context (function/method/constructor calls) at a cursor
 * position through one `nodeAt` lookup and a parent walk.
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
        private readonly NodeLocatorInterface $locator,
    ) {
    }

    /**
     * @return RawDetection|null
     */
    public function detect(ParsedDocument $parsed, int $offset): ?array
    {
        $node = $this->locator->nodeAt($parsed, $offset);
        // Walk parents until an enclosing call is found. The tree annotator sets
        // the parent attribute, so this is a pointer walk, not a traversal.
        while ($node !== null && !self::isCallLike($node)) {
            $parent = $node->getAttribute('parent');
            $node = $parent instanceof Node ? $parent : null;
        }

        if (!self::isCallLike($node)) {
            return null;
        }

        $separators = $node->getAttribute(ParsedDocument::ARGUMENT_SEPARATORS);
        if (!is_array($separators)) {
            throw new LogicException('A syntax source returned a call without its argument separators');
        }
        $activeParam = count(array_filter($separators, static fn (mixed $pos): bool => $pos < $offset));
        $usedNames = [];
        $positionalCount = 0;
        $sawNamedArg = false;

        foreach ($node->args as $i => $arg) {
            if ($arg instanceof Arg && $arg->name !== null) {
                $usedNames[] = $arg->name->name;
                $sawNamedArg = true;
            } elseif (!$sawNamedArg && $i < $activeParam) {
                $positionalCount++;
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
