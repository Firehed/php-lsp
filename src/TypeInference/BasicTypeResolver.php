<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\TypeInference;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Utility\ScopeFinder;
use Firehed\PhpLsp\Utility\TypeFactory;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

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
    public function __construct(
        private readonly MemberResolver $memberResolver,
    ) {
    }
    public function resolveExpressionType(
        Expr $expr,
        Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction|null $scope,
        array $ast,
    ): ?Type {
        // new ClassName()
        if ($expr instanceof Expr\New_) {
            if ($expr->class instanceof Node\Name) {
                $name = $expr->class->toString();
                $fqn = match ($name) {
                    'self', 'static' => ScopeFinder::findEnclosingClassName($expr),
                    'parent' => ($classNode = ScopeFinder::findEnclosingClassNode($expr)) instanceof Stmt\Class_
                        ? ScopeFinder::resolveExtendsName($classNode)
                        : null,
                    default => $name,
                };
                if ($fqn === null) {
                    return null;
                }
                /** @var class-string $fqn */
                return new ClassName($fqn);
            }
            return null;
        }

        // $this - check before generic variable handling
        if ($expr instanceof Expr\Variable && $expr->name === 'this') {
            $className = ScopeFinder::findEnclosingClassName($expr);
            if ($className === null) {
                return null;
            }
            /** @var class-string $className */
            return new ClassName($className);
        }

        // Variable reference - delegate to resolveVariableType
        if ($expr instanceof Expr\Variable && is_string($expr->name) && $scope !== null) {
            return $this->resolveVariableType($expr->name, $scope, $expr->getStartLine() - 1, $ast);
        }

        // Method call: $obj->method() or $obj?->method()
        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) {
            $objectType = $this->resolveExpressionType($expr->var, $scope, $ast);
            $className = $this->extractClassName($objectType);
            if ($className !== null && $expr->name instanceof Node\Identifier) {
                return $this->getMethodReturnType($className, $expr->name->toString());
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

        // Function call: functionName()
        if ($expr instanceof Expr\FuncCall) {
            if ($expr->name instanceof Node\Name) {
                return $this->getFunctionReturnType($expr->name->toString(), $ast);
            }
            return null;
        }

        // Property fetch: $obj->property or $obj?->property
        if ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch) {
            $objectType = $this->resolveExpressionType($expr->var, $scope, $ast);
            $className = $this->extractClassName($objectType);
            if ($className !== null && $expr->name instanceof Node\Identifier) {
                return $this->getPropertyType($className, $expr->name->toString());
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
    ): ?Type {
        // Determine class context for self/static/parent resolution
        $selfContext = ScopeFinder::findEnclosingClassName($scope);
        $parentContext = null;
        if ($selfContext !== null) {
            $classNode = ScopeFinder::findEnclosingClassNode($scope);
            if ($classNode instanceof Stmt\Class_) {
                $parentContext = ScopeFinder::resolveExtendsName($classNode);
            }
        }

        // Check parameters first
        foreach ($scope->params as $param) {
            if (
                $param->var instanceof Expr\Variable
                && is_string($param->var->name)
                && $param->var->name === $variableName
            ) {
                return TypeFactory::fromNode($param->type, $selfContext, $parentContext);
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
            public ?Type $foundType = null;
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
                ?Type &$foundType,
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

    private function getMethodReturnType(string $className, string $methodName): ?Type
    {
        /** @var class-string $className */
        $methodInfo = $this->memberResolver->findMethod(
            new ClassName($className),
            new MethodName($methodName),
            Visibility::Private,
        );

        return $methodInfo?->returnType;
    }

    private function getPropertyType(string $className, string $propertyName): ?Type
    {
        /** @var class-string $className */
        $propertyInfo = $this->memberResolver->findProperty(
            new ClassName($className),
            new PropertyName($propertyName),
            Visibility::Private,
        );

        return $propertyInfo?->type;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getFunctionReturnType(string $functionName, array $ast): ?Type
    {
        $func = $this->findFunctionInAst($functionName, $ast);
        if ($func !== null) {
            return TypeFactory::fromNode($func->returnType);
        }

        // Try reflection for built-in functions
        try {
            $reflection = new \ReflectionFunction($functionName);
            return TypeFactory::fromReflection($reflection->getReturnType());
        } catch (\ReflectionException) {
            return null;
        }
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findFunctionInAst(string $functionName, array $ast): ?Stmt\Function_
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Function_ && $stmt->name->toString() === $functionName) {
                return $stmt;
            }
            if ($stmt instanceof Stmt\Namespace_) {
                foreach ($stmt->stmts as $nsStmt) {
                    if ($nsStmt instanceof Stmt\Function_ && $nsStmt->name->toString() === $functionName) {
                        return $nsStmt;
                    }
                }
            }
        }
        return null;
    }

    private function extractClassName(?Type $type): ?string
    {
        if ($type === null) {
            return null;
        }
        $classNames = $type->getResolvableClassNames();
        return $classNames !== [] ? $classNames[0]->fqn : null;
    }
}
