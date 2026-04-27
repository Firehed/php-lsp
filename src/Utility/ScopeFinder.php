<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

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
     * Resolve a Name node to its fully qualified name.
     *
     * Uses the resolved name attribute if available (from NameResolver),
     * otherwise falls back to the raw name.
     */
    public static function resolveName(Name $name): string
    {
        $resolvedName = $name->getAttribute('resolvedName');
        return $resolvedName instanceof Name
            ? $resolvedName->toString()
            : $name->toString();
    }

    /**
     * Resolve a class Name node to its fully qualified class name.
     *
     * @return class-string
     */
    public static function resolveClassName(Name $name): string
    {
        /** @var class-string */
        return self::resolveName($name);
    }

    /**
     * Get the fully qualified name of a class-like node.
     *
     * @return ?class-string
     */
    public static function getClassLikeName(Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_ $node): ?string
    {
        if ($node->name === null) {
            return null;
        }
        /** @var class-string */
        return isset($node->namespacedName)
            ? $node->namespacedName->toString()
            : $node->name->toString();
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
        $classNode = self::findEnclosingClassNode($node);
        if ($classNode === null) {
            return null;
        }
        return self::getClassLikeName($classNode);
    }

    /**
     * Resolve the parent class name from a class node's extends clause.
     *
     * @return ?class-string
     */
    public static function resolveExtendsName(Stmt\Class_ $class): ?string
    {
        if ($class->extends === null) {
            return null;
        }
        return self::resolveClassName($class->extends);
    }

    /**
     * Check if a node's line range contains the given line (0-indexed).
     */
    public static function nodeContainsLine(Node $node, int $line): bool
    {
        $startLine = $node->getStartLine();
        $endLine = $node->getEndLine();

        return $startLine !== -1
            && $endLine !== -1
            && $line >= $startLine - 1
            && $line <= $endLine - 1;
    }

    /**
     * Find the class-like (class, interface, trait, enum) containing the given line (0-indexed).
     *
     * @param array<Stmt> $ast
     */
    public static function findClassLikeAtLine(
        array $ast,
        int $line,
    ): Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null {
        $visitor = new class ($line) extends NodeVisitorAbstract {
            public Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null $found = null;

            public function __construct(private readonly int $line)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if (
                    ($node instanceof Stmt\Class_
                        || $node instanceof Stmt\Interface_
                        || $node instanceof Stmt\Trait_
                        || $node instanceof Stmt\Enum_)
                    && ScopeFinder::nodeContainsLine($node, $this->line)
                ) {
                    $this->found = $node;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->found;
    }

    /**
     * Iterate top-level statements, flattening namespace contents.
     *
     * @param array<Stmt> $ast
     * @return \Generator<Stmt>
     */
    public static function iterateTopLevelStatements(array $ast): \Generator
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                yield from $stmt->stmts;
            } else {
                yield $stmt;
            }
        }
    }

    /**
     * Find a user-defined function by name in the AST.
     *
     * @param array<Stmt> $ast
     */
    public static function findFunction(string $functionName, array $ast): ?Stmt\Function_
    {
        $visitor = new class ($functionName) extends NodeVisitorAbstract {
            public ?Stmt\Function_ $found = null;

            public function __construct(private readonly string $functionName)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Function_ && $node->name->toString() === $this->functionName) {
                    $this->found = $node;
                    return NodeTraverser::STOP_TRAVERSAL;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->found;
    }
}
