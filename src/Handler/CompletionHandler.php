<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Completion\ContextDetector;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Utility\DocblockParser;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;

final class CompletionHandler implements HandlerInterface
{
    // LSP CompletionItemKind constants
    private const KIND_METHOD = 2;
    private const KIND_FUNCTION = 3;
    private const KIND_CLASS = 7;
    private const KIND_PROPERTY = 10;
    private const KIND_CONSTANT = 21;

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ParserService $parser,
        private readonly ?ComposerClassLocator $classLocator,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/completion';
    }

    /**
     * @return array{isIncomplete: bool, items: list<array{label: string, kind?: int, detail?: string, documentation?: string}>}|null
     */
    public function handle(Message $message): ?array
    {
        $params = $message->params ?? [];

        $textDocument = $params['textDocument'] ?? [];
        if (!is_array($textDocument)) {
            return null;
        }
        $uri = $textDocument['uri'] ?? '';
        if (!is_string($uri)) {
            return null;
        }

        $position = $params['position'] ?? [];
        if (!is_array($position)) {
            return null;
        }
        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;
        if (!is_int($line) || !is_int($character)) {
            return null;
        }

        $document = $this->documentManager->get($uri);
        if ($document === null) {
            return null;
        }

        // Skip completions inside comments, strings, heredocs
        $offset = $document->offsetAt($line, $character);
        if (!ContextDetector::isCompletable($document->getContent(), $offset)) {
            return [
                'isIncomplete' => false,
                'items' => [],
            ];
        }

        $ast = $this->parser->parse($document);
        if ($ast === null) {
            return null;
        }

        // Get text before cursor to determine completion context
        $lineText = $document->getLine($line);
        $textBeforeCursor = substr($lineText, 0, $character);

        $items = $this->getCompletionItems($textBeforeCursor, $ast, $document);

        return [
            'isIncomplete' => false,
            'items' => $items,
        ];
    }

    /**
     * @param array<Stmt> $ast
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getCompletionItems(string $textBeforeCursor, array $ast, TextDocument $document): array
    {
        // $this-> completion
        if (preg_match('/\$this->(\w*)$/', $textBeforeCursor, $matches)) {
            $prefix = $matches[1];
            return $this->getThisMemberCompletions($prefix, $ast);
        }

        // ClassName:: completion (static) - also match single : for mid-typing
        if (preg_match('/([A-Z]\w*)::?(\w*)$/', $textBeforeCursor, $matches)) {
            $className = $matches[1];
            $prefix = $matches[2];
            return $this->getStaticCompletions($className, $prefix, $ast, $document);
        }

        // new ClassName completion - only suggest imported classes
        if (preg_match('/new\s+(\w*)$/', $textBeforeCursor, $matches)) {
            $prefix = $matches[1];
            return $this->getImportedClassCompletions($prefix, $ast);
        }

        // Function/class completion (at start of expression or after operators)
        if (preg_match('/(?:^|[(\s=,!&|])(\w+)$/', $textBeforeCursor, $matches)) {
            $prefix = $matches[1];
            $items = $this->getFunctionCompletions($prefix, $ast);
            $items = array_merge($items, $this->getImportedClassCompletions($prefix, $ast));
            return $items;
        }

        return [];
    }

    /**
     * @param array<Stmt> $ast
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getThisMemberCompletions(string $prefix, array $ast): array
    {
        // Find the enclosing class
        $classNode = $this->findFirstClass($ast);
        if ($classNode === null) {
            return [];
        }

        $items = [];

        foreach ($classNode->stmts as $stmt) {
            // Methods
            if ($stmt instanceof Stmt\ClassMethod) {
                $name = $stmt->name->toString();
                if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                    $items[] = $this->formatMethodCompletion($stmt);
                }
            }

            // Properties
            if ($stmt instanceof Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $name = $prop->name->toString();
                    if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                        $items[] = $this->formatPropertyCompletion($stmt, $name);
                    }
                }
            }
        }

        // Also include inherited members via reflection if class exists
        $className = $classNode->namespacedName?->toString() ?? $classNode->name?->toString();
        if ($className !== null) {
            $items = array_merge($items, $this->getInheritedMemberCompletions($className, $prefix, $items));
        }

        return $items;
    }

    /**
     * @param array<Stmt> $ast
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getStaticCompletions(string $className, string $prefix, array $ast, TextDocument $document): array
    {
        $items = [];

        // Resolve short name to FQCN using imports
        $resolvedClassName = $this->resolveClassName($className, $ast);

        // Try to find class in AST first
        $classNode = $this->findClassInAst($resolvedClassName, $ast);

        // If not in current file, try Composer
        if ($classNode === null && $this->classLocator !== null) {
            $filePath = $this->classLocator->locateClass($resolvedClassName);
            if ($filePath !== null) {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $externalDoc = new TextDocument('file://' . $filePath, 'php', 0, $content);
                    $externalAst = $this->parser->parse($externalDoc);
                    if ($externalAst !== null) {
                        $classNode = $this->findClassInAst($resolvedClassName, $externalAst);
                    }
                }
            }
        }

        if ($classNode !== null) {
            foreach ($classNode->stmts as $stmt) {
                // Static methods
                if ($stmt instanceof Stmt\ClassMethod && $stmt->isStatic()) {
                    $name = $stmt->name->toString();
                    if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                        $items[] = $this->formatMethodCompletion($stmt);
                    }
                }

                // Static properties
                if ($stmt instanceof Stmt\Property && $stmt->isStatic()) {
                    foreach ($stmt->props as $prop) {
                        $name = $prop->name->toString();
                        if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                            $items[] = $this->formatPropertyCompletion($stmt, $name);
                        }
                    }
                }

                // Constants
                if ($stmt instanceof Stmt\ClassConst) {
                    foreach ($stmt->consts as $const) {
                        $name = $const->name->toString();
                        if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                            $items[] = $this->formatConstantCompletion($stmt, $name);
                        }
                    }
                }
            }
        }

        // Also try reflection for inherited/built-in
        $items = array_merge($items, $this->getReflectionStaticCompletions($resolvedClassName, $prefix, $items));

        // Always offer ::class magic constant
        if ($prefix === '' || str_starts_with('class', strtolower($prefix))) {
            $items[] = [
                'label' => 'class',
                'kind' => self::KIND_CONSTANT,
                'detail' => 'string (fully qualified class name)',
            ];
        }

        return $items;
    }

    /**
     * @param array<Stmt> $ast
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getFunctionCompletions(string $prefix, array $ast): array
    {
        $items = [];

        // User-defined functions in current file
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Function_) {
                $name = $stmt->name->toString();
                if (str_starts_with(strtolower($name), strtolower($prefix))) {
                    $items[] = $this->formatFunctionCompletion($stmt);
                }
            }
        }

        // Built-in functions
        $definedFunctions = get_defined_functions();
        foreach ($definedFunctions['internal'] as $name) {
            if (str_starts_with(strtolower($name), strtolower($prefix))) {
                $items[] = [
                    'label' => $name,
                    'kind' => self::KIND_FUNCTION,
                ];
            }
        }

        // Limit results
        return array_slice($items, 0, 100);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findFirstClass(array $ast): ?Stmt\Class_
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                foreach ($stmt->stmts as $nsStmt) {
                    if ($nsStmt instanceof Stmt\Class_) {
                        return $nsStmt;
                    }
                }
            }
            if ($stmt instanceof Stmt\Class_) {
                return $stmt;
            }
        }
        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findClassInAst(string $className, array $ast): Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null
    {
        $finder = new class ($className) extends NodeVisitorAbstract {
            public Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null $found = null;
            private string $namespace = '';

            public function __construct(private readonly string $className)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString() ?? '';
                    return null;
                }

                if ($node instanceof Stmt\Class_
                    || $node instanceof Stmt\Interface_
                    || $node instanceof Stmt\Trait_
                    || $node instanceof Stmt\Enum_
                ) {
                    $name = $node->name?->toString();
                    if ($name === null) {
                        return null;
                    }
                    $fqn = $this->namespace !== '' ? $this->namespace . '\\' . $name : $name;

                    if ($fqn === $this->className || $name === $this->className) {
                        $this->found = $node;
                        return NodeTraverser::STOP_TRAVERSAL;
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        return $finder->found;
    }

    /**
     * @param list<array{label: string, kind?: int, detail?: string, documentation?: string}> $existingItems
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getInheritedMemberCompletions(string $className, string $prefix, array $existingItems): array
    {
        $existingLabels = array_column($existingItems, 'label');
        $items = [];

        try {
            if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
                return [];
            }

            $reflection = new ReflectionClass($className);

            // Methods from parent classes
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
                $name = $method->getName();
                if (in_array($name, $existingLabels, true)) {
                    continue;
                }
                if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                    $items[] = $this->formatReflectionMethodCompletion($method);
                }
            }

            // Properties from parent classes
            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $prop) {
                $name = $prop->getName();
                if (in_array($name, $existingLabels, true)) {
                    continue;
                }
                if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                    $items[] = $this->formatReflectionPropertyCompletion($prop);
                }
            }
        } catch (ReflectionException) {
            // Class not autoloadable
        }

        return $items;
    }

    /**
     * @param list<array{label: string, kind?: int, detail?: string, documentation?: string}> $existingItems
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getReflectionStaticCompletions(string $className, string $prefix, array $existingItems): array
    {
        $existingLabels = array_column($existingItems, 'label');
        $items = [];

        try {
            if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
                return [];
            }

            $reflection = new ReflectionClass($className);

            // Static methods
            foreach ($reflection->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$method->isStatic()) {
                    continue;
                }
                $name = $method->getName();
                if (in_array($name, $existingLabels, true)) {
                    continue;
                }
                if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                    $items[] = $this->formatReflectionMethodCompletion($method);
                }
            }

            // Constants
            foreach ($reflection->getReflectionConstants() as $const) {
                $name = $const->getName();
                if (in_array($name, $existingLabels, true)) {
                    continue;
                }
                if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                    $items[] = [
                        'label' => $name,
                        'kind' => self::KIND_CONSTANT,
                        'detail' => 'const ' . $name,
                    ];
                }
            }
        } catch (ReflectionException) {
            // Class not autoloadable
        }

        return $items;
    }

    /**
     * @return array{label: string, kind: int, detail?: string, documentation?: string}
     */
    private function formatMethodCompletion(Stmt\ClassMethod $method): array
    {
        $params = [];
        foreach ($method->params as $param) {
            $paramStr = '';
            if ($param->type !== null) {
                $paramStr .= $this->formatType($param->type) . ' ';
            }
            $var = $param->var;
            if ($var instanceof Variable && is_string($var->name)) {
                $paramStr .= '$' . $var->name;
            }
            $params[] = $paramStr;
        }

        $detail = $method->name->toString() . '(' . implode(', ', $params) . ')';
        if ($method->returnType !== null) {
            $detail .= ': ' . $this->formatType($method->returnType);
        }

        $item = [
            'label' => $method->name->toString(),
            'kind' => self::KIND_METHOD,
            'detail' => $detail,
        ];

        $docComment = $method->getDocComment();
        if ($docComment !== null) {
            $doc = DocblockParser::extractDescription($docComment->getText());
            if ($doc !== '') {
                $item['documentation'] = $doc;
            }
        }

        return $item;
    }

    /**
     * @return array{label: string, kind: int, detail?: string, documentation?: string}
     */
    private function formatPropertyCompletion(Stmt\Property $property, string $name): array
    {
        $type = $property->type !== null ? $this->formatType($property->type) : 'mixed';

        $item = [
            'label' => $name,
            'kind' => self::KIND_PROPERTY,
            'detail' => $type . ' $' . $name,
        ];

        $docComment = $property->getDocComment();
        if ($docComment !== null) {
            $doc = DocblockParser::extractDescription($docComment->getText());
            if ($doc !== '') {
                $item['documentation'] = $doc;
            }
        }

        return $item;
    }

    /**
     * @return array{label: string, kind: int, detail?: string, documentation?: string}
     */
    private function formatConstantCompletion(Stmt\ClassConst $const, string $name): array
    {
        $item = [
            'label' => $name,
            'kind' => self::KIND_CONSTANT,
            'detail' => 'const ' . $name,
        ];

        $docComment = $const->getDocComment();
        if ($docComment !== null) {
            $doc = DocblockParser::extractDescription($docComment->getText());
            if ($doc !== '') {
                $item['documentation'] = $doc;
            }
        }

        return $item;
    }

    /**
     * @return array{label: string, kind: int, detail?: string, documentation?: string}
     */
    private function formatFunctionCompletion(Stmt\Function_ $func): array
    {
        $params = [];
        foreach ($func->params as $param) {
            $paramStr = '';
            if ($param->type !== null) {
                $paramStr .= $this->formatType($param->type) . ' ';
            }
            $var = $param->var;
            if ($var instanceof Variable && is_string($var->name)) {
                $paramStr .= '$' . $var->name;
            }
            $params[] = $paramStr;
        }

        $detail = 'function ' . $func->name->toString() . '(' . implode(', ', $params) . ')';
        if ($func->returnType !== null) {
            $detail .= ': ' . $this->formatType($func->returnType);
        }

        $item = [
            'label' => $func->name->toString(),
            'kind' => self::KIND_FUNCTION,
            'detail' => $detail,
        ];

        $docComment = $func->getDocComment();
        if ($docComment !== null) {
            $doc = DocblockParser::extractDescription($docComment->getText());
            if ($doc !== '') {
                $item['documentation'] = $doc;
            }
        }

        return $item;
    }

    /**
     * @return array{label: string, kind: int, detail?: string, documentation?: string}
     */
    private function formatReflectionMethodCompletion(ReflectionMethod $method): array
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $paramStr = '';
            $type = $param->getType();
            if ($type !== null) {
                $paramStr .= $this->formatReflectionType($type) . ' ';
            }
            $paramStr .= '$' . $param->getName();
            $params[] = $paramStr;
        }

        $detail = $method->getName() . '(' . implode(', ', $params) . ')';
        $returnType = $method->getReturnType();
        if ($returnType !== null) {
            $detail .= ': ' . $this->formatReflectionType($returnType);
        }

        $item = [
            'label' => $method->getName(),
            'kind' => self::KIND_METHOD,
            'detail' => $detail,
        ];

        $docComment = $method->getDocComment();
        if ($docComment !== false) {
            $doc = DocblockParser::extractDescription($docComment);
            if ($doc !== '') {
                $item['documentation'] = $doc;
            }
        }

        return $item;
    }

    /**
     * @return array{label: string, kind: int, detail?: string, documentation?: string}
     */
    private function formatReflectionPropertyCompletion(ReflectionProperty $prop): array
    {
        $type = $prop->getType();
        $typeStr = $type !== null ? $this->formatReflectionType($type) : 'mixed';

        $item = [
            'label' => $prop->getName(),
            'kind' => self::KIND_PROPERTY,
            'detail' => $typeStr . ' $' . $prop->getName(),
        ];

        $docComment = $prop->getDocComment();
        if ($docComment !== false) {
            $doc = DocblockParser::extractDescription($docComment);
            if ($doc !== '') {
                $item['documentation'] = $doc;
            }
        }

        return $item;
    }

    private function formatType(Node $type): string
    {
        if ($type instanceof Name) {
            return $type->toString();
        }
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?' . $this->formatType($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn($t) => $this->formatType($t), $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(fn($t) => $this->formatType($t), $type->types));
        }
        return '';
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

    /**
     * Resolve a short class name to its FQCN using use statements.
     *
     * @param array<Stmt> $ast
     */
    private function resolveClassName(string $shortName, array $ast): string
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                foreach ($stmt->stmts as $nsStmt) {
                    $resolved = $this->checkUseStatement($nsStmt, $shortName);
                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
            } else {
                $resolved = $this->checkUseStatement($stmt, $shortName);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        // Not found in imports, return as-is (might be FQCN or in same namespace)
        return $shortName;
    }

    private function checkUseStatement(Stmt $stmt, string $shortName): ?string
    {
        if (!$stmt instanceof Stmt\Use_) {
            return null;
        }

        foreach ($stmt->uses as $use) {
            $alias = $use->alias?->toString() ?? $use->name->getLast();
            if ($alias === $shortName) {
                return $use->name->toString();
            }
        }

        return null;
    }

    /**
     * Get completions for imported classes (from use statements).
     *
     * @param array<Stmt> $ast
     * @return list<array{label: string, kind: int, detail: string}>
     */
    private function getImportedClassCompletions(string $prefix, array $ast): array
    {
        $items = [];
        $imports = $this->getImports($ast);

        foreach ($imports as $shortName => $fqcn) {
            if ($prefix === '' || str_starts_with(strtolower($shortName), strtolower($prefix))) {
                $items[] = [
                    'label' => $shortName,
                    'kind' => self::KIND_CLASS,
                    'detail' => $fqcn,
                ];
            }
        }

        return $items;
    }

    /**
     * Extract all imports from use statements.
     *
     * @param array<Stmt> $ast
     * @return array<string, string> Short name => FQCN
     */
    private function getImports(array $ast): array
    {
        $imports = [];

        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                foreach ($stmt->stmts as $nsStmt) {
                    $this->extractImportsFromUse($nsStmt, $imports);
                }
            } else {
                $this->extractImportsFromUse($stmt, $imports);
            }
        }

        return $imports;
    }

    /**
     * @param array<string, string> $imports
     */
    private function extractImportsFromUse(Stmt $stmt, array &$imports): void
    {
        if ($stmt instanceof Stmt\Use_) {
            foreach ($stmt->uses as $use) {
                $shortName = $use->alias?->toString() ?? $use->name->getLast();
                $fqcn = $use->name->toString();
                $imports[$shortName] = $fqcn;
            }
        } elseif ($stmt instanceof Stmt\GroupUse) {
            $prefix = $stmt->prefix->toString();
            foreach ($stmt->uses as $use) {
                $shortName = $use->alias?->toString() ?? $use->name->getLast();
                $fqcn = $prefix . '\\' . $use->name->toString();
                $imports[$shortName] = $fqcn;
            }
        }
    }
}
