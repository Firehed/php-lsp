<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

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
     * The bindings visible strictly before $offset, in source order.
     *
     * @return list<VariableBinding>
     */
    public static function before(Scope $scope, int $offset): array
    {
        $bindings = [];

        foreach ($scope->getParams() as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $bindings[] = new VariableBinding($param->var->name, $param->var);
            }
        }

        foreach ($scope->getUses() as $use) {
            if (is_string($use->var->name)) {
                $bindings[] = new VariableBinding($use->var->name, $use->var);
            }
        }

        self::walk($scope->getStatements(), $offset, $bindings);

        return $bindings;
    }

    /**
     * @param array<Node> $stmts
     * @param list<VariableBinding> $bindings
     */
    private static function walk(array $stmts, int $offset, array &$bindings): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt->getStartFilePos() >= $offset) {
                continue;
            }
            if ($stmt instanceof Stmt\Function_ || $stmt instanceof Stmt\ClassLike) {
                continue;
            }

            if ($stmt instanceof Stmt\Expression && $stmt->expr instanceof Assign) {
                self::collectAssignTargets($stmt->expr, $bindings);
            }

            if ($stmt instanceof Stmt\Foreach_) {
                if ($stmt->keyVar instanceof Variable && is_string($stmt->keyVar->name)) {
                    $bindings[] = new VariableBinding($stmt->keyVar->name, $stmt->keyVar);
                }
                if ($stmt->valueVar instanceof Variable && is_string($stmt->valueVar->name)) {
                    $bindings[] = new VariableBinding($stmt->valueVar->name, $stmt->valueVar);
                }
            }

            if ($stmt instanceof Stmt\TryCatch) {
                self::walk($stmt->stmts, $offset, $bindings);
                foreach ($stmt->catches as $catch) {
                    if ($catch->var !== null && is_string($catch->var->name)) {
                        $bindings[] = new VariableBinding($catch->var->name, $catch->var);
                    }
                    self::walk($catch->stmts, $offset, $bindings);
                }
                if ($stmt->finally !== null) {
                    self::walk($stmt->finally->stmts, $offset, $bindings);
                }
                continue;
            }

            if ($stmt instanceof Stmt\If_) {
                self::walk($stmt->stmts, $offset, $bindings);
                foreach ($stmt->elseifs as $elseif) {
                    self::walk($elseif->stmts, $offset, $bindings);
                }
                if ($stmt->else !== null) {
                    self::walk($stmt->else->stmts, $offset, $bindings);
                }
                continue;
            }

            if ($stmt instanceof Stmt\Switch_) {
                foreach ($stmt->cases as $case) {
                    self::walk($case->stmts, $offset, $bindings);
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
                self::walk($stmt->stmts, $offset, $bindings);
            }
        }
    }

    /**
     * @param list<VariableBinding> $bindings
     */
    private static function collectAssignTargets(Assign $assign, array &$bindings): void
    {
        if ($assign->var instanceof Variable && is_string($assign->var->name)) {
            $bindings[] = new VariableBinding($assign->var->name, $assign->var);
        }
    }
}
