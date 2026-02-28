<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\TypeInference;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParserService;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ReflectionClass;
use ReflectionException;

/**
 * Type inference service combining AST analysis with reflection.
 *
 * Uses php-parser for AST analysis and PHP reflection for type lookups.
 * Handles common patterns for variable type inference without requiring
 * external tools or temp files.
 */
final class PhpStanTypeInferenceService implements TypeInferenceInterface
{
    private ParserService $parser;

    /** @var array<string, VariableTypeCache> */
    private array $fileCache = [];

    public function __construct()
    {
        $this->parser = new ParserService();
    }

    /**
     * Get the type of a variable at a specific line.
     *
     * @return string|null The type description, or null if not determinable
     */
    public function getVariableType(TextDocument $document, string $variableName, int $line): ?string
    {
        $cache = $this->analyzeDocument($document);
        return $cache->getVariableTypeAtLine($variableName, $line);
    }

    /**
     * Get the type of an expression.
     *
     * @return string|null The type description, or null if not determinable
     */
    public function getExpressionType(TextDocument $document, Expr $expr, int $line): ?string
    {
        // For method calls, resolve the object type and look up return type
        if ($expr instanceof Expr\MethodCall) {
            $objectType = $this->resolveExpressionType($document, $expr->var, $line);
            if ($objectType === null) {
                return null;
            }
            $methodName = $expr->name;
            if (!$methodName instanceof Identifier) {
                return null;
            }
            return $this->getMethodReturnType($objectType, $methodName->toString());
        }

        // For property fetches, resolve the object type and look up property type
        if ($expr instanceof Expr\PropertyFetch) {
            $objectType = $this->resolveExpressionType($document, $expr->var, $line);
            if ($objectType === null) {
                return null;
            }
            $propertyName = $expr->name;
            if (!$propertyName instanceof Identifier) {
                return null;
            }
            return $this->getPropertyType($objectType, $propertyName->toString());
        }

        // For new expressions, the type is the class name
        if ($expr instanceof Expr\New_) {
            if ($expr->class instanceof Name) {
                return $expr->class->toString();
            }
        }

        // For variables, look up in cache
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return $this->getVariableType($document, $expr->name, $line);
        }

