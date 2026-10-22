<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Name;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt;

/**
 * The lexical scope at a point in a document: the one answer to what encloses
 * it — namespace, class-like, and innermost function-like — and what follows
 * from that.
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
     * @param array<Stmt> $tree
     */
    private function __construct(
        private readonly ?Stmt\Namespace_ $namespace,
        private readonly Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null $classLike,
        private readonly Stmt\Function_|Stmt\ClassMethod|Closure|ArrowFunction|PropertyHook|null $sourceNode,
        private readonly array $tree,
    ) {
    }

    /**
     * The scope a node is in, the node itself included: a function-like's
     * header is in that function-like. With no node, the file's global scope.
     *
     * @param array<Stmt> $tree
     */
    public static function at(?Node $node, array $tree): self
    {
        $namespace = null;
        $classLike = null;
        $function = null;
        for ($n = $node; $n instanceof Node; $n = $n->getAttribute('parent')) {
            if ($n instanceof Stmt\Namespace_) {
                $namespace ??= $n;
            }
            if (
                $n instanceof Stmt\Class_ || $n instanceof Stmt\Interface_
                || $n instanceof Stmt\Trait_ || $n instanceof Stmt\Enum_
            ) {
                $classLike ??= $n;
            }
            if (
                $n instanceof Stmt\Function_ || $n instanceof Stmt\ClassMethod
                || $n instanceof Closure || $n instanceof ArrowFunction || $n instanceof PropertyHook
            ) {
                $function ??= $n;
            }
        }
        return new self($namespace, $classLike, $function, $tree);
    }

    /**
     * The scope holding this one's function-like, or null for a global scope.
     */
    public function outer(): ?self
    {
        if ($this->sourceNode === null) {
            return null;
        }
        $parent = $this->sourceNode->getAttribute('parent');
        return self::at($parent instanceof Node ? $parent : null, $this->tree);
    }

    /**
     * @return array<Node\Param>
     */
    public function getParams(): array
    {
        return $this->sourceNode->params ?? [];
    }

    /**
     * Statements that can introduce variables in this scope.
     *
     * @return array<Stmt>
     */
    public function getStatements(): array
    {
        return match (true) {
            $this->sourceNode === null => $this->namespace->stmts ?? $this->tree,
            // Arrow functions have an expression body, not a statement list; their
            // captured variables come from the enclosing scope, not local assignments.
            $this->sourceNode instanceof ArrowFunction => [],
            // A hook's body is a statement list, or a single expression (`get => …`).
            $this->sourceNode instanceof PropertyHook => is_array($this->sourceNode->body)
                ? $this->sourceNode->body
                : [],
            default => $this->sourceNode->stmts ?? [],
        };
    }

    /**
     * @return ?class-string
     */
    public function getSelfContext(): ?string
    {
        return LateBindingKeyword::Self->resolveIn($this->classLike);
    }

    /**
     * The class-like code here is written in, which `self` names.
     */
    public function getSelfName(): ?ClasslikeName
    {
        $self = $this->getSelfContext();
        return $self === null ? null : ClasslikeName::fromFullyQualified($self);
    }

    /**
     * @return ?class-string
     */
    public function getParentContext(): ?string
    {
        return LateBindingKeyword::Parent->resolveIn($this->classLike);
    }

    /**
     * The type of `$this` in this scope, or null where PHP does not bind it.
     * An instance method or a property hook binds it (hooks exist only on
     * instance properties); a closure or arrow function inherits it unless
     * declared `static` (PHP manual, Anonymous functions; Property hooks).
     */
    public function getThisType(): ?ClasslikeName
    {
        $function = $this->sourceNode;
        if ($function instanceof Closure || $function instanceof ArrowFunction) {
            return $function->static ? null : $this->outer()?->getThisType();
        }
        $bindsThis = $function instanceof PropertyHook
            || $function instanceof Stmt\ClassMethod && !$function->isStatic();
        return $bindsThis ? $this->getSelfName() : null;
    }

    /**
     * A class name as written here: `self`, `static`, and `parent` resolve
     * against the enclosing class-like, null where it gives them none.
     *
     * @return ?class-string
     */
    public function resolveClasslikeName(Name $name): ?string
    {
        $keyword = LateBindingKeyword::tryFromName($name->toString());
        if ($keyword !== null) {
            return $keyword->resolveIn($this->classLike);
        }
        /** @var class-string */
        return $name->toString();
    }

    /**
     * How a name written here resolves: the enclosing namespace and its imports.
     */
    public function nameContext(): NameContext
    {
        return NameContextFactory::in($this->namespace, $this->tree);
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
        return $this->sourceNode instanceof Closure ? $this->sourceNode->uses : [];
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
     * scope.
     */
    public function getSourceNode(): Stmt\Function_|Stmt\ClassMethod|Closure|ArrowFunction|PropertyHook|null
    {
        return $this->sourceNode;
    }
}
