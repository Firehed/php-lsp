<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\TypeInference;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Firehed\PhpLsp\Utility\ReflectionHelper;
use Firehed\PhpLsp\Utility\TypeFormatter;
use ReflectionNamedType;

/**
 * Basic type resolver using simple heuristics.
 *
 * Handles:
 * - Parameter types from function signatures
 * - `new ClassName()` expressions
 * - Property access on typed properties
 * - Method calls with declared return types
 * - Static method calls with declared return types
 */
final class BasicTypeResolver implements TypeResolverInterface
{
    public function resolveExpressionType(
        Expr $expr,
        Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction|null $scope,
        array $ast,
    ): ?string {
        // new ClassName()
        if ($expr instanceof Expr\New_) {
            if ($expr->class instanceof Node\Name) {
                return $expr->class->toString();
            }
            return null;
        }

        // $this - check before generic variable handling
        if ($expr instanceof Expr\Variable && $expr->name === 'this') {
            $className = $this->findEnclosingClassName($ast, $scope);
            return $className;
        }

        // Variable reference - delegate to resolveVariableType
        if ($expr instanceof Expr\Variable && is_string($expr->name) && $scope !== null) {
            return $this->resolveVariableType($expr->name, $scope, $expr->getStartLine() - 1, $ast);
        }

        // Method call: $obj->method()
        if ($expr instanceof Expr\MethodCall) {
            $objectType = $this->resolveExpressionType($expr->var, $scope, $ast);
            if ($objectType !== null && $expr->name instanceof Node\Identifier) {
                return $this->getMethodReturnType($objectType, $expr->name->toString());
            }
            return null;
        }

        // Static method call: ClassName::method()
        if ($expr instanceof Expr\StaticCall) {
            if ($expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier) {
                $className = $expr->class->toString();
                return $this->getMethodReturnType($className, $expr->name->toString());
            }
            return null;
        }

        // Property fetch: $obj->property
        if ($expr instanceof Expr\PropertyFetch) {
            $objectType = $this->resolveExpressionType($expr->var, $scope, $ast);
            if ($objectType !== null && $expr->name instanceof Node\Identifier) {
                return $this->getPropertyType($objectType, $expr->name->toString());
            }
            return null;
        }

        // Clone expression - same type as original
        if ($expr instanceof Expr\Clone_) {
            return $this->resolveExpressionType($expr->expr, $scope, $ast);
        }

        // Ternary/null coalesce - try to get type from one branch
        if ($expr instanceof Expr\Ternary) {
            return $this->resolveExpressionType($expr->if ?? $expr->cond, $scope, $ast)
                ?? $this->resolveExpressionType($expr->else, $scope, $ast);
        }

        if ($expr instanceof Expr\BinaryOp\Coalesce) {
            return $this->resolveExpressionType($expr->left, $scope, $ast)
                ?? $this->resolveExpressionType($expr->right, $scope, $ast);
        }

        return null;
    }

    public function resolveVariableType(
        string $variableName,
        Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction $scope,
        int $line,
        array $ast,
    ): ?string {
        // Check parameters first
        foreach ($scope->params as $param) {
            if (
                $param->var instanceof Expr\Variable
                && is_string($param->var->name)
                && $param->var->name === $variableName
            ) {
                return TypeFormatter::formatNode($param->type);
            }
        }

        // Check closure use() variables
        if ($scope instanceof Expr\Closure) {
            foreach ($scope->uses as $use) {
                if (is_string($use->var->name) && $use->var->name === $variableName) {
                    // Can't determine type of use() variables without more context
                    return null;
                }
            }
        }

        // Look for assignments before the current line
        $foundType = null;
        $stmts = $scope instanceof Expr\ArrowFunction ? [] : ($scope->stmts ?? []);

        $visitor = new class ($variableName, $line, $foundType, $this, $scope, $ast) extends NodeVisitorAbstract {
            public ?string $foundType = null;
            private string $variableName;
            private int $line;
            private BasicTypeResolver $resolver;
            /** @var Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction */
            private $scope;
            /** @var array<Stmt> */
            private array $ast;

            /**
             * @param Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction $scope
             * @param array<Stmt> $ast
             */
            public function __construct(
                string $variableName,
                int $line,
                ?string &$foundType,
                BasicTypeResolver $resolver,
                $scope,
                array $ast,
            ) {
                $this->variableName = $variableName;
                $this->line = $line;
                $this->foundType = &$foundType;
                $this->resolver = $resolver;
                $this->scope = $scope;
                $this->ast = $ast;
            }

            public function enterNode(Node $node): ?int
            {
                // Skip nested scopes
                if (
                    $node instanceof Stmt\Function_
                    || $node instanceof Stmt\ClassMethod
                    || $node instanceof Expr\Closure
                    || $node instanceof Expr\ArrowFunction
                ) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                // Look for assignments
                if ($node instanceof Expr\Assign) {
                    $nodeLine = $node->getStartLine() - 1; // Convert to 0-based
                    if (
                        $nodeLine <= $this->line
                        && $node->var instanceof Expr\Variable
                        && is_string($node->var->name)
                        && $node->var->name === $this->variableName
                    ) {
                        // Try to resolve the assigned expression's type
                        $type = $this->resolver->resolveExpressionType($node->expr, $this->scope, $this->ast);
                        if ($type !== null) {
                            $this->foundType = $type;
                        }
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->foundType;
    }

    private function getMethodReturnType(string $className, string $methodName): ?string
    {
        $reflection = ReflectionHelper::getClass($className);
        if ($reflection === null || !$reflection->hasMethod($methodName)) {
            return null;
        }
        $method = $reflection->getMethod($methodName);
        $returnType = $method->getReturnType();
        if (!$returnType instanceof ReflectionNamedType) {
            return null;
        }
        return $returnType->getName();
    }

    private function getPropertyType(string $className, string $propertyName): ?string
    {
        $reflection = ReflectionHelper::getClass($className);
        if ($reflection === null || !$reflection->hasProperty($propertyName)) {
            return null;
        }
        $property = $reflection->getProperty($propertyName);
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType) {
            return null;
        }
        return $type->getName();
    }

    /**
     * Find the class name that contains the given scope.
     *
     * @param array<Stmt> $ast
     */
    private function findEnclosingClassName(
        array $ast,
        Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction|null $scope,
    ): ?string {
        if ($scope === null) {
            return null;
        }

        $found = null;
        $visitor = new class ($scope, $found) extends NodeVisitorAbstract {
            public ?string $found = null;
            private ?string $currentClass = null;
            /** @var Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction */
            private $targetScope;

            /**
             * @param Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction $targetScope
             */
            public function __construct($targetScope, ?string &$found)
            {
                $this->targetScope = $targetScope;
                $this->found = &$found;
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Class_ && $node->name !== null) {
                    // namespacedName is only set when NameResolver visitor is used
                    // and may throw if accessed before initialization
                    try {
                        $nsName = $node->namespacedName;
                        $this->currentClass = $nsName !== null ? $nsName->toString() : $node->name->toString();
                    } catch (\Error) {
                        $this->currentClass = $node->name->toString();
                    }
                }

                if ($node === $this->targetScope && $this->currentClass !== null) {
                    $this->found = $this->currentClass;
                }

                return null;
            }

            public function leaveNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Class_) {
                    $this->currentClass = null;
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
