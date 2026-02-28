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
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\VerbosityLevel;

/**
 * Type inference service using PHPStan's reflection system.
 *
 * Combines AST-based variable tracking with PHPStan's ReflectionProvider
 * for accurate class, method, and property type resolution.
 */
final class PhpStanTypeInferenceService implements TypeInferenceInterface
{
    private ParserService $parser;
    private ?Container $container = null;
    private ?ReflectionProvider $reflectionProvider = null;
    private bool $containerInitAttempted = false;
    private string $tempDir;
    private string $projectRoot;

    /** @var array<string, VariableTypeCache> */
    private array $fileCache = [];

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? getcwd() ?: __DIR__;
        $this->tempDir = sys_get_temp_dir() . '/phpstan-lsp-' . md5($this->projectRoot);
        $this->parser = new ParserService();
    }

    public function getVariableType(TextDocument $document, string $variableName, int $line): ?string
    {
        $cache = $this->analyzeDocument($document);
        return $cache->getVariableTypeAtLine($variableName, $line);
    }

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
                $resolved = $expr->class->getAttribute('resolvedName');
                return $resolved instanceof Name ? $resolved->toString() : $expr->class->toString();
            }
        }

        // For variables, look up in cache
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return $this->getVariableType($document, $expr->name, $line);
        }

        return null;
    }

    public function getMethodReturnType(string $className, string $methodName): ?string
    {
        $this->ensureContainerInitialized();

        if ($this->reflectionProvider === null) {
            return null;
        }

        try {
            if (!$this->reflectionProvider->hasClass($className)) {
                return null;
            }

            $classReflection = $this->reflectionProvider->getClass($className);
            if (!$classReflection->hasNativeMethod($methodName)) {
                return null;
            }

            $method = $classReflection->getNativeMethod($methodName);
            $variants = $method->getVariants();

            if (empty($variants)) {
                return null;
            }

            $returnType = $variants[0]->getReturnType();
            $description = $returnType->describe(VerbosityLevel::typeOnly());

            if ($description === 'mixed') {
                return null;
            }

            return $description;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getPropertyType(string $className, string $propertyName): ?string
    {
        $this->ensureContainerInitialized();

        if ($this->reflectionProvider === null) {
            return null;
        }

        try {
            if (!$this->reflectionProvider->hasClass($className)) {
                return null;
            }

            $classReflection = $this->reflectionProvider->getClass($className);
            if (!$classReflection->hasNativeProperty($propertyName)) {
                return null;
            }

            $property = $classReflection->getNativeProperty($propertyName);
            $type = $property->getReadableType();
            $description = $type->describe(VerbosityLevel::typeOnly());

            if ($description === 'mixed') {
                return null;
            }

            return $description;
        } catch (\Throwable) {
            return null;
        }
    }

    public function hasClass(string $className): bool
    {
        $this->ensureContainerInitialized();

        if ($this->reflectionProvider === null) {
            return false;
        }

        try {
            return $this->reflectionProvider->hasClass($className);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the return type of a function.
     */
    public function getFunctionReturnType(string $functionName): ?string
    {
        $this->ensureContainerInitialized();

        if ($this->reflectionProvider === null) {
            return null;
        }

        try {
            $nameNode = new Name($functionName);
            if (!$this->reflectionProvider->hasFunction($nameNode, null)) {
                return null;
            }

            $function = $this->reflectionProvider->getFunction($nameNode, null);
            $variants = $function->getVariants();

            if (empty($variants)) {
                return null;
            }

            $returnType = $variants[0]->getReturnType();
            $description = $returnType->describe(VerbosityLevel::typeOnly());

            if ($description === 'mixed') {
                return null;
            }

            return $description;
        } catch (\Throwable) {
            return null;
        }
    }

    public function invalidate(string $uri): void
    {
        unset($this->fileCache[$uri]);
    }

    private function resolveExpressionType(TextDocument $document, Expr $expr, int $line): ?string
    {
        if ($expr instanceof Expr\Variable) {
            if ($expr->name === 'this') {
                return $this->findEnclosingClass($document, $line);
            }
            if (is_string($expr->name)) {
                return $this->getVariableType($document, $expr->name, $line);
            }
        }

        return $this->getExpressionType($document, $expr, $line);
    }

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
     * @param array<Stmt> $ast
     */
    private function extractVariableTypes(array $ast, VariableTypeCache $cache): void
    {
        $this->ensureContainerInitialized();

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

                // Track method/function parameters
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
                if ($type instanceof Node\UnionType) {
                    $types = [];
                    foreach ($type->types as $t) {
                        $resolved = $this->resolveTypeNode($t);
                        if ($resolved !== null) {
                            $types[] = $resolved;
                        }
                    }
                    return !empty($types) ? implode('|', $types) : null;
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

                // $obj->method() -> look up return type via PHPStan
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

                // Property fetch $obj->property
                if ($expr instanceof Expr\PropertyFetch) {
                    $objectType = $this->inferExpressionType($expr->var, $line);
                    if ($objectType !== null && $expr->name instanceof Identifier) {
                        return $this->service->getPropertyType($objectType, $expr->name->toString());
                    }
                }

                // Function call functionName()
                if ($expr instanceof Expr\FuncCall && $expr->name instanceof Name) {
                    $funcName = $expr->name->toString();
                    return $this->service->getFunctionReturnType($funcName);
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($extractor);
        $traverser->traverse($ast);
    }

    private function ensureContainerInitialized(): void
    {
        if ($this->container !== null || $this->containerInitAttempted) {
            return;
        }
        $this->containerInitAttempted = true;

        try {
            // Load the project's autoloader if available
            $projectAutoloader = $this->projectRoot . '/vendor/autoload.php';
            if (file_exists($projectAutoloader)) {
                require_once $projectAutoloader;
            }

            $containerFactory = new ContainerFactory($this->projectRoot);

            // Find phpstan.neon if it exists
            $configFiles = [];
            $phpstanNeon = $this->projectRoot . '/phpstan.neon';
            $phpstanNeonDist = $this->projectRoot . '/phpstan.neon.dist';
            if (file_exists($phpstanNeon)) {
                $configFiles[] = $phpstanNeon;
            } elseif (file_exists($phpstanNeonDist)) {
                $configFiles[] = $phpstanNeonDist;
            }

            $this->container = $containerFactory->create(
                $this->tempDir,
                $configFiles,
                [],
            );

            $this->reflectionProvider = $this->container->getByType(ReflectionProvider::class);
        } catch (\Throwable) {
            // PHPStan initialization failed, service will be degraded
            $this->container = null;
        }
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
