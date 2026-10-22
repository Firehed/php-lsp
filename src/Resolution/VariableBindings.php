<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;

/**
 * The one place variable bindings visible in a scope are enumerated.
 *
 * A binding is a parameter, a closure `use` clause, an assignment target, a
 * foreach key/value, or a catch variable. The list is returned in source
 * order — the nearest binding is last. Nested function-like bodies are not
 * entered; control-flow bodies (if/while/for/foreach/try...) are.
 */
final class VariableBindings
{
    /**
     * The nearest binding of each name visible at $offset, by name. An arrow
     * function also sees what the scope around it binds before it (implicit
     * capture); a binding in an inner scope shadows one in an outer.
     *
     * @return array<string, VariableBinding>
     */
    public static function visible(Scope $scope, int $offset): array
    {
        $visible = [];
        while (true) {
            $nearest = [];
            foreach (self::before($scope, $offset) as $binding) {
                $nearest[$binding->name] = $binding;
            }
            $visible += $nearest;
            if (!$scope->allowsImplicitCapture()) {
                return $visible;
            }
            $sourceNode = $scope->getSourceNode();
            $outer = $scope->outer();
            assert($sourceNode !== null && $outer !== null, 'allowsImplicitCapture() implies an arrow function');
            $scope = $outer;
            $offset = $sourceNode->getStartFilePos();
        }
    }

    /**
     * The bindings visible strictly before $offset, in source order.
     *
     * @return list<VariableBinding>
     */
    public static function before(Scope $scope, int $offset): array
    {
        $bindings = [];

        foreach ($scope->getParams() as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $bindings[] = new VariableBinding($param->var->name, $param->var, $scope);
            }
        }

        foreach ($scope->getUses() as $use) {
            if (is_string($use->var->name)) {
                $bindings[] = new VariableBinding($use->var->name, $use->var, $scope);
            }
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
                self::collectAssignTargets($stmt->expr, $scope, $bindings);
            }

            if ($stmt instanceof Stmt\Foreach_) {
                if ($stmt->keyVar instanceof Variable && is_string($stmt->keyVar->name)) {
                    $bindings[] = new VariableBinding($stmt->keyVar->name, $stmt->keyVar, $scope);
                }
                if ($stmt->valueVar instanceof Variable && is_string($stmt->valueVar->name)) {
                    $bindings[] = new VariableBinding($stmt->valueVar->name, $stmt->valueVar, $scope);
                }
            }

            if ($stmt instanceof Stmt\TryCatch) {
                self::walk($stmt->stmts, $offset, $scope, $bindings);
                foreach ($stmt->catches as $catch) {
                    if ($catch->var !== null && is_string($catch->var->name)) {
                        $bindings[] = new VariableBinding($catch->var->name, $catch->var, $scope);
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
     * @param list<VariableBinding> $bindings
     */
    private static function collectAssignTargets(Assign $assign, Scope $scope, array &$bindings): void
    {
        if ($assign->var instanceof Variable && is_string($assign->var->name)) {
            $bindings[] = new VariableBinding($assign->var->name, $assign->var, $scope);
        }
    }
}
