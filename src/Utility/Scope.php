<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use Firehed\PhpLsp\Domain\ClassName;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * The lexical scope a variable resolves against.
 *
 * A scope provides everything variable resolution needs: the parameters and
 * statements that can introduce variables, the class context for self/parent,
 * whether `$this` is bound, and any closure `use()` captures. Function-like AST
 * nodes and file-level (global/procedural) code both map onto this uniformly, so
 * consumers never branch on node type or handle a "no enclosing function" case.
 */
final class Scope
{
    /**
     * @param list<Node\Param> $params
     * @param array<Stmt> $statements
     * @param ?class-string $selfContext
     * @param ?class-string $parentContext
     * @param list<string> $capturedVariableNames
     */
    private function __construct(
        private readonly array $params,
        private readonly array $statements,
        private readonly ?string $selfContext,
        private readonly ?string $parentContext,
        private readonly ?ClassName $thisType,
        private readonly array $capturedVariableNames,
    ) {
    }

    /**
     * Build the scope enclosing a file offset: the innermost function-like node
     * containing it, or file-level (global) scope when there is none.
     *
     * @param array<Stmt> $ast
     */
    public static function atOffset(array $ast, int $offset): self
    {
        $node = self::findEnclosingFunctionLike($ast, $offset);
        if ($node !== null) {
            return self::forNode($node);
        }

        return self::global(self::globalStatementsAtOffset($ast, $offset));
    }

    public static function forNode(Stmt\Function_|Stmt\ClassMethod|Closure|ArrowFunction $node): self
    {
        $selfContext = ScopeFinder::findEnclosingClassName($node);
        $parentContext = null;
        if ($selfContext !== null) {
            $classNode = ScopeFinder::findEnclosingClassNode($node);
            if ($classNode instanceof Stmt\Class_) {
                $parentContext = ScopeFinder::resolveExtendsName($classNode);
            }
        }

        $thisType = ($node instanceof Stmt\ClassMethod && $selfContext !== null)
            ? new ClassName($selfContext)
            : null;

        // Arrow functions have an expression body, not a statement list; their
        // captured variables come from the enclosing scope, not local assignments.
        $statements = $node instanceof ArrowFunction ? [] : ($node->stmts ?? []);

        $capturedVariableNames = [];
        if ($node instanceof Closure) {
            foreach ($node->uses as $use) {
                if (is_string($use->var->name)) {
                    $capturedVariableNames[] = $use->var->name;
                }
            }
        }

        /** @var ?class-string $selfContext */
        /** @var ?class-string $parentContext */
        return new self($node->params, $statements, $selfContext, $parentContext, $thisType, $capturedVariableNames);
    }

    /**
     * @param array<Stmt> $statements
     */
    public static function global(array $statements): self
    {
        return new self([], $statements, null, null, null, []);
    }

    /**
     * @return list<Node\Param>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Statements that can introduce variables in this scope.
     *
     * @return array<Stmt>
     */
    public function getStatements(): array
    {
        return $this->statements;
    }

    /**
     * @return ?class-string
     */
    public function getSelfContext(): ?string
    {
        return $this->selfContext;
    }

    /**
     * @return ?class-string
     */
    public function getParentContext(): ?string
    {
        return $this->parentContext;
    }

    /**
     * The type of `$this` in this scope, or null when `$this` is not bound
     * (free functions, closures, and file-level code).
     */
    public function getThisType(): ?ClassName
    {
        return $this->thisType;
    }

    /**
     * Whether the named variable is captured by a closure `use()` clause. Such
     * variables are bound from the enclosing scope, so their type cannot be
     * determined from local assignments.
     */
    public function capturesVariable(string $name): bool
    {
        return in_array($name, $this->capturedVariableNames, true);
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function findEnclosingFunctionLike(
        array $ast,
        int $offset,
    ): Stmt\Function_|Stmt\ClassMethod|Closure|ArrowFunction|null {
        $visitor = new class ($offset) extends NodeVisitorAbstract {
            public Stmt\Function_|Stmt\ClassMethod|Closure|ArrowFunction|null $found = null;

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if (
                    ($node instanceof Stmt\Function_
                        || $node instanceof Stmt\ClassMethod
                        || $node instanceof Closure
                        || $node instanceof ArrowFunction)
                    && $node->getStartFilePos() <= $this->offset
                    && $node->getEndFilePos() >= $this->offset
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
     * The file-level statement list containing the offset: the body of the
     * enclosing namespace block, or the AST root when there is no namespace.
     *
     * @param array<Stmt> $ast
     * @return array<Stmt>
     */
    private static function globalStatementsAtOffset(array $ast, int $offset): array
    {
        foreach ($ast as $stmt) {
            if (
                $stmt instanceof Stmt\Namespace_
                && $stmt->getStartFilePos() <= $offset
                && $stmt->getEndFilePos() >= $offset
            ) {
                return $stmt->stmts;
            }
        }

        return $ast;
    }
}
