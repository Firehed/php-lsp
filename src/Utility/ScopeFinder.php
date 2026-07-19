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
     * Resolve a class Name node in context, handling special names.
     *
     * Handles `self`, `static`, and `parent` by resolving them to the
     * appropriate class name based on the enclosing class context.
     *
     * @return ?class-string
     */
    public static function resolveClassNameInContext(Name $name, Node $contextNode): ?string
    {
        $rawName = $name->toString();

        if ($rawName === 'self' || $rawName === 'static') {
            return self::findEnclosingClassName($contextNode);
        }

        if ($rawName === 'parent') {
            $enclosingClass = self::findEnclosingClassNode($contextNode);
            if (!$enclosingClass instanceof Stmt\Class_) {
                return null;
            }
            return self::resolveExtendsName($enclosingClass);
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
     * Find the class containing the given line (0-indexed).
     *
     * @param array<Stmt> $ast
     */
    public static function findClassAtLine(array $ast, int $line): ?Stmt\Class_
    {
        $visitor = new class ($line) extends NodeVisitorAbstract {
            public ?Stmt\Class_ $found = null;

            public function __construct(private readonly int $line)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Class_ && ScopeFinder::nodeContainsLine($node, $this->line)) {
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
     * Extract all class imports from `use` statements as short name => FQCN.
     *
     * Superseded by {@see NameContextFactory}, which is type-aware and scoped to
     * one namespace block. This one folds `use function` and `use const` into the
     * class map and flattens every block in the file; its callers are moved over
     * in #331, after which it goes away.
     *
     * @param array<Stmt> $ast
     * @return array<string, string>
     */
    public static function extractImports(array $ast): array
    {
        $imports = [];

        foreach (self::iterateTopLevelStatements($ast) as $stmt) {
            if ($stmt instanceof Stmt\Use_) {
                foreach ($stmt->uses as $use) {
                    $shortName = $use->alias?->toString() ?? $use->name->getLast();
                    $imports[$shortName] = $use->name->toString();
                }
            } elseif ($stmt instanceof Stmt\GroupUse) {
                $prefix = $stmt->prefix->toString();
                foreach ($stmt->uses as $use) {
                    $shortName = $use->alias?->toString() ?? $use->name->getLast();
                    $imports[$shortName] = $prefix . '\\' . $use->name->toString();
                }
            }
        }

        return $imports;
    }

    /**
     * Resolve a simple class name using use statements from AST.
     *
     * Returns the fully qualified name if found in a use statement,
     * or null if not found.
     *
     * @param array<Stmt> $ast
     */
    public static function resolveFromUseStatements(string $className, array $ast): ?string
    {
        return self::extractImports($ast)[$className] ?? null;
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
