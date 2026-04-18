<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Completion\ContextDetector;
use Firehed\PhpLsp\Completion\TypeHintContext;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use Firehed\PhpLsp\Utility\ClassFinder;
use Firehed\PhpLsp\Utility\DocblockParser;
use Firehed\PhpLsp\Utility\ReflectionHelper;
use Firehed\PhpLsp\Utility\TypeFormatter;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ReflectionMethod;
use ReflectionProperty;

final class CompletionHandler implements HandlerInterface
{
    // LSP CompletionItemKind constants
    private const KIND_METHOD = 2;
    private const KIND_FUNCTION = 3;
    private const KIND_VARIABLE = 6;
    private const KIND_CLASS = 7;
    private const KIND_PROPERTY = 10;
    private const KIND_KEYWORD = 14;
    private const KIND_ENUM_MEMBER = 20;
    private const KIND_CONSTANT = 21;

    // Matches property type continuations: "private ?", "public int|", "protected Foo&"
    private const PROPERTY_TYPE_PATTERN = '/(?:public|private|protected)\s+(?:readonly\s+)?(?:\w+\s*)?[?|&]\s*(\w*)$/';

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ParserService $parser,
        private readonly SymbolIndex $symbolIndex,
        private readonly ?ComposerClassLocator $classLocator,
        private readonly ?TypeResolverInterface $typeResolver = null,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/completion';
    }

    /**
     * @return array{
     *   isIncomplete: bool,
     *   items: list<array{label: string, kind?: int, detail?: string, documentation?: string}>,
     * }|null
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

        $items = $this->getCompletionItems($textBeforeCursor, $ast, $document, $line);

        return [
            'isIncomplete' => false,
            'items' => $items,
        ];
    }

    /**
     * @param array<Stmt> $ast
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getCompletionItems(string $textBeforeCursor, array $ast, TextDocument $document, int $line): array
    {
        // $this-> completion
        if (preg_match('/\$this->(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            return $this->getThisMemberCompletions($prefix, $ast);
        }

        // $variable-> completion (typed variables, not $this)
        if (preg_match('/\$(\w+)->(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $variableName = $matches[1];
            $prefix = $matches[2];
            return $this->getTypedVariableMemberCompletions($variableName, $prefix, $ast, $line);
        }

        // Variable completion ($var)
        if (preg_match('/\$(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            return $this->getVariableCompletions($prefix, $ast, $line);
        }

        // self:: and static:: completion - resolve to enclosing class
        if (preg_match('/\b(?:self|static)::(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $classNode = $this->findClassAtLine($ast, $line);
            if ($classNode !== null) {
                $className = $classNode->namespacedName?->toString() ?? $classNode->name?->toString();
                if ($className === null) {
                    // Anonymous class - no completions available
                    return [];
                }
                $prefix = $matches[1];
                return $this->getStaticCompletions($className, $prefix, $ast, $document);
            }
            return [];
        }

        // parent:: completion - methods from parent class
        if (preg_match('/\bparent::(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            return $this->getParentCompletions($prefix, $ast, $line);
        }

        // ClassName:: completion (static) - also match single : for mid-typing
        if (preg_match('/([A-Z]\w*)::?(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $className = $matches[1];
            $prefix = $matches[2];
            return $this->getStaticCompletions($className, $prefix, $ast, $document);
        }

        // new ClassName completion - suggest imported classes and indexed instantiable types
        if (preg_match('/new\s+(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            $items = $this->getImportedClassCompletions($prefix, $ast);
            $indexedItems = $this->getIndexedClassCompletions($prefix, [SymbolKind::Class_, SymbolKind::Enum_]);
            $items = array_merge($items, $indexedItems);
            return $this->deduplicateCompletions($items);
        }

        // After visibility keyword - suggest function, static, readonly, const, or types
        // Must check before general type hint context since both patterns overlap
        if (preg_match('/(?:public|private|protected)\s+(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            $items = $this->getClassMemberKeywordCompletions($prefix);
            $items = array_merge($items, $this->getTypeHintCompletions($prefix, $ast, TypeHintContext::Property));
            return $this->deduplicateCompletions($items);
        }

        // Return type context - after ): with optional space
        if (preg_match('/\):\s*(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            return $this->getTypeHintCompletions($prefix, $ast, TypeHintContext::ReturnType);
        }

        // Property type context - nullable/union/intersection after visibility keyword
        if (preg_match(self::PROPERTY_TYPE_PATTERN, $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            return $this->getTypeHintCompletions($prefix, $ast, TypeHintContext::Property);
        }

        // Parameter type context - after ( or , in function signature
        // Matches: "(str", ", str", "?str", "|str", "&str" when in parameter position
        if (preg_match('/[(,?|&]\s*(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            return $this->getTypeHintCompletions($prefix, $ast, TypeHintContext::Parameter);
        }

        // Class body context - only class-level keywords, no functions
        if ($this->isInClassBody($textBeforeCursor)) {
            if (preg_match('/(?:^|[\s{;])(\w+)$/', $textBeforeCursor, $matches) === 1) {
                $prefix = $matches[1];
                return $this->getClassBodyKeywordCompletions($prefix);
            }
            return [];
        }

        // Function/class/keyword completion (at start of expression or after operators)
        if (preg_match('/(?:^|[(\s=,!&|])(\w+)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            $items = $this->getKeywordCompletions($prefix);
            $items = array_merge($items, $this->getFunctionCompletions($prefix, $ast));
            $items = array_merge($items, $this->getImportedClassCompletions($prefix, $ast));
            $items = array_merge($items, $this->getIndexedClassCompletions($prefix, [
                SymbolKind::Class_,
                SymbolKind::Interface_,
                SymbolKind::Trait_,
                SymbolKind::Enum_,
            ]));
            return $this->deduplicateCompletions($items);
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
     * Get completions for parent:: - methods from the parent class.
     *
     * @param array<Stmt> $ast
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getParentCompletions(string $prefix, array $ast, int $line): array
    {
        $classNode = $this->findClassAtLine($ast, $line);
        if ($classNode === null || $classNode->extends === null) {
            return [];
        }

        $parentClassName = $classNode->extends->toString();

        // Resolve the parent class name if it's in the same file
        $parentClassNode = ClassFinder::findWithLocator(
            $parentClassName,
            $ast,
            $this->classLocator,
            $this->parser,
        );

        $items = [];

        if ($parentClassNode !== null) {
            foreach ($parentClassNode->stmts as $stmt) {
                // Methods (public and protected, both static and non-static)
                if ($stmt instanceof Stmt\ClassMethod) {
                    if ($stmt->isPrivate()) {
                        continue;
                    }
                    $name = $stmt->name->toString();
                    if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                        $items[] = $this->formatMethodCompletion($stmt);
                    }
                }
            }
        }

        // Also check via reflection for inherited/built-in classes
        $items = array_merge($items, $this->getParentReflectionCompletions($parentClassName, $prefix, $items));

        return $items;
    }

    /**
     * Get parent class methods via reflection.
     *
     * @param list<array{label: string, kind?: int, detail?: string, documentation?: string}> $existingItems
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getParentReflectionCompletions(string $className, string $prefix, array $existingItems): array
    {
        $reflection = ReflectionHelper::getClass($className);
        if ($reflection === null) {
            return [];
        }

        $existingLabels = array_column($existingItems, 'label');
        $items = [];

        // Public and protected methods (both static and non-static)
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            $name = $method->getName();
            if (in_array($name, $existingLabels, true)) {
                continue;
            }
            if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                $items[] = $this->formatReflectionMethodCompletion($method);
            }
        }

        return $items;
    }

    /**
     * Get completions for a typed variable: $user-> where $user has a known type.
     *
     * @param array<Stmt> $ast
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getTypedVariableMemberCompletions(
        string $variableName,
        string $prefix,
        array $ast,
        int $line,
    ): array {
        if ($this->typeResolver === null) {
            return [];
        }

        // Find the enclosing scope
        $scope = $this->findEnclosingScope($ast, $line);
        if ($scope === null) {
            return [];
        }

        // Resolve the variable's type
        $className = $this->typeResolver->resolveVariableType($variableName, $scope, $line, $ast);
        if ($className === null) {
            return [];
        }

        return $this->getInstanceMemberCompletions($className, $prefix, $ast);
    }

    /**
     * Get instance (non-static) member completions for a class.
     *
     * @param array<Stmt> $ast
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getInstanceMemberCompletions(
        string $className,
        string $prefix,
        array $ast,
    ): array {
        $items = [];

        $classNode = ClassFinder::findWithLocator($className, $ast, $this->classLocator, $this->parser);

        if ($classNode !== null) {
            foreach ($classNode->stmts as $stmt) {
                // Public methods (non-static)
                if ($stmt instanceof Stmt\ClassMethod && !$stmt->isStatic() && $stmt->isPublic()) {
                    $name = $stmt->name->toString();
                    if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                        $items[] = $this->formatMethodCompletion($stmt);
                    }
                }

                // Public properties (non-static)
                if ($stmt instanceof Stmt\Property && !$stmt->isStatic() && $stmt->isPublic()) {
                    foreach ($stmt->props as $prop) {
                        $name = $prop->name->toString();
                        if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                            $items[] = $this->formatPropertyCompletion($stmt, $name);
                        }
                    }
                }
            }
        }

        // Add public members from reflection (for inherited/built-in classes)
        $items = array_merge($items, $this->getReflectionInstanceCompletions($className, $prefix, $items));

        return $items;
    }

    /**
     * Get public instance members via reflection.
     *
     * @param list<array{label: string, kind?: int, detail?: string, documentation?: string}> $existingItems
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getReflectionInstanceCompletions(string $className, string $prefix, array $existingItems): array
    {
        $reflection = ReflectionHelper::getClass($className);
        if ($reflection === null) {
            return [];
        }

        $existingLabels = array_column($existingItems, 'label');
        $items = [];

        // Public non-static methods
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
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

        // Public non-static properties
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $name = $prop->getName();
            if (in_array($name, $existingLabels, true)) {
                continue;
            }
            if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                $items[] = $this->formatReflectionPropertyCompletion($prop);
            }
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

        $classNode = ClassFinder::findWithLocator($resolvedClassName, $ast, $this->classLocator, $this->parser);

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

                // Enum cases
                if ($stmt instanceof Stmt\EnumCase) {
                    $name = $stmt->name->toString();
                    if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                        $items[] = $this->formatEnumCaseCompletion($stmt);
                    }
                }
            }

            // Add built-in enum methods
            if ($classNode instanceof Stmt\Enum_) {
                $items = array_merge($items, $this->getEnumBuiltinMethods($classNode, $prefix));
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
     * Find the class containing the given line.
     *
     * @param array<Stmt> $ast
     */
    private function findClassAtLine(array $ast, int $line): ?Stmt\Class_
    {
        $visitor = new class ($line) extends NodeVisitorAbstract {
            public ?Stmt\Class_ $found = null;

            public function __construct(private readonly int $line)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Class_) {
                    $startLine = $node->getStartLine();
                    $endLine = $node->getEndLine();

                    if (
                        $startLine !== -1 && $endLine !== -1
                        && $this->line >= $startLine - 1
                        && $this->line <= $endLine - 1
                    ) {
                        $this->found = $node;
                    }
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->found;
    }

    /**
     * @param list<array{label: string, kind?: int, detail?: string, documentation?: string}> $existingItems
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getInheritedMemberCompletions(string $className, string $prefix, array $existingItems): array
    {
        $reflection = ReflectionHelper::getClass($className);
        if ($reflection === null) {
            return [];
        }

        $existingLabels = array_column($existingItems, 'label');
        $items = [];

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
        $flags = ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED;
        foreach ($reflection->getProperties($flags) as $prop) {
            $name = $prop->getName();
            if (in_array($name, $existingLabels, true)) {
                continue;
            }
            if ($prefix === '' || str_starts_with(strtolower($name), strtolower($prefix))) {
                $items[] = $this->formatReflectionPropertyCompletion($prop);
            }
        }

        return $items;
    }

    /**
     * @param list<array{label: string, kind?: int, detail?: string, documentation?: string}> $existingItems
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function getReflectionStaticCompletions(string $className, string $prefix, array $existingItems): array
    {
        $reflection = ReflectionHelper::getClass($className);
        if ($reflection === null) {
            return [];
        }

        $existingLabels = array_column($existingItems, 'label');
        $items = [];

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
                $paramStr .= TypeFormatter::formatNode($param->type) . ' ';
            }
            $var = $param->var;
            if ($var instanceof Variable && is_string($var->name)) {
                $paramStr .= '$' . $var->name;
            }
            $params[] = $paramStr;
        }

        $detail = $method->name->toString() . '(' . implode(', ', $params) . ')';
        if ($method->returnType !== null) {
            $detail .= ': ' . TypeFormatter::formatNode($method->returnType);
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
        $type = $property->type !== null ? TypeFormatter::formatNode($property->type) : 'mixed';

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
     * @return array{label: string, kind: int, detail: string}
     */
    private function formatEnumCaseCompletion(Stmt\EnumCase $case): array
    {
        $name = $case->name->toString();
        $detail = 'case ' . $name;

        if ($case->expr instanceof Node\Scalar\Int_) {
            $detail .= ' = ' . $case->expr->value;
        } elseif ($case->expr instanceof Node\Scalar\String_) {
            $detail .= " = '" . $case->expr->value . "'";
        }

        return [
            'label' => $name,
            'kind' => self::KIND_ENUM_MEMBER,
            'detail' => $detail,
        ];
    }

    /**
     * @return list<array{label: string, kind: int, detail: string}>
     */
    private function getEnumBuiltinMethods(Stmt\Enum_ $enum, string $prefix): array
    {
        $items = [];

        // cases() is available on all enums
        if ($prefix === '' || str_starts_with('cases', strtolower($prefix))) {
            $items[] = [
                'label' => 'cases',
                'kind' => self::KIND_METHOD,
                'detail' => 'cases(): array',
            ];
        }

        // from() and tryFrom() are only available on backed enums
        if ($enum->scalarType !== null) {
            $scalarType = $enum->scalarType->toString();

            if ($prefix === '' || str_starts_with('from', strtolower($prefix))) {
                $items[] = [
                    'label' => 'from',
                    'kind' => self::KIND_METHOD,
                    'detail' => "from($scalarType \$value): static",
                ];
            }

            if ($prefix === '' || str_starts_with('tryfrom', strtolower($prefix))) {
                $items[] = [
                    'label' => 'tryFrom',
                    'kind' => self::KIND_METHOD,
                    'detail' => "tryFrom($scalarType \$value): ?static",
                ];
            }
        }

        return $items;
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
                $paramStr .= TypeFormatter::formatNode($param->type) . ' ';
            }
            $var = $param->var;
            if ($var instanceof Variable && is_string($var->name)) {
                $paramStr .= '$' . $var->name;
            }
            $params[] = $paramStr;
        }

        $detail = 'function ' . $func->name->toString() . '(' . implode(', ', $params) . ')';
        if ($func->returnType !== null) {
            $detail .= ': ' . TypeFormatter::formatNode($func->returnType);
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
                $paramStr .= TypeFormatter::formatReflection($type) . ' ';
            }
            $paramStr .= '$' . $param->getName();
            $params[] = $paramStr;
        }

        $detail = $method->getName() . '(' . implode(', ', $params) . ')';
        $returnType = $method->getReturnType();
        if ($returnType !== null) {
            $detail .= ': ' . TypeFormatter::formatReflection($returnType);
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
        $typeStr = $type !== null ? TypeFormatter::formatReflection($type) : 'mixed';

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

    /**
     * Get class completions from the workspace symbol index.
     *
     * @param list<SymbolKind> $kinds
     * @return list<array{label: string, kind: int, detail: string}>
     */
    private function getIndexedClassCompletions(string $prefix, array $kinds): array
    {
        $symbols = $this->symbolIndex->findByPrefix($prefix, $kinds);
        $items = [];

        foreach ($symbols as $symbol) {
            $items[] = [
                'label' => $symbol->name,
                'kind' => self::KIND_CLASS,
                'detail' => $symbol->fullyQualifiedName,
            ];
        }

        return $items;
    }

    /**
     * Remove duplicate completions, preferring items that appear earlier.
     *
     * @param list<array{label: string, kind?: int, detail?: string, documentation?: string}> $items
     * @return list<array{label: string, kind?: int, detail?: string, documentation?: string}>
     */
    private function deduplicateCompletions(array $items): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $key = $item['label'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Get completions for type hint positions.
     *
     * @param array<Stmt> $ast
     * @return list<array{label: string, kind?: int, detail?: string}>
     */
    private function getTypeHintCompletions(string $prefix, array $ast, TypeHintContext $context): array
    {
        $items = [];

        // Types valid in all contexts
        $commonTypes = [
            'string', 'int', 'float', 'bool', 'array', 'object',
            'mixed', 'null', 'callable', 'iterable', 'true', 'false',
        ];

        // Context-specific type validity:
        // | Type   | Property | Parameter | Return |
        // |--------|----------|-----------|--------|
        // | void   | No       | No        | Yes    |
        // | never  | No       | No        | Yes    |
        // | self   | No       | Yes       | Yes    |
        // | static | No       | No        | Yes    |
        // | parent | No       | Yes       | Yes    |
        $builtinTypes = match ($context) {
            TypeHintContext::Property => $commonTypes,
            TypeHintContext::Parameter => [...$commonTypes, 'self', 'parent'],
            TypeHintContext::ReturnType => [...$commonTypes, 'void', 'never', 'self', 'static', 'parent'],
        };

        foreach ($builtinTypes as $type) {
            if ($prefix === '' || str_starts_with($type, strtolower($prefix))) {
                $items[] = [
                    'label' => $type,
                    'kind' => self::KIND_KEYWORD,
                    'detail' => 'builtin type',
                ];
            }
        }

        // Imported classes
        $items = array_merge($items, $this->getImportedClassCompletions($prefix, $ast));

        // Indexed types (traits are not valid type hints)
        $items = array_merge($items, $this->getIndexedClassCompletions($prefix, [
            SymbolKind::Class_,
            SymbolKind::Interface_,
            SymbolKind::Enum_,
        ]));

        return $this->deduplicateCompletions($items);
    }

    /**
     * Get PHP keyword completions.
     *
     * @return list<array{label: string, kind: int}>
     */
    private function getKeywordCompletions(string $prefix): array
    {
        $keywords = [
            // Control flow
            'if', 'else', 'elseif', 'switch', 'case', 'default',
            'while', 'do', 'for', 'foreach', 'break', 'continue',
            'return', 'throw', 'try', 'catch', 'finally',
            // Declarations
            'function', 'class', 'interface', 'trait', 'enum', 'namespace', 'use',
            'extends', 'implements', 'const', 'public', 'protected', 'private',
            'static', 'final', 'abstract', 'readonly',
            // Operators and other
            'new', 'instanceof', 'clone', 'yield', 'match',
            'echo', 'print', 'include', 'include_once', 'require', 'require_once',
            'global', 'unset', 'isset', 'empty', 'list', 'fn',
        ];

        $items = [];
        $prefixLower = strtolower($prefix);

        foreach ($keywords as $keyword) {
            if ($prefix === '' || str_starts_with($keyword, $prefixLower)) {
                $items[] = [
                    'label' => $keyword,
                    'kind' => self::KIND_KEYWORD,
                ];
            }
        }

        return $items;
    }

    /**
     * Check if cursor is inside a class/interface/trait/enum body (but not inside a method).
     */
    private function isInClassBody(string $textBeforeCursor): bool
    {
        // Count braces to detect if we're inside a class body
        // This is a heuristic - look for class/interface/trait/enum followed by unbalanced {
        if (preg_match('/(?:class|interface|trait|enum)\s+\w+/', $textBeforeCursor) !== 1) {
            return false;
        }

        // Count brace depth after the class declaration
        $classPos = strrpos($textBeforeCursor, 'class ');
        $interfacePos = strrpos($textBeforeCursor, 'interface ');
        $traitPos = strrpos($textBeforeCursor, 'trait ');
        $enumPos = strrpos($textBeforeCursor, 'enum ');
        $lastClassPos = max(
            $classPos !== false ? $classPos : 0,
            $interfacePos !== false ? $interfacePos : 0,
            $traitPos !== false ? $traitPos : 0,
            $enumPos !== false ? $enumPos : 0,
        );

        $afterClass = substr($textBeforeCursor, $lastClassPos);
        $depth = 0;
        $inString = false;
        $stringChar = '';

        for ($i = 0; $i < strlen($afterClass); $i++) {
            $char = $afterClass[$i];

            if ($inString) {
                if ($char === $stringChar && ($i === 0 || $afterClass[$i - 1] !== '\\')) {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $stringChar = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }
        }

        // depth === 1 means we're directly inside the class body (not in a method)
        return $depth === 1;
    }

    /**
     * Get keywords valid at class body level.
     *
     * @return list<array{label: string, kind: int}>
     */
    private function getClassBodyKeywordCompletions(string $prefix): array
    {
        $keywords = [
            'public', 'private', 'protected',
            'static', 'final', 'abstract', 'readonly',
            'const', 'function', 'use',
        ];

        $items = [];
        $prefixLower = strtolower($prefix);

        foreach ($keywords as $keyword) {
            if ($prefix === '' || str_starts_with($keyword, $prefixLower)) {
                $items[] = [
                    'label' => $keyword,
                    'kind' => self::KIND_KEYWORD,
                ];
            }
        }

        return $items;
    }

    /**
     * Get keywords valid after a visibility modifier.
     *
     * @return list<array{label: string, kind: int}>
     */
    private function getClassMemberKeywordCompletions(string $prefix): array
    {
        $keywords = ['function', 'static', 'readonly', 'const'];

        $items = [];
        $prefixLower = strtolower($prefix);

        foreach ($keywords as $keyword) {
            if ($prefix === '' || str_starts_with($keyword, $prefixLower)) {
                $items[] = [
                    'label' => $keyword,
                    'kind' => self::KIND_KEYWORD,
                ];
            }
        }

        return $items;
    }

    /**
     * Get variable completions for the current scope.
     *
     * @param array<Stmt> $ast
     * @return list<array{label: string, kind: int, detail?: string}>
     */
    private function getVariableCompletions(string $prefix, array $ast, int $cursorLine): array
    {
        // Find the innermost function/method/closure containing the cursor
        $enclosingScope = $this->findEnclosingScope($ast, $cursorLine);
        if ($enclosingScope === null) {
            return [];
        }

        $inMethod = $enclosingScope instanceof Stmt\ClassMethod;
        $variables = $this->collectScopeVariables($enclosingScope);

        // Build completion items
        $items = [];
        $prefixLower = strtolower($prefix);

        // Add $this if we're in a method
        if ($inMethod && ($prefix === '' || str_starts_with('this', $prefixLower))) {
            $className = $this->typeResolver?->resolveVariableType('this', $enclosingScope, $cursorLine, $ast);
            $items[] = [
                'label' => '$this',
                'kind' => self::KIND_VARIABLE,
                'detail' => $className ?? 'self',
            ];
        }

        foreach ($variables as $name => $basicType) {
            if ($prefix === '' || str_starts_with(strtolower($name), $prefixLower)) {
                // Use type resolver if available, fall back to basic type
                $resolvedType = $this->typeResolver?->resolveVariableType($name, $enclosingScope, $cursorLine, $ast);
                $items[] = [
                    'label' => '$' . $name,
                    'kind' => self::KIND_VARIABLE,
                    'detail' => $resolvedType ?? $basicType,
                ];
            }
        }

        return $items;
    }

    /**
     * Find the innermost function/method/closure containing the given line.
     *
     * @param array<Stmt> $ast
     */
    private function findEnclosingScope(
        array $ast,
        int $cursorLine,
    ): Stmt\Function_|Stmt\ClassMethod|Node\Expr\Closure|Node\Expr\ArrowFunction|null {
        $found = null;

        $visitor = new class ($cursorLine, $found) extends NodeVisitorAbstract {
            /** @var Stmt\Function_|Stmt\ClassMethod|Node\Expr\Closure|Node\Expr\ArrowFunction|null */
            public $found = null;
            private int $cursorLine;

            /**
             * @param Stmt\Function_|Stmt\ClassMethod|Node\Expr\Closure|Node\Expr\ArrowFunction|null $found
             */
            public function __construct(int $cursorLine, &$found)
            {
                $this->cursorLine = $cursorLine;
                $this->found = &$found;
            }

            public function enterNode(Node $node): ?int
            {
                if (
                    $node instanceof Stmt\Function_
                    || $node instanceof Stmt\ClassMethod
                    || $node instanceof Node\Expr\Closure
                    || $node instanceof Node\Expr\ArrowFunction
                ) {
                    $startLine = $node->getStartLine();
                    $endLine = $node->getEndLine();

                    // Check if cursor is within this scope (1-based lines from parser)
                    if (
                        $startLine !== -1 && $endLine !== -1
                        && $this->cursorLine >= $startLine - 1
                        && $this->cursorLine <= $endLine - 1
                    ) {
                        // Keep the innermost (last found) scope
                        $this->found = $node;
                    }
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->found;
    }

    /**
     * Collect variables from a function/method/closure scope.
     *
     * @return array<string, string> Variable name => type
     */
    private function collectScopeVariables(
        Stmt\Function_|Stmt\ClassMethod|Node\Expr\Closure|Node\Expr\ArrowFunction $scope,
    ): array {
        $variables = [];

        // Collect parameters
        foreach ($scope->params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $variables[$param->var->name] = $this->formatParamType($param->type);
            }
        }

        // Collect use() variables from closures
        if ($scope instanceof Node\Expr\Closure) {
            foreach ($scope->uses as $use) {
                if (is_string($use->var->name)) {
                    $variables[$use->var->name] = 'mixed';
                }
            }
        }

        // Traverse the scope body to find assignments and foreach variables
        $stmts = $scope instanceof Node\Expr\ArrowFunction ? [] : ($scope->stmts ?? []);

        $collector = new class ($variables) extends NodeVisitorAbstract {
            /** @var array<string, string> */
            public array $variables;

            /**
             * @param array<string, string> $variables
             */
            public function __construct(array &$variables)
            {
                $this->variables = &$variables;
            }

            public function enterNode(Node $node): ?int
            {
                // Skip nested function scopes
                if (
                    $node instanceof Stmt\Function_
                    || $node instanceof Stmt\ClassMethod
                    || $node instanceof Node\Expr\Closure
                    || $node instanceof Node\Expr\ArrowFunction
                ) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                // Collect assignments
                if ($node instanceof Node\Expr\Assign) {
                    if ($node->var instanceof Variable && is_string($node->var->name)) {
                        if (!isset($this->variables[$node->var->name])) {
                            $this->variables[$node->var->name] = 'mixed';
                        }
                    }
                }

                // Collect foreach variables
                if ($node instanceof Stmt\Foreach_) {
                    if ($node->valueVar instanceof Variable && is_string($node->valueVar->name)) {
                        $this->variables[$node->valueVar->name] = 'mixed';
                    }
                    if ($node->keyVar instanceof Variable && is_string($node->keyVar->name)) {
                        $this->variables[$node->keyVar->name] = 'mixed';
                    }
                }

                // Collect catch variables
                if ($node instanceof Stmt\Catch_) {
                    if ($node->var !== null && is_string($node->var->name)) {
                        $this->variables[$node->var->name] = 'Exception';
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($stmts);

        return $collector->variables;
    }

    private function formatParamType(?Node $type): string
    {
        if ($type === null) {
            return 'mixed';
        }
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\Name) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?' . $this->formatParamType($type->type);
        }
        if ($type instanceof Node\UnionType) {
            $types = array_map(fn($t) => $this->formatParamType($t), $type->types);
            return implode('|', $types);
        }
        return 'mixed';
    }
}
