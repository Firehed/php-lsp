<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use Firehed\PhpLsp\Domain\LateBindingKeyword;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Utility for finding enclosing scopes in an AST.
 */
final class ScopeFinder
{
    /**
     * Find the enclosing function/method/closure for a node.
     *
     * Walks up the parent chain to find the innermost scope.
     */
    public static function findEnclosingScope(
        Node $node,
    ): Stmt\Function_|Stmt\ClassMethod|Closure|ArrowFunction|null {
        $current = $node->getAttribute('parent');
        while ($current instanceof Node) {
            if (
                $current instanceof Stmt\Function_
                || $current instanceof Stmt\ClassMethod
                || $current instanceof Closure
                || $current instanceof ArrowFunction
            ) {
                return $current;
            }
            $current = $current->getAttribute('parent');
        }
        return null;
    }

    /**
     * Find the enclosing class-like node (class, interface, trait, or enum).
     *
     * Walks up the parent chain to find the innermost class-like scope.
     */
    public static function findEnclosingClassNode(
        Node $node,
    ): Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null {
        $current = $node->getAttribute('parent');
        while ($current instanceof Node) {
            if (
                $current instanceof Stmt\Class_
                || $current instanceof Stmt\Interface_
                || $current instanceof Stmt\Trait_
                || $current instanceof Stmt\Enum_
            ) {
                return $current;
            }
            $current = $current->getAttribute('parent');
        }
        return null;
    }

    /**
     * Find the enclosing namespace node for a given node.
     *
     * Walks up the parent chain to find the namespace statement, if any.
     */
    public static function findEnclosingNamespace(Node $node): ?Stmt\Namespace_
    {
        $current = $node->getAttribute('parent');
        while ($current instanceof Node) {
            if ($current instanceof Stmt\Namespace_) {
                return $current;
            }
            $current = $current->getAttribute('parent');
        }
        return null;
    }

    /**
     * Resolve a class Name node to its fully qualified class name.
     *
     * Uses the resolved name attribute set by NameResolver when present;
     * falls back to the raw name otherwise.
     *
     * @return class-string
     */
    public static function resolveClassName(Name $name): string
    {
        $resolvedName = $name->getAttribute('resolvedName');
        /** @var class-string */
        return $resolvedName instanceof Name
            ? $resolvedName->toString()
            : $name->toString();
    }

    /**
     * Resolve a class Name node in context, handling special names.
     *
     * Handles `self`, `static`, and `parent` by resolving them to the
     * appropriate class name based on the enclosing class context.
     *
     * @return ?class-string
     */
    public static function resolveClassNameInContext(Name $name, Node $contextNode): ?string
    {
        $keyword = LateBindingKeyword::tryFromName($name->toString());
        if ($keyword !== null) {
            return $keyword->resolveIn(self::findEnclosingClassNode($contextNode));
        }

        return self::resolveClassName($name);
    }

    /**
     * Get the fully qualified name of a class-like node.
     *
     * @return ?class-string
     */
    public static function getClassLikeName(Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_ $node): ?string
    {
        return LateBindingKeyword::Self->resolveIn($node);
    }

    /**
     * Find the fully qualified name of the enclosing class-like node.
     *
     * Returns the FQN if available, otherwise the short name, or null if not
     * in a class context.
     *
     * @return ?class-string
     */
    public static function findEnclosingClassName(Node $node): ?string
    {
        return LateBindingKeyword::Self->resolveIn(self::findEnclosingClassNode($node));
    }

    /**
     * Find the namespace declaration containing a given zero-based line.
     *
     * Returns null when the line is outside any namespace block or the enclosing
     * namespace is the global namespace.
     *
     * @param array<Stmt> $ast
     */
    public static function findNamespaceAtLine(array $ast, int $line): ?string
    {
        return self::findNamespaceNodeAtLine($ast, $line)?->name?->toString();
    }

    /**
     * The namespace declaration enclosing a given zero-based line.
     *
     * A braced namespace ends at its closing brace. A semicolon-style one has no
     * closing token: it runs until the next namespace declaration, or to the end
     * of the file. Its node cannot say so — the parser moves the following
     * statements into the node and extends its end line only to the last of them
     * — so everything after that last statement, where a cursor routinely sits,
     * would otherwise look like it were outside the namespace entirely.
     *
     * @param array<Stmt> $ast
     */
    public static function findNamespaceNodeAtLine(array $ast, int $line): ?Stmt\Namespace_
    {
        // AST line numbers are one-based.
        $target = $line + 1;
        $enclosing = null;

        foreach ($ast as $stmt) {
            if (!$stmt instanceof Stmt\Namespace_ || $stmt->getStartLine() > $target) {
                continue;
            }

            if ($stmt->getAttribute('kind') === Stmt\Namespace_::KIND_BRACED) {
                if ($target <= $stmt->getEndLine()) {
                    return $stmt;
                }
                continue;
            }

            $enclosing = $stmt;
        }

        return $enclosing;
    }
}
