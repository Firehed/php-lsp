<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;

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
     * @param array<Node\Param> $params
     * @param array<Stmt> $statements
     * @param ?class-string $selfContext
     * @param ?class-string $parentContext
     * @param array<Node\ClosureUse> $uses
     */
    private function __construct(
        private readonly array $params,
        private readonly array $statements,
        private readonly ?string $selfContext,
        private readonly ?string $parentContext,
        private readonly ?ClasslikeName $thisType,
        private readonly array $uses,
        private readonly Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null $enclosingClassLike,
        private readonly Stmt\Function_|Stmt\ClassMethod|Closure|ArrowFunction|null $sourceNode,
    ) {
    }

    /**
     * The scope a node is in: its innermost function-like's, or the global
     * scope of its namespace's statements, or the file's when it has none.
     *
     * @param array<Stmt> $tree
     */
    public static function enclosing(Node $node, array $tree): self
    {
        $classLike = ScopeFinder::findEnclosingClassNode($node);
        $function = ScopeFinder::findEnclosingScope($node);
        if ($function !== null) {
            return self::forNode($function, $classLike);
        }
        return self::global(ScopeFinder::findEnclosingNamespace($node)->stmts ?? $tree, $classLike);
    }

    public static function forNode(
        Stmt\Function_|Stmt\ClassMethod|Closure|ArrowFunction $node,
        Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null $enclosingClassLike = null,
    ): self {
        $enclosingClassLike ??= ScopeFinder::findEnclosingClassNode($node);

        $selfContext = LateBindingKeyword::Self->resolveIn($enclosingClassLike);
        $parentContext = LateBindingKeyword::Parent->resolveIn($enclosingClassLike);

        $thisType = ($node instanceof Stmt\ClassMethod && $selfContext !== null)
            ? ClasslikeName::fromFullyQualified($selfContext)
            : null;

        // Arrow functions have an expression body, not a statement list; their
        // captured variables come from the enclosing scope, not local assignments.
        $statements = $node instanceof ArrowFunction ? [] : ($node->stmts ?? []);

        $uses = $node instanceof Closure ? $node->uses : [];

        /** @var ?class-string $selfContext */
        return new self(
            $node->params,
            $statements,
            $selfContext,
            $parentContext,
            $thisType,
            $uses,
            $enclosingClassLike,
            $node,
        );
    }

    /**
     * @param array<Stmt> $statements
     */
    public static function global(
        array $statements,
        Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null $enclosingClassLike = null,
    ): self {
        return new self([], $statements, null, null, null, [], $enclosingClassLike, null);
    }

    /**
     * @return array<Node\Param>
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
    public function getThisType(): ?ClasslikeName
    {
        return $this->thisType;
    }

    public function getEnclosingClassLike(): Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null
    {
        return $this->enclosingClassLike;
    }

    /**
     * The long-closure `use ($x)` clauses that bind names in this scope. A
     * closure body's `$x` resolves to its `use` clause; a name absent from the
     * use list is not bound in a long closure (#301 rule).
     *
     * @return array<Node\ClosureUse>
     */
    public function getUses(): array
    {
        return $this->uses;
    }

    /**
     * True when the scope inherits its enclosing scope's variable bindings
     * implicitly. Only arrow functions do this: `fn () => $x` reads $x from
     * the enclosing scope with no `use` clause.
     */
    public function allowsImplicitCapture(): bool
    {
        return $this->sourceNode instanceof ArrowFunction;
    }

    /**
     * The function-like node this scope was built from, or null for global
     * scope. Used to walk to the enclosing scope for arrow-function fall-through.
     */
    public function getSourceNode(): Stmt\Function_|Stmt\ClassMethod|Closure|ArrowFunction|null
    {
        return $this->sourceNode;
    }
}
