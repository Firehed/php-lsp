<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Parser\NodeLocator\NodeLocatorInterface;
use Firehed\PhpLsp\Parser\ParsedDocument;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\ConstFetch;
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
 *   4: bool,
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

        $separators = ParsedDocument::argumentSeparatorsOf($node);
        if ($separators === null) {
            throw new LogicException('A syntax source returned a call without its argument separators');
        }
        $activeParam = count(array_filter($separators, static fn (int $pos): bool => $pos < $offset));
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

        $inValue = self::inValue($node->args[$activeParam] ?? null, $offset);

        return [$node, $activeParam, $usedNames, $positionalCount, $inValue];
    }

    /**
     * Whether the argument being typed is past the point where a name could
     * still be written: a named one once the cursor is past its name and the
     * character after it (the colon, as `name:` is written); a positional one
     * once it has begun and is not a bare word, which may yet become a name.
     */
    private static function inValue(?Node $typing, int $offset): bool
    {
        if (!$typing instanceof Arg) {
            return false;
        }
        if ($typing->name !== null) {
            return $offset > $typing->name->getEndFilePos() + 1;
        }
        $value = $typing->value;
        // Written as one word: the name spans only its last part, however it
        // resolved. A spread or by-reference argument is never a name.
        $bareWord = $value instanceof ConstFetch
            && !$typing->unpack
            && !$typing->byRef
            && $value->name->getEndFilePos() - $value->name->getStartFilePos() + 1 === strlen($value->name->getLast());
        return !$bareWord && $offset > $typing->getStartFilePos();
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
