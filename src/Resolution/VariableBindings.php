<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeType;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;

/**
 * The one place variable bindings visible in a scope are enumerated.
 *
 * A binding code writes is a parameter, a closure `use` clause, an assignment
 * target, a foreach key/value, or a catch variable, listed in source order —
 * the nearest binding is last. Nested function-like bodies are not entered;
 * control-flow bodies (if/while/for/foreach/try...) are. What a name refers to
 * is decided in {@see self::visible()}, `$this` included.
 */
final class VariableBindings
{
    /**
     * The binding each name visible at $offset refers to, by name: `$this`
     * where the scope binds it, then the nearest binding of each other name.
     * An arrow function also sees what the scope around it binds before it
     * (implicit capture); a binding in an inner scope shadows one in an outer.
     *
     * @return array<string, VariableBinding>
     */
    public static function visible(Scope $scope, int $offset): array
    {
        $innermost = $scope;
        $visible = [];
        while (true) {
            $nearest = [];
            foreach (self::before($scope, $offset) as $binding) {
                $nearest[$binding->name] = $binding;
            }
            $visible += $nearest;
            if (!$scope->allowsImplicitCapture()) {
                break;
            }
            $sourceNode = $scope->getSourceNode();
            $outer = $scope->outer();
            assert($sourceNode !== null && $outer !== null, 'allowsImplicitCapture() implies an arrow function');
            $scope = $outer;
            $offset = $sourceNode->getStartFilePos();
        }

        // `$this` is what PHP binds, whatever code writes.
        unset($visible['this']);
        $thisClass = $innermost->getThisType();
        return $thisClass === null
            ? $visible
            : ['this' => VariableBinding::implicit('this', new ClasslikeType($thisClass), $innermost)] + $visible;
    }

    /**
     * The bindings code writes strictly before $offset, in source order: what
     * the code says, `$this` included, not what a name refers to.
     *
     * @return list<VariableBinding>
     */
    public static function before(Scope $scope, int $offset): array
    {
        $bindings = [];

        foreach ($scope->getParams() as $param) {
            self::bind($param->var, $scope, $bindings);
        }

        foreach ($scope->getUses() as $use) {
            self::bind($use->var, $scope, $bindings);
        }

        self::walk($scope->getStatements(), $offset, $scope, $bindings);

        return $bindings;
    }

    /**
     * @param array<Node> $stmts
     * @param list<VariableBinding> $bindings
     */
    private static function walk(array $stmts, int $offset, Scope $scope, array &$bindings): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt->getStartFilePos() >= $offset) {
                continue;
            }
            if ($stmt instanceof Stmt\Function_ || $stmt instanceof Stmt\ClassLike) {
                continue;
            }

            if ($stmt instanceof Stmt\Expression && $stmt->expr instanceof Assign) {
                self::bindOnceRun($stmt->expr->var, $stmt->expr, $offset, $scope, $bindings);
            }

            if ($stmt instanceof Stmt\Foreach_) {
                self::bindOnceRun($stmt->keyVar, $stmt->expr, $offset, $scope, $bindings);
                self::bindOnceRun($stmt->valueVar, $stmt->expr, $offset, $scope, $bindings);
            }

            if ($stmt instanceof Stmt\TryCatch) {
                self::walk($stmt->stmts, $offset, $scope, $bindings);
                foreach ($stmt->catches as $catch) {
                    if ($catch->var !== null) {
                        self::bindOnceRun($catch->var, $catch->var, $offset, $scope, $bindings);
                    }
                    self::walk($catch->stmts, $offset, $scope, $bindings);
                }
                if ($stmt->finally !== null) {
                    self::walk($stmt->finally->stmts, $offset, $scope, $bindings);
                }
                continue;
            }

            if ($stmt instanceof Stmt\If_) {
                self::walk($stmt->stmts, $offset, $scope, $bindings);
                foreach ($stmt->elseifs as $elseif) {
                    self::walk($elseif->stmts, $offset, $scope, $bindings);
                }
                if ($stmt->else !== null) {
                    self::walk($stmt->else->stmts, $offset, $scope, $bindings);
                }
                continue;
            }

            if ($stmt instanceof Stmt\Switch_) {
                foreach ($stmt->cases as $case) {
                    self::walk($case->stmts, $offset, $scope, $bindings);
                }
                continue;
            }

            if (
                $stmt instanceof Stmt\While_
                || $stmt instanceof Stmt\Do_
                || $stmt instanceof Stmt\For_
                || $stmt instanceof Stmt\Foreach_
                || $stmt instanceof Stmt\Namespace_
            ) {
                self::walk($stmt->stmts, $offset, $scope, $bindings);
            }
        }
    }

    /**
     * Records $target once the code PHP runs before assigning it, which ends
     * with $runFirst, is complete before $offset. That code may read an
     * earlier binding of the same name.
     *
     * @param list<VariableBinding> $bindings
     */
    private static function bindOnceRun(
        ?Node $target,
        Node $runFirst,
        int $offset,
        Scope $scope,
        array &$bindings,
    ): void {
        if ($runFirst->getEndFilePos() < $offset) {
            self::bind($target, $scope, $bindings);
        }
    }

    /**
     * Records $target when it names a variable.
     *
     * @param list<VariableBinding> $bindings
     */
    private static function bind(?Node $target, Scope $scope, array &$bindings): void
    {
        if ($target instanceof Variable && is_string($target->name)) {
            $bindings[] = VariableBinding::written($target->name, $target, $scope);
        }
    }
}