        return null;
    }

    /**
     * Get the return type of a method.
     *
     * @param string $className The fully qualified class name
     * @param string $methodName The method name
     * @return string|null The return type, or null if not found
     */
    public function getMethodReturnType(string $className, string $methodName): ?string
    {
        try {
            if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
                return null;
            }

            $reflection = new ReflectionClass($className);
            if (!$reflection->hasMethod($methodName)) {
                return null;
            }

            $method = $reflection->getMethod($methodName);
            $returnType = $method->getReturnType();

            if ($returnType === null) {
                return null;
            }

            return $this->formatReflectionType($returnType);
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * Get the type of a property.
     *
     * @param string $className The fully qualified class name
     * @param string $propertyName The property name
     * @return string|null The property type, or null if not found
     */
    public function getPropertyType(string $className, string $propertyName): ?string
    {
        try {
            if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
                return null;
            }

            $reflection = new ReflectionClass($className);
            if (!$reflection->hasProperty($propertyName)) {
                return null;
            }

            $property = $reflection->getProperty($propertyName);
            $type = $property->getType();

            if ($type === null) {
                return null;
            }

            return $this->formatReflectionType($type);
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * Check if a class exists (via autoloading).
     */
    public function hasClass(string $className): bool
    {
        return class_exists($className) || interface_exists($className) || trait_exists($className);
    }

    /**
     * Invalidate cache for a document.
     */
    public function invalidate(string $uri): void
    {
        unset($this->fileCache[$uri]);
    }

    /**
     * Resolve the type of an expression (helper for chained lookups).
     */
    private function resolveExpressionType(TextDocument $document, Expr $expr, int $line): ?string
    {
        if ($expr instanceof Expr\Variable) {
            if ($expr->name === 'this') {
                // Find enclosing class from AST
                return $this->findEnclosingClass($document, $line);
            }
            if (is_string($expr->name)) {
                return $this->getVariableType($document, $expr->name, $line);
            }
        }

        return $this->getExpressionType($document, $expr, $line);
    }

    /**
     * Find the enclosing class at a given line.
     */
    private function findEnclosingClass(TextDocument $document, int $line): ?string
    {
        $ast = $this->parser->parse($document);
        if ($ast === null) {
            return null;
        }

        $finder = new class ($line) extends NodeVisitorAbstract {
            public ?string $className = null;
            private ?string $currentNamespace = null;

            public function __construct(private readonly int $line)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Namespace_) {
                    $this->currentNamespace = $node->name?->toString();
                }

                if ($node instanceof Stmt\Class_) {
                    $startLine = $node->getStartLine();
                    $endLine = $node->getEndLine();

                    if ($startLine <= $this->line && $this->line <= $endLine) {
                        $name = $node->name?->toString();
                        if ($name !== null) {
                            $this->className = $this->currentNamespace !== null
                                ? $this->currentNamespace . '\\' . $name
                                : $name;
                        }
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        return $finder->className;
    }

    /**
     * Analyze a document and cache variable type information.
     */
    private function analyzeDocument(TextDocument $document): VariableTypeCache
    {
        $uri = $document->uri;

        if (isset($this->fileCache[$uri])) {
            return $this->fileCache[$uri];
        }

        $ast = $this->parser->parse($document);
        if ($ast === null) {
            return $this->fileCache[$uri] = new VariableTypeCache();
        }

        $cache = new VariableTypeCache();
        $this->extractVariableTypes($ast, $cache);

        $this->fileCache[$uri] = $cache;
        return $cache;
    }

    /**
     * Extract variable type information from AST.
     *
     * @param array<Stmt> $ast
     */
    private function extractVariableTypes(array $ast, VariableTypeCache $cache): void
    {
        $extractor = new class ($cache, $this) extends NodeVisitorAbstract {
            private ?string $currentNamespace = null;
            private ?string $currentClass = null;

            public function __construct(
                private readonly VariableTypeCache $cache,
                private readonly PhpStanTypeInferenceService $service,
            ) {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Namespace_) {
                    $this->currentNamespace = $node->name?->toString();
                }

                if ($node instanceof Stmt\Class_) {
                    $name = $node->name?->toString();
                    if ($name !== null) {
                        $this->currentClass = $this->currentNamespace !== null
                            ? $this->currentNamespace . '\\' . $name
                            : $name;
                    }
                }

                // Track method parameters
                if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
                    foreach ($node->params as $param) {
                        $var = $param->var;
                        if ($var instanceof Expr\Variable && is_string($var->name) && $param->type !== null) {
                            $type = $this->resolveTypeNode($param->type);
                            if ($type !== null) {
                                $startLine = $node->getStartLine();
                                $endLine = $node->getEndLine();
                                $this->cache->addVariableType($var->name, $type, $startLine, $endLine);
                            }
                        }
                    }
                }

                // Track variable assignments
                if ($node instanceof Expr\Assign) {
                    $var = $node->var;
                    if ($var instanceof Expr\Variable && is_string($var->name)) {
                        $line = $node->getStartLine();
                        $type = $this->inferExpressionType($node->expr, $line);
                        if ($type !== null) {
                            // Variable is available from this line until end of scope
                            // For simplicity, use a large end line
                            $this->cache->addVariableType($var->name, $type, $line, PHP_INT_MAX);
                        }
                    }
                }

                return null;
            }

            public function leaveNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Class_) {
                    $this->currentClass = null;
                }
                if ($node instanceof Stmt\Namespace_) {
                    $this->currentNamespace = null;
                }
                return null;
            }

            private function resolveTypeNode(Node $type): ?string
            {
                if ($type instanceof Name) {
                    $resolved = $type->getAttribute('resolvedName');
                    return $resolved instanceof Name ? $resolved->toString() : $type->toString();
                }
                if ($type instanceof Identifier) {
                    return $type->toString();
                }
                if ($type instanceof Node\NullableType) {
                    $inner = $this->resolveTypeNode($type->type);
                    return $inner !== null ? '?' . $inner : null;
                }
                return null;
            }

            private function inferExpressionType(Expr $expr, int $line): ?string
            {
                // new ClassName() -> ClassName
                if ($expr instanceof Expr\New_) {
                    if ($expr->class instanceof Name) {
                        $resolved = $expr->class->getAttribute('resolvedName');
                        return $resolved instanceof Name ? $resolved->toString() : $expr->class->toString();
                    }
                }

                // $obj->method() -> look up return type
                if ($expr instanceof Expr\MethodCall) {
                    $objectType = $this->inferExpressionType($expr->var, $line);
                    if ($objectType !== null && $expr->name instanceof Identifier) {
                        return $this->service->getMethodReturnType($objectType, $expr->name->toString());
                    }
                }

                // $this -> current class
                if ($expr instanceof Expr\Variable && $expr->name === 'this') {
                    return $this->currentClass;
                }

                // Other variables -> look up in cache
                if ($expr instanceof Expr\Variable && is_string($expr->name)) {
                    return $this->cache->getVariableTypeAtLine($expr->name, $line);
                }

                // Static method call ClassName::method()
                if ($expr instanceof Expr\StaticCall) {
                    if ($expr->class instanceof Name && $expr->name instanceof Identifier) {
                        $className = $expr->class->getAttribute('resolvedName');
                        $className = $className instanceof Name ? $className->toString() : $expr->class->toString();
                        return $this->service->getMethodReturnType($className, $expr->name->toString());
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($extractor);
        $traverser->traverse($ast);
    }

    private function formatReflectionType(\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();
            return $type->allowsNull() && $name !== 'null' && $name !== 'mixed' ? '?' . $name : $name;
        }
        if ($type instanceof \ReflectionUnionType) {
            return implode('|', array_map(fn($t) => $this->formatReflectionType($t), $type->getTypes()));
        }
        if ($type instanceof \ReflectionIntersectionType) {
            return implode('&', array_map(fn($t) => $this->formatReflectionType($t), $type->getTypes()));
        }
        return (string) $type;
    }
}

/**
 * Cache for variable type information within a file.
 */
final class VariableTypeCache
{
    /** @var array<string, list<array{type: string, startLine: int, endLine: int}>> */
    private array $variableTypes = [];

    public function addVariableType(string $variableName, string $type, int $startLine, int $endLine): void
    {
        $this->variableTypes[$variableName][] = [
            'type' => $type,
            'startLine' => $startLine,
            'endLine' => $endLine,
        ];
    }

    public function getVariableTypeAtLine(string $variableName, int $line): ?string
    {
        if (!isset($this->variableTypes[$variableName])) {
            return null;
        }

        // Find the most recent assignment before or at this line
        $bestMatch = null;
        $bestStartLine = -1;

        foreach ($this->variableTypes[$variableName] as $entry) {
            if ($entry['startLine'] <= $line && $line <= $entry['endLine']) {
                if ($entry['startLine'] > $bestStartLine) {
                    $bestMatch = $entry['type'];
                    $bestStartLine = $entry['startLine'];
                }
            }
        }

        return $bestMatch;
    }
}
