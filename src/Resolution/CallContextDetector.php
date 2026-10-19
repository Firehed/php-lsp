<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Parser\NodeLocator\NodeLocatorInterface;
use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\FunctionLike;

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
        // The tree annotator sets the parent attribute, so this is a pointer walk,
        // not a traversal. A function body is not the enclosing call's argument:
        // inside one, only the calls in that body count.
        $node = $this->locator->nodeAt($parsed, $offset);
        while ($node !== null && !$node instanceof FunctionLike) {
            if (self::isCallLike($node)) {
                $list = ParsedDocument::argumentListOf($node);
                if ($list !== null && $offset > $list->opensAt) {
                    return self::detection($node, $list->separators, $offset);
                }
            }
            $parent = $node->getAttribute('parent');
            $node = $parent instanceof Node ? $parent : null;
        }

        return null;
    }

    /**
     * @param list<int> $separators
     * @return RawDetection
     */
    private static function detection(
        FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute $node,
        array $separators,
        int $offset,
    ): array {
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
