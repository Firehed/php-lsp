<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;

/**
 * Reads syntax trees the way the syntax-source contract describes them: up
 * parent links, with names compared as php-parser's name resolver leaves them.
 */
trait DescribesSyntaxTreesTrait
{
    /**
     * @template T of Node
     * @param class-string<T> $kind
     * @return ?T
     */
    private static function ancestorOf(Node $node, string $kind): ?Node
    {
        $current = $node->getAttribute('parent');
        while ($current instanceof Node && !$current instanceof $kind) {
            $current = $current->getAttribute('parent');
        }

        return $current instanceof $kind ? $current : null;
    }

    /**
     * The call $node is, or sits inside.
     */
    private static function enclosingCall(
        ?Node $node,
    ): FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute|null {
        while ($node !== null) {
            if (
                $node instanceof FuncCall
                || $node instanceof MethodCall
                || $node instanceof NullsafeMethodCall
                || $node instanceof StaticCall
                || $node instanceof New_
                || $node instanceof Attribute
            ) {
                return $node;
            }
            $parent = $node->getAttribute('parent');
            $node = $parent instanceof Node ? $parent : null;
        }

        return null;
    }

    /**
     * The name the node is called or accessed through: its class, its text,
     * and the namespaced form name resolution records for a function name.
     */
    private static function describeName(Node $node): string
    {
        $name = match (true) {
            $node instanceof FuncCall, $node instanceof Attribute => $node->name,
            $node instanceof New_, $node instanceof StaticCall, $node instanceof StaticPropertyFetch => $node->class,
            default => null,
        };
        if (!$name instanceof Node\Name) {
            return '(none)';
        }
        $namespaced = $name->getAttribute('namespacedName');

        return self::shortClass($name) . '(' . $name->toString() . ')'
            . ($namespaced instanceof Node\Name ? ' ns:' . $namespaced->toString() : '');
    }

    private static function shortClass(Node $node): string
    {
        $class = $node::class;
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
